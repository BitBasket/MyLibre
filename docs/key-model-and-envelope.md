# Key model and canonical CGM envelope

Design proposal, 2026-09-12.

This document locks the two decisions that everything else in the publication design depends on:

1. **Who holds which key, and what the encryption guarantees.**
2. **The vendor-neutral CGM envelope** the worker emits and the browser commits.

It is written against the constraints in `PUBLISH-PLAN.md` and the current PHP prototype (GPG snapshots written by the poller, decrypted with OpenPGP.js in the browser). It does not propose new cryptography; it pins the trust boundaries and the wire format. Implementation follows only after this is agreed.

---

## 1. Key and encryption model

### 1.1 What the plan requires

> Generate the user's encryption keypair on the user's device. Send only the public encryption key to the polling environment. Keep the private key on the user's devices, encrypted locally with a user password or protected through suitable platform key storage.

Supporting requirements:

- An explicit user-controlled recovery-key option; without it, loss of the password/private key must honestly mean permanent loss.
- The public key must be pinned/authenticated so a compromised service cannot silently substitute a different key for future payloads.
- The honest claim for a cloud poller: *"We do not retain readable glucose history. Relay and backup objects are encrypted to keys controlled by the user."* Never "zero-knowledge," because the worker still sees each LibreLinkUp response in memory before encrypting it.

### 1.2 Current state (what "GPG works" actually does today)

| Piece | Location | Behaviour |
| --- | --- | --- |
| Key generation | `bin/init-pgp.php` → `src/Security/PgpKeyGenerator.php` | Generates an Ed25519/Curve25519 keypair **on the server** into `data/keys/public.asc` and `data/keys/private.asc`. |
| Passphrase | `.env` (`PGP_PASSPHRASE`), written by `init-pgp.php` | Unlocks the private key; stored in plaintext in `.env`. |
| Encrypt + sign | `src/Security/PgpCrypto.php::encrypt()` | `--local-user $fingerprint --sign` **and** `--recipient $fingerprint`. Signs with the **private** key and encrypts to the same user key. |
| Snapshot write | `src/Export/DashboardSnapshot.php` | Encrypts each snapshot (`current.json.asc`, `history-YYYYMMDD.json.asc`, `status.json.asc`) into `public/`, overwriting in place. |
| Decrypt | `public/pgp.js` (OpenPGP.js) | Stores the **same** keypair in IndexedDB (`mylibre-pgp`), decrypts and verifies in-page. |

The private key and its passphrase therefore live on the same host that logs into LibreLinkUp, and `init-pgp.php` explicitly instructs the user to copy `private.asc` into the browser. This satisfies "ciphertext at rest, plaintext only in the browser," but **not** the plan's key-ownership model.

### 1.3 Required change, and its one hard consequence

Generation moves to the device. The worker receives the public key only and never the private key or passphrase.

The hard consequence: **the worker can no longer sign with the user's key.** Today authenticity comes from sign+encrypt with the private key that lives on the poller. Once the worker holds only the public key, that is impossible. Integrity must instead come from the encryption scheme's own authentication (OpenPGP MDC, or an AEAD tag such as ChaCha20-Poly1305 / AES-GCM): tampering yields a **decryption failure**, not a forged-but-valid reading.

If non-repudiation of worker output is ever required, add a separate **MyLibre signing key** (its own keypair, pinned on the device). It is not needed for integrity, and it must **not** be the user's key.

### 1.4 The crypto primitive is swappable — do not block on it

The architecture needs exactly three operations, and both families provide all three:

- **OpenPGP/GPG** (status quo, standards-based, works today). Costs: a large OpenPGP.js bundle to ship and parse on the decrypting page, and a `gpg` subprocess on the worker (Rust has no first-class GPG).
- **age-style / libsodium sealed box** (X25519 + ChaCha20-Poly1305, RFC 7748 / RFC 8439). Native WebCrypto where available (with `libsodium.js` as a fallback) and small, audited Rust libraries (`libsodium`, `age`, RustCrypto). **Not hand-rolled** — standard primitives in existing libraries.

Decision: **keep GPG for the local prototype** (it works and is standard) and hide it behind a small interface so the primitive can change at the worker boundary without touching the envelope or the browser logic. The public-key-only requirement is satisfiable by OpenPGP in **unsigned (encrypt-to-recipient-only) mode**, so fixing the key model does **not** force an immediate switch.

Interface (names illustrative):

```text
worker side:  encryptToPublicKey(publicKey, plaintext) -> ciphertext
device side:  generateKeypair(passphrase) -> {publicKey, wrappedPrivateKey}
              decrypt(ciphertext, privateKey) -> plaintext   (fails on tamper)
              fingerprint(key) -> string
```

There is deliberately **no** `signWithUserPrivateKey` anywhere in this interface.

### 1.5 Key pinning (substitution resistance)

The worker must not be able to swap the recipient key silently. On enrollment the device records the fingerprint it registered; every batch is checked against that pinned key, and the browser **refuses** any payload not encrypted to the pinned key. (OpenPGP: verify the message's recipient key ID/primary key. libsodium/age: bind the recipient to the AEAD through the AAD or an explicit recipient field.) The fingerprint is established out-of-band of the ciphertext it protects.

### 1.6 Recovery and loss

- **The unlock secret is a passphrase**, chosen by the user, and it wraps the private key (GPG s2k today; Argon2id + an AEAD wrapper if libsodium is adopted).
- The passphrase is meant to be saved in a **password manager** — a plain, copyable text secret, so it syncs to the user's desktop and phone and can be re-typed anywhere.
- **Recovery material is the downloaded private-key file plus the passphrase.** There is no server-side escrow and no separate recovery key: the user's copy of the downloaded `private.asc` *is* the recovery path.
- Without the downloaded private key **and** the passphrase, loss is **permanent**. The UI must say so plainly and must not imply recoverability that does not exist.

### 1.7 Key lifecycle (decided)

One user keypair, generated in the frontend (or imported by the user if they already have one):

1. **Generate** the keypair in the browser, encrypted with the user's passphrase.
2. **Mandatory download.** The frontend must let the user download **both** the public and the private key files (`.asc`). This step is required, not optional: the downloaded files are the user's durable copy and the only recovery material.
3. **Store a working copy in IndexedDB** for convenience, so the user is not re-importing on every visit. IndexedDB is browser-managed site data and is wiped if site data is cleared — the downloaded files are what survive that.
4. **Enroll the public key only** with the worker. The private key and passphrase are never transmitted.
5. **Import the same keypair onto additional devices** (phone, second laptop) via the downloaded files plus the passphrase.

**One keypair, shared across the user's devices.** The worker holds exactly one public key per user, and each enrolled device pins the same fingerprint (§1.5). This is simpler than per-device keys, but it means revoking a single device is not possible — losing a device requires re-keying and re-downloading the pair. Per-device keypairs remain a possible future upgrade if revocation becomes a requirement.

---

## 2. Canonical CGM envelope (v1)

The unit the worker emits and the browser commits. It carries enough to deduplicate, to preserve provenance, and to be extended to Dexcom and other adapters without erasing provider-specific identity.

### 2.1 Batch wrapper

```json
{
  "schema": "mylibre.cgm.batch",
  "schemaVersion": 1,
  "adapter": { "name": "librelinkup", "schemaVersion": 1 },
  "producedAt": "2026-09-12T09:48:00Z",
  "producedBy": { "kind": "worker", "version": "0.1.0" },
  "readings": [ /* CgmReading[] */ ]
}
```

### 2.2 Reading

```json
{
  "id": "librelinkup:ae:<accountHash>:1762943220",
  "source": {
    "provider": "librelinkup",
    "region": "ae",
    "accountId": "sha256:<hex>",
    "patientId": "<opaque>",
    "deviceId": "<opaque|null>"
  },
  "sensorTimestamp": "2026-09-12T09:47:00Z",
  "receivedTimestamp": "2026-09-12T09:48:02Z",
  "value": { "amount": 174, "unit": "mg/dL" },
  "originalValue": null,
  "trend": { "name": "falling", "arrow": "\u2198", "rate": null },
  "quality": { "stale": false, "gapBefore": false, "origin": "graphData" },
  "raw": { }
}
```

Rules:

- **`id` is the deduplication identity** and must be deterministic. Prefer the provider's own stable reading id when it exists; otherwise `provider:region:accountHash:sensorEpochSeconds`. Two polls that overlap must produce the same `id` for the same reading.
- **Never fabricate.** Missing points are represented by `gapBefore` / absence, never by synthesized values. This preserves the plan's safety constraint.
- **Preserve both timestamps.** `sensorTimestamp` is when the sensor measured; `receivedTimestamp` is when the worker observed it. Keep the source UTC offset/timezone when the adapter exposes it, for international travel.
- **Canonical unit is `mg/dL`** (decided — see §6). Both the backend and the front end work in mg/dL. LibreLinkUp already returns mg/dL (`ValueInMgPerDl`, `src/LibreLinkUpProvider.php:289`), and mg/dL has ~18× the resolution of mmol/L (1 mmol/L = 18.016 mg/dL), so a later mmol/L display loses nothing. **Unit conversion is a display/i18n concern only** — a mmol/L option is deferred to a future i18n pass and must not change the stored format. `originalValue` is recorded only when an adapter observes a source unit other than mg/dL.
- **`raw` is optional and adapter-schema-versioned**, so provenance survives normalization without leaking provider field names into the canonical fields. `adapter.schemaVersion` in the wrapper versions the mapping.
- **Provenance is preserved**: `provider`, `region`, hashed `accountId` (never the raw UUID), and `patientId`/`deviceId` remain available for correct multi-source handling.

---

## 3. Batch container, sync, expiry

The dashboard is pure static HTML + JS + CSS with **no request-time backend**, so discovery must not depend on a listing endpoint or manifest: **the batch URLs are derived arithmetically** and a missing one simply 404s.

- **Object naming:** `b/<bucket>.json.asc`, where `bucket = floor(epochSeconds / bucketSeconds) * bucketSeconds` (default `bucketSeconds = 300`). Fixed width, sortable, and computable by the browser from the clock alone.
- **`status.json.asc` carries the range**, not a list: `bucketSeconds`, `earliestReadingAt`, `latestReadingAt`. The browser derives `[firstBucket, lastFinal]` from those numbers. It leaks only the shape of the schedule, which a fixed polling interval already implies.
- **The open bucket is written each poll** and **finalised when the clock leaves it** (at which point its timestamps are recorded in `PollState` so they are never re-emitted). A bucket file is therefore mutable only while open and never loses a reading.
- **Client-side sync (no ack endpoint).** The browser fetches the derived buckets, decrypts, dedups by timestamp, merges, and persists the result plus a `consumedBucket` checkpoint locally. It **re-fetches the most recent finalised bucket** (one bucket of slack) so a flush that lands just after the boundary is still picked up.
- **Cold start is bounded.** With no listing, a fresh browser can only discover buckets by probing, so the initial backfill is capped (currently the most recent 8640 buckets ≈ 30 days at 5 min). Warm loads are incremental and uncapped. This is the one real cost of "no manifest"; a static index file would remove it if the trade-off is later judged worthwhile.
- **Expiry.** `poller`-pruned by age (30 days) on the real relay — a lifecycle rule or deletion job, never a request path.
- **Deletion is a testable guarantee.** On the funded relay, a test must show that a pruned bucket is unreachable in the primary store and leaves no readable copy in logs, dead-letter queues, access logs, or retries. (Versioning is only acceptable insofar as it does not defeat this; it is not a product feature.)

---

## 4. Migration from the current PHP prototype

- Keep the tested `LibreLinkUpProvider` behaviour as the adapter reference; port to Rust later without discarding the verified protocol work.
- `DashboardSnapshot` was replaced by `BucketWriter`, which emits immutable `b/<bucket>.json.asc` batches. The batch payload currently carries dashboard-shaped readings (`{timestamp, glucoseMgDl, trend, trendArrow}`); moving that payload onto the canonical envelope of §2 is still pending.
- `ReadingPresenter` is still shared by the writer and the browser. When the canonical envelope lands it splits into **envelope** (provider-neutral) and **presentation** (browser-side, dashboard-shaped).
- The single-file `current.json.asc` is display-only and must **not** become the durable history path; durability comes from the append-only batches.
- **Key generation is now on the device.** `public/pgp.js` exposes `PgpVault.generateKeypair()`; the unlock screen generates the keypair in the browser, requires a download of both `public.asc` and `private.asc`, and keeps a working copy in IndexedDB (§1.7). `bin/init-pgp.php` still generates a *server* keypair for the local prototype.
- **Two key roles are now separate at the server.** The poller keeps a **server keypair** for its own on-disk stores (`data/glucose.json.asc`, the session cache), while outbound snapshots are encrypted to the **user public key** via `PGP_USER_PUBLIC_KEY_PATH`. That key is **mandatory**: there is no fallback to the server keypair and no plaintext mode, so a deployment without an enrolled key fails fast instead of publishing glucose under a key the host itself can read.
- Because the server may hold only the user's public key, it can no longer sign with the user's key. Snapshot integrity rests on the message's own integrity protection; the browser verifies a signature only when one is present, and unsigned snapshots still fail loudly on tampering. The server's own at-rest decryption stays **strict** — it requires a valid signature by default, because the server's stores are always signed by the server keypair, and its public key is derivable from published ciphertext.

---

## 5. Open questions

1. Sign worker output with a separate MyLibre key, or rely purely on the encryption's integrity tag?
2. Batch composition: whole overlapping graph per poll, or only newly observed readings?
3. **Enrollment transport (self-host, done):** `POST /api/keys` over HTTPS (or loopback) writes `data/keys/user-public.asc`. First fingerprint wins; a different key is a 409. The private key is refused. A managed relay still needs a stronger binding than "whoever hits this URL first."
4. Which expiry mechanism per provider: object-storage lifecycle rule vs. deletion worker?

---

## 6. Decisions log

- **2026-09-12 — Crypto primitive: keep GPG/OpenPGP.** Stay on the working, standards-based OpenPGP path and hide it behind the small `Crypto` interface (§1.4) so a primitives swap (libsodium/age) remains possible at the worker boundary without touching the envelope or browser logic. The public-key-only requirement is met with unsigned (encrypt-to-recipient-only) mode.
- **2026-09-12 — Canonical unit: `mg/dL`.** Store and serve mg/dL on the backend and the front end. mmol/L is a future i18n display conversion only (§2.2), never a change to the stored format.
- **2026-09-12 — Keypair scope: one user keypair, imported onto each device.** The worker holds one public key per user; each device pins the same fingerprint. No per-device revocation (§1.7); per-device keys remain a possible future upgrade.
- **2026-09-12 — Unlock secret: a passphrase**, not a WebAuthn passkey (a WebAuthn credential is not a copyable text secret and cannot be moved across devices by a password manager). The passphrase is saved in the user's password manager.
- **2026-09-12 — Key storage: frontend-generated, download mandatory.** Both the public and private keys must be downloadable by the user (`.asc`); IndexedDB holds a convenience copy; only the public key enrolls with the worker. The downloaded private key plus the passphrase are the only recovery material (§1.6, §1.7).
- **2026-09-12 — Static, backend-free sync: predetermined bucket URLs.** History is immutable `b/<bucket>.json.enc` batches whose names the browser derives from the clock; `status.json.asc` carries only `bucketSeconds`/`earliestReadingAt`/`latestReadingAt`. No manifest, no acknowledgement endpoint, no request-time backend. The one cost is that a cold-start backfill is capped (§3).
- **2026-09-12 — The poller is a write-only relay.** Its only persistent state is `PollState` — a bounded set of emitted timestamps plus the earliest reading time. It never reads glucose values back and needs no private key for history.
- **2026-09-12 — Follow-up (not blocking): per-region display-unit defaults.** A verified geography→unit table (mg/dL vs mmol/L) is needed only for the future i18n default. Tracked separately; do **not** bake a geography table into the canonical format.
- **2026-09-13 — Self-host enrollment: POST /api/keys.** The dashboard uploads the public key only; the poller encrypts outbound snapshots to `data/keys/user-public.asc` once that file exists. The private key is never stored on the host. HTTPS (or loopback) is required so a network attacker cannot enroll a substitute key.
