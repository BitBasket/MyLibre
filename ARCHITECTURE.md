# Backend architecture: `bin/poll-glucose.php`

This document describes how the glucose poller actually works in the current tree. It is the backend half of the dashboard: a long-running PHP process that talks to LibreLinkUp (or a mock), then writes static encrypted files under `public/` for a file server to serve. The browser is a separate pass.

The poller is a **write-only relay**. It never reads a glucose value back from disk. Abbott field names never leave `src/LibreLink/`. The only HTTP it serves is the loopback `AUTH_LISTEN` intake (`/api/librelink/status` and `/api/librelink/login`) so the dashboard can hand it a LibreLinkUp login when no session token is cached.

```text
Libre 2  →  LibreLink EG (phone, BLE)
         →  LibreLinkUp (Abbott cloud)
         →  LibreLinkUpProvider
         →  GlucoseReadingDTO
         →  GlucosePoller
         →  public/current.json.asc
            public/status.json.asc
            public/b/<bucket>.json.asc
```

The official app remains the Bluetooth receiver. This process never talks to the sensor.

---

## 1. What the entrypoint does

`bin/poll-glucose.php` is a thin launcher:

1. Autoload Composer.
2. `App::boot()` — load `.env` into `Config`, construct a `Logger` that writes redacted lines to stderr.
3. Resolve three collaborators from `App`:
   - `provider()` — `MockGlucoseProvider` or `LibreLinkUpProvider`
   - `pollState()` — `PollStateStore` at `data/poll-state.json`
   - `bucketWriter()` — `BucketWriter` targeting `public/`, encrypting to the recipient PGP key
4. Construct `GlucosePoller` with `ABBOTT_POLL_SECONDS` (default 60) and `persistHistory: true`.
5. Bind `AUTH_LISTEN` (unless empty, `--once`, or the provider is not LibreLinkUp) and `$poller->run($once)` where `$once` is true if `--once` is on the argv.

`run()` writes a first `current`/`status` snapshot from whatever is already on disk (the latest reading is `null` until the first successful poll). If LibreLinkUp has no session and no env credentials, it sets `loginRequired` on `status` and waits on the intake socket instead of polling. Otherwise it loops: `poll()` → wait on the intake socket for `$delay` seconds → repeat. `--once` returns after a single `poll()` (or immediately when interactive login is required).

Equivalent: `composer poll`, or the systemd user unit `systemd/libre-glucose.service`.

---

## 2. Composition root (`App`)

`src/Support/App.php` is the only place that wires infrastructure.

| Factory | What it builds |
| --- | --- |
| `crypto()` | Server keypair (`PGP_PUBLIC_KEY_PATH` + `PGP_PRIVATE_KEY_PATH` + `PGP_PASSPHRASE`). Required to encrypt/decrypt the LibreLinkUp session cache. Validates the pair on first use. |
| `recipientCrypto()` | Key used for **outbound** files. If `PGP_USER_PUBLIC_KEY_PATH` is set, that public key alone (unsigned encrypt). Otherwise the server keypair (sign + encrypt). |
| `pollState()` | `PollStateStore($config->pollStatePath)` |
| `bucketWriter()` | `BucketWriter` writing into `publicPath` (default `public/`), using `recipientCrypto()`, `bucketSeconds` (default 300) |
| `provider()` | `mock` → `MockGlucoseProvider`. `librelinkup` → `LibreLinkUpProvider` with an encrypted `SessionStore`. |

Unknown `GLUCOSE_PROVIDER` values fail at boot. LibreLinkUp credentials are optional in `.env`: the dashboard connect form can post them to `AUTH_LISTEN`. Without a session file and without env credentials the poller waits for that form instead of calling Abbott.

The live poller does **not** construct `EncryptedGlucoseRepository`, `SQLiteGlucoseRepository`, or anything under `src/Database/`. Those exist for one-shot migrations and tests.

---

## 3. Internal contract

Everything downstream of the provider speaks `GlucoseReadingDTO` (`phpexperts/simple-dto`):

| Field | Meaning |
| --- | --- |
| `timestamp` | Sensor time as UTC `Carbon` (from Abbott `FactoryTimestamp`, else `Timestamp`) |
| `glucoseMgDl` | Integer mg/dL (`ValueInMgPerDl`, rounded) |
| `trend` | `rapidly falling` / `falling` / `stable` / `rising` / `rapidly rising`, or null |
| `trendArrow` | `↓` `↘` `→` `↗` `↑`, or null |
| `source` | `librelinkup` or `mock` |

`GlucoseProvider` is the only upstream port:

- `authenticate(): LibreLinkUpSessionDTO`
- `getCurrentReading(): GlucoseReadingDTO`
- `getHistory(): GlucoseReadingDTO[]`

The poller always asks for history (`persistHistory: true`). If that array is empty it falls back to `getCurrentReading()`. The `persistHistory: false` constructor flag exists for tests.

`ReadingPresenter` is the shared JSON shape for files the dashboard already knows how to decrypt. Stored records are `{glucoseMgDl, trend, trendArrow, timestamp}`. Age and stale flags are **not** written; the browser derives them from the clock.

The canonical CGM envelope in `docs/key-model-and-envelope.md` is a design target. The files on disk today are still the dashboard-shaped payload above.

---

## 4. One poll cycle

`GlucosePoller::poll()` is the unit of work. Happy path:

1. **Load `PollState`** from `data/poll-state.json`. `latest()` is the high-water timestamp used only for gap logging, not for dropping readings (a set is used so a late, out-of-order point is still emitted).
2. **Fetch** `provider->getHistory()` (current measurement plus every `graphData` point, sorted by timestamp).
3. **Collect unseen readings** into an in-memory map `$pending[epochSeconds] = DTO`. Skip timestamps already in `PollState`. Collapse duplicates within the open bucket by timestamp.
4. **Bucket the wall clock**, not the reading: `bucket = floor(now / bucketSeconds) * bucketSeconds` (default 300 s).
5. **If the clock left the open bucket**, finalise it:
   - `writeBatch(oldBucket, pending)`
   - `state = state.emitted(pending timestamps)` and save
   - clear `$pending`, set `$openBucket` to the new clock bucket
6. **Rewrite the open bucket** with whatever is still pending (`writeBatch` no-ops on an empty list).
7. **Log sensor freshness and gaps** (see §8).
8. **Export pointers**: `current.json.asc` from the newest fetched DTO; `status.json.asc` from `PollState` (`firstReadingAt`, `latest()`).
9. Return the next sleep: `ABBOTT_POLL_SECONDS` on success, or a backoff on failure.

`$pending` and `$openBucket` are process memory. They are not in `poll-state.json`. A restart forgets the open-bucket buffer and rebuilds it from the next graph response plus the persisted seen-set.

### 4.1 Open vs finalised buckets

| | Open bucket | Finalised bucket |
| --- | --- | --- |
| When | Wall clock still inside `bucket` | Clock has moved to a later bucket |
| File | Rewritten every successful poll | Not rewritten by a healthy poller |
| `PollState` | Timestamps **not** recorded yet | Timestamps appended to `seen` |
| Purpose | Dashboard can show the last few minutes without waiting 5 minutes | Durable history the browser can fetch by arithmetic URL |

`BucketWriter::writeBatch()` always overwrites the file. “Immutable” here means the poller will not emit those timestamps again once they are in `PollState`, so it has no reason to touch the old file. It never opens a bucket to merge.

Readings are assigned to **the clock’s open bucket at emit time**, not to `floor(sensorTimestamp / 300) * 300`. A point that arrives in the same poll that crosses a 5-minute boundary is flushed into the bucket being closed. Migration scripts (`bin/migrate-history.php`, `bin/migrate-sqlite.php`) are different: they group by the reading’s own timestamp.

### 4.2 Restart behaviour

- Mid-open-bucket restart: timestamps were not saved, graph still has them → they are written again into the current clock bucket (usually the same file).
- Restart after the clock has left the bucket, with timestamps never finalised: the same points are emitted into the **new** open bucket. The old file, if it was written at least once, remains. The browser is expected to dedupe by timestamp.
- Restart after finalise: `PollState` has the timestamps; they are skipped.

The poller will not reconstruct a missing finalised file. If a bucket file is deleted after its timestamps were recorded, those readings are gone from the relay’s point of view.

---

## 5. On-disk state

### 5.1 `data/poll-state.json` (plaintext)

The only persistent poller bookkeeping. Numbers only — no glucose values — so it is deliberately unencrypted.

```json
{
  "seen": [1757660400, 1757660460, "..."],
  "firstReadingAt": 1755000000
}
```

`PollState`:

- `seen` is a sorted unique list, newest last, capped at **2000** entries (`PollState::WINDOW`). At one reading per minute that is ~33 hours, which is longer than the graph window the provider returns, so live polling does not re-emit dropped timestamps.
- `firstReadingAt` is the earliest timestamp ever passed to `emitted()`. It only grows backward. It is what `status.json` exposes as `earliestReadingAt`.
- `hasSeen($ts)` is membership in the rolling set, not “less than high water”. A late reading whose timestamp is older than `latest()` is still emitted if it is not in the set.
- Writes are temp-file + `rename`, mode `0600`.

Until the first bucket is finalised, `seen` stays empty even though `current.json.asc` and the open bucket already contain readings. `status.latestReadingAt` therefore lags the live value until that first close.

### 5.2 Files under `public/` (ciphertext)

All written by `BucketWriter`. Atomic: temp file next to the destination, `rename`, then `chmod` (`0644` when encrypted so nginx can serve them).

| Path | Role | Payload |
| --- | --- | --- |
| `current.json.asc` | Latest reading, display-only. Not the history store. | `ReadingPresenter::stored` — four fields, or all-null on startup / failed export |
| `status.json.asc` | Schedule shape for the browser. Not a file listing. | `ok`, `encrypted`, `schemaVersion` **2**, `provider`, `bucketSeconds`, `earliestReadingAt`, `latestReadingAt` (ISO-8601 Zulu), `browserPollSeconds`, `loginRequired` |
| `b/<bucket>.json.asc` | History batch. `bucket` is the Unix epoch of the window start. | `{ schemaVersion: 1, bucket, readings: [...] }` |

A missing `b/<bucket>.json.asc` is a 404. There is no manifest, no index, no acknowledgement endpoint. The browser is supposed to compute `bucket` from the clock (and from `earliestReadingAt` / `latestReadingAt` / `bucketSeconds` in `status`).

Filenames are `.json.asc` (ASCII-armored OpenPGP), not `.json.enc`.

### 5.3 `data/libre-session.json.asc`

LibreLinkUp bearer token, regional `baseUri`, hashed-account source UUID, expiry, patient id. Encrypted to the **server** keypair (signed). Mode `0600`. The password is never stored. Plaintext session files are refused.

### 5.4 Not used by the live poller

| Path | Status |
| --- | --- |
| `data/glucose.json.asc` | Previous v2 encrypted history store. Read only by `bin/migrate-history.php`. |
| `data/glucose.sqlite` | v1 store. Read only by `bin/migrate-sqlite.php`. |
| `public/history-YYYYMMDD.json.asc` | Previous day-file snapshots. The poller no longer writes or reads them. |
| `SQLITE_PATH` / `DATA_PATH` | Config still loads them; `GlucosePoller` does not. |

---

## 6. Encryption

`PgpCrypto` shells out to `gpg` in a throwaway homedir (import key → operate → kill agent → delete homedir).

Two key roles:

1. **Server keypair** (`data/keys/public.asc` + `private.asc`, passphrase in `PGP_PASSPHRASE`). Generated/unlocked by `php bin/init-pgp.php`. Used for the session cache, and for outbound files when no user public key is configured.
2. **User public key** (`PGP_USER_PUBLIC_KEY_PATH`). Optional. When set, outbound `current` / `status` / `b/*` are encrypted to that recipient only. The matching private key is expected to live in the browser, not on this host.

Consequences:

- Public-key-only outbound messages are **unsigned**. Integrity is the OpenPGP message MDC (tamper → decrypt failure). The poller cannot sign with a private key it does not have.
- Server-keypair outbound messages are **signed and encrypted** (`--local-user` + `--recipient` the same fingerprint).
- Bulk migration (`encryptFiles` / `writeBatches`) is always recipient-only: GnuPG cannot combine `--sign` with `--multifile`. Plaintext is staged in a temp workspace **outside** `public/` so an interrupted run does not leave readable JSON in the web root.
- Even with a user public key, LibreLinkUp mode still needs the server private key, because `SessionStore` decrypts with `App::crypto()`.

The poller process sees each LibreLinkUp response in memory as plaintext `GlucoseReadingDTO`s before encrypting. The honest claim is “ciphertext at rest on the published files,” not zero-knowledge.

---

## 7. LibreLinkUp adapter

`src/LibreLink/` is the isolation boundary. Abbott URLs, headers, region names, account IDs, and JSON field names stay here. HTTP is `phpexperts/rest-speaker` only.

### 7.1 Session

1. Load `SessionStore`. If a cached `LibreLinkUpSessionDTO` exists and is not within one minute of expiry, reuse it.
2. Otherwise `POST llu/auth/login` with email/password via `RESTSpeaker` + `NoAuth`.
3. Login may return `{ redirect: true, region: "ae" }` (JSON redirect, not HTTP 3xx). Follow at most two hops to `https://api-{region}.libreview.io/`.
4. Persist token, `baseUri`, raw account UUID, expiry, optional `LIBRELINK_PATIENT_ID`.
5. Authenticated calls use `LibreLinkUpAuth`: `Authorization: Bearer …`, `Account-Id: sha256(user.id)` (hash of the **account** UUID, not the patient id), plus `product: llu.android` and `version` from `LIBRELINK_CLIENT_VERSION`.

HTTP 401 on a data call clears the cache and logs in once more. A second 401 becomes `LibreLinkAuthException`.

### 7.2 Graph

`GET llu/connections/{patientId}/graph`.

- If `patientId` is empty, `GET llu/connections` and pick the configured id, or the first connection.
- Current value: `data.connection.glucoseMeasurement`.
- History: every element of `data.graphData`, plus the current measurement, sorted by timestamp.
- Trend: Abbott `TrendArrow` 1–5 → `TrendNormalizer`.
- Timestamps parsed as UTC.

The window is whatever Abbott returns for that account (unofficial API; observed as a rolling graph, on the order of hours). Points that have rolled off the graph are gone unless they were already written to a bucket. The poller never invents intermediate values.

`LIBRELINK_REGION=AUTO` (default) starts at `https://api.libreview.io/` and follows the login redirect. `LIBRELINK_BASE_URI` overrides the start URL.

### 7.3 Mock

`GLUCOSE_PROVIDER=mock` never calls Abbott. It returns a 24-hour sine wave of `GlucoseReadingDTO`s at 5-minute steps so the rest of the pipeline (buckets, PGP, dashboard files) can be exercised.

---

## 8. Logging, freshness, gaps, backoff

`Logger` writes `[UTC] level: message` to stderr and redacts Bearer tokens / password / authorization values.

| Event | When |
| --- | --- |
| `Stored glucose reading N mg/dL` | Latest fetched timestamp is ≤ 180 s old |
| `Collected N new glucose readings` | At least one unseen timestamp entered `$pending` |
| `SENSOR LOST` | Successful response whose latest timestamp is > 180 s old |
| `SENSOR RESTORED` | A later poll in the **same process** is fresh again after a loss |
| `READING GAP` | Latest timestamp jumped more than 180 s past `PollState.latest()` |
| `BACKFILL INCOMPLETE` | Gap intermediates supplied by `graphData` are fewer than `floor((gap-1) / pollInterval)` |

Expected-intermediate math uses the configured poll interval (60 s), not Abbott’s native one-minute cadence. A sleep gap whose points have already left the graph is reported incomplete and left as a hole. Nothing is fabricated.

Failures change only the next `sleep()`:

| Exception | Next delay |
| --- | --- |
| `LibreLinkAuthException` | max(30, 2× current), cap 900 s |
| `LibreLinkRateLimitException` (HTTP 429) | max(`retryAfterSeconds` default 300, same doubling), cap 900 s |
| `LibreLinkNetworkException` | max(15, 2× current), cap 300 s |
| `LibreLinkResponseException` | stay at `ABBOTT_POLL_SECONDS` (no doubling) |
| Any other `Throwable` | max(30, 2× current), cap 300 s |

A failed poll does not advance `PollState` and does not rewrite buckets. Snapshot export errors are logged and swallowed so a dashboard write failure does not abort the poll loop.

Do not lower `ABBOTT_POLL_SECONDS` below 60: Abbott rate-limits.

---

## 9. Static serving

The poller is not a web server. Anything that can serve `public/` is enough (`php -S`, `./run-nginx.sh`, `composer serve`).

`public/router.php` (PHP built-in server) serves real files as-is. For the well-known snapshot URLs (`/current.json`, `/status.json`, `/b/<digits>.json`, and the `.asc` forms) a missing file is a JSON 404 rather than the built-in HTML 404. The poller does not consult this router.

---

## 10. One-shot writers that share the same output

These are not the poller. They write the same `public/b/*.json.asc` + `PollState` layout so the live process can continue without re-emitting old timestamps. Run them with the poller **stopped**.

| Command | Source | Grouping |
| --- | --- | --- |
| `php bin/migrate-history.php` | Decrypt `DATA_PATH` (`EncryptedGlucoseRepository`) | Sensor-timestamp bucket |
| `php bin/migrate-sqlite.php [path]` | Snapshot v1 SQLite via `VACUUM INTO`, read `SQLiteGlucoseRepository` | Sensor-timestamp bucket |

Both call `BucketWriter::writeBatches()` (one GnuPG `--multifile` pass) and `PollState::emitted()` on every imported timestamp. They do not modify the source. `seen` is still capped at 2000; that is enough for the live graph window after import.

---

## 11. What this process guarantees, and what it does not

**Does:**

- Poll LibreLinkUp (or the mock) on a timer.
- Normalize to `GlucoseReadingDTO` / mg/dL.
- Emit each sensor timestamp at most once per rolling `PollState` window.
- Publish encrypted `current`, `status`, and 5-minute batches a static file server can host.
- Log sensor loss and graph backfill holes without inventing points.
- Back off on auth, 429, and network errors.

**Does not:**

- Talk to the Libre 2 over BLE.
- Recommend insulin or alter Abbott values.
- Read history back, merge into an old bucket, or list `public/b/`.
- Implement LibreLinkUp 2FA or in-app terms; those happen in the official app.
- Keep a private copy of glucose history once a bucket is written (the session cache is the only encrypted server-side secret besides keys).
- Serve the dashboard or decrypt outbound files for the browser.

---

## 12. Map of the poller path

```text
bin/poll-glucose.php
  App::boot
    Config::fromEnv          .env
    Logger                   stderr, redacted
  GlucosePoller
    GlucoseProvider
      LibreLinkUpProvider    RESTSpeaker, LibreLinkUpAuth, SessionStore
      MockGlucoseProvider
    PollStateStore           data/poll-state.json
    BucketWriter
      ReadingPresenter       JSON shape
      PgpCrypto              gpg, recipient key
        → public/current.json.asc
        → public/status.json.asc
        → public/b/<epoch>.json.asc
```

Frontend analysis (how the PWA discovers buckets, decrypts, dedups, and draws) is a separate document.
