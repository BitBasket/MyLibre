# Glucose data format

History is **mg/dL in a minute slot**. The time is the column index, not a stored timestamp. Trend, arrows, ISO strings, and stale flags are not part of history.

There are three places that shape appears:

| Place | What it is | Format |
| --- | --- | --- |
| Browser site data | Durable history on the device | One CSV in `localStorage` key `mylibre.h.csv` |
| `mylibre.history.csv` | Portable export / import | Same CSV as the device store |
| `public/b/<bucket>.json.asc` | Encrypted relay batch | JSON `{ schemaVersion, bucket, readings }` after decrypt |

The live dashboard value is a fourth, smaller object (`current.json.asc`). It is **not** history.

Canonical unit is mg/dL. Days are UTC. Dedup key is the UTC minute. A missed poll is an empty cell, never a fabricated value.

---

## 1. A reading

In memory the dashboard keeps:

```js
{ t: 1757769120000, v: 185 }   // t = UTC minute as epoch ms, v = integer mg/dL
```

Seconds are discarded on ingest (`floor(t / 60000) * 60000`). Two points in the same UTC minute collapse; the later value wins.

History does **not** store:

- Unix epochs or ISO-8601 strings (the slot *is* the time)
- `trend` / `trendArrow` (only the live current reading uses those)
- `ageSeconds`, `stale`, `staleLevel` (derived from the clock)

---

## 2. CSV: one UTC day per line, 1,440 slots

There are 1,440 minutes in a day. Each line is one UTC calendar day with **exactly 1,440 value cells** after the date:

```
YYYYMMDD,v0000,v0001,v0002,...,v1439
```

| Field | Meaning |
| --- | --- |
| `YYYYMMDD` | UTC date |
| `vN` | Integer mg/dL for UTC minute `N = hour*60 + minute`, or empty if no reading |

Example (first minutes of a day, with a hole at 00:01):

```
20260913,185,,184,186,...
```

Slot `0` is 00:00 UTC, slot `1` is 00:01, slot `1439` is 23:59. A poll that did not happen is a skipped cell (`,,`). You do not store the timestamp.

Rules:

- No header.
- Comma-separated. `#` comments and blank lines are ignored on read.
- Days are sorted, one line each.
- The day id is `toISOString().slice(0, 10)` with hyphens stripped.
- A full day of values is ~6 KB; a sparse day is smaller in digits but still 1,440 commas.

`localStorage` key: **`mylibre.h.csv`**. Other keys:

| Key | Value |
| --- | --- |
| `mylibre.h.csv` | The CSV above |
| `mylibre.current` | Latest reading for offline display (see §5) |
| `mylibre.bucket` / `mylibre.bucketSeconds` | Sync checkpoint, not glucose |

`localStorage.setItem` replaces the whole value, so a quiet dashboard poll does **not** write. A new minute rewrites the CSV; closed days are kept as a string prefix so only the last line is rebuilt.

The in-memory series is the live copy. `mylibre.h.csv` is the durable cache of that series.

---

## 3. Portable export (`mylibre.history.csv`)

Export downloads the same CSV as §2. That file is the portable backup.

---

## 4. Import

Import merges by UTC minute (later value wins) and rewrites `mylibre.h.csv`. The dashboard accepts the dense CSV in §2 as:

- plaintext (`.csv`)
- OpenPGP **symmetric** ciphertext (`gpg --symmetric`, password) — armored `.asc` or binary `.gpg`
- OpenPGP **public-key** ciphertext (`gpg --encrypt --recipient`, encrypted to the unlocked keypair)

The browser inspects the message packets (SKESK vs PKESK) to choose password decrypt or the unlocked private key. It does not convert JSON, sparse CSV, or the old `mylibre.history` localStorage key.

Convert the v2 JSON object store (the payload `bin/migrate-history.php` reads from `data/glucose.json.asc`, or a plaintext export of the same `{timestamp, glucoseMgDl, …}` objects) with:

```bash
php bin/upgrade-glucose-data-version.php -o mylibre.history.csv
php bin/upgrade-glucose-data-version.php path/to/history.json -o mylibre.history.csv
```

Then use the dashboard Import button. The file may be plaintext or OpenPGP-encrypted as above.

---

## 5. Live current reading (not history)

`public/current.json.asc` (and the tiny `mylibre.current` cache) is the dashboard card: last value, trend, timestamp. Age and stale flags are **not** stored; the browser derives them.

```json
{
  "glucoseMgDl": 185,
  "trend": "stable",
  "trendArrow": "→",
  "timestamp": "2026-09-13T11:52:10Z"
}
```

`timestamp` here is ISO-8601 Zulu (`Y-m-d\TH:i:s\Z`). Trend exists only on this object. It is rewritten when the current timestamp changes, not every dashboard poll.

---

## 6. Relay batches (`public/b/<bucket>.json.asc`)

The poller does not hold history. It writes encrypted 5-minute batches the browser fetches by arithmetic URL (`bucket = floor(epochSeconds / 300) * 300`). After decrypt, a **new** batch is:

```json
{
  "schemaVersion": 1,
  "bucket": 1757769000,
  "readings": [
    { "glucoseMgDl": 185, "timestamp": "2026-09-13T11:50:10Z" }
  ]
}
```

`ReadingPresenter::history()` emits only `glucoseMgDl` and `timestamp`. Batches written before that change may still include `trend` and `trendArrow`; the browser strips them on ingest.

The browser floors each point to a UTC minute, merges, and persists the dense CSV in §2. Relay files are a delivery format, not the canonical on-device store.

`status.json.asc` carries the schedule (`bucketSeconds`, `earliestReadingAt`, `latestReadingAt`), not glucose.

---

## 7. Why this shape

- The poller aims for one value per minute. The natural record is **slot = minute-of-day**, value = mg/dL. A miss is an empty key, not a missing timestamp.
- **Original JSON objects** (~89 bytes/reading, ~124 KB / ~31k tokens for a busy day) spent most of their size on keys and ISO strings.
- **Sparse `epoch,mgdl` pairs** still paid 10 digits of time on every point.
- **Dense 1,440 cells** for a full day is ~6 KB / ~1.4k tokens. An LLM (or anything else) reading 24 hours gets a numeric row; time is the index.

On load, sparse and JSON history are converted into this layout.
