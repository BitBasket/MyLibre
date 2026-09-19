# MyLibre architecture

MyLibre is a local-first glucose dashboard. A PHP 8.4 process polls LibreLinkUp (or a deterministic mock), publishes encrypted snapshots as static files, and serves a browser PWA that decrypts and renders those files locally. It does not communicate with a Libre sensor: Abbott's LibreLink phone app uploads sensor data to LibreLinkUp first.

```
Libre sensor → LibreLink app → LibreLinkUp → LibreLinkUpProvider
                                      → GlucosePoller → encrypted static snapshots
                                                        ↓
                         Caddy → PHP API → browser PWA
                                      ↘ loopback login intake
```

## Runtime components

`App\Support\App` (from `bitbasket/mycgm-core`) is the composition root. It loads `.env`, constructs logging and cryptography, selects `GLUCOSE_PROVIDER` (`librelinkup` or `mock`), and exposes the single shared `provider()`, `pollState()`, `bucketWriter()`, `recipientCrypto()`, and `authIntake()` instances. There is one `App\Poller\GlucosePoller`, built in `bin/poll-glucose.php`.

`bin/serve.php` supervises the public PHP HTTP server and a poller child process. The engine `Kernel` handles API requests and passes static assets through; the poll loop periodically fetches provider data and writes snapshots. `bin/poll-glucose.php` is the poller launcher and supports `--once`; `composer poll` invokes it. The provider contract emits `GlucoseReadingDTO` values, keeping Abbott field names inside the engine `src/LibreLink/`. The mock provider supplies a 24-hour five-minute sine wave for development and tests.

The browser application is the engine PWA in `vendor/bitbasket/mycgm-core/pwa/` (`bin/link-pwa.php` symlinks it into `public/`): `index.html`, `app.js`, `app.css`, `pgp.js`, OpenPGP and chart libraries, a web manifest, icons, and a service worker. It fetches status, current, and arithmetic history URLs, decrypts them with the browser-held private key, deduplicates by timestamp, and derives display age/stale state. There is no history query or manifest endpoint.

## HTTP and trust boundary

`bin/serve.php` (`composer start`) binds the PHP API on `127.0.0.1:8765` and serves one dashboard at the site root:

- `GET/POST /api/keys` reports enrollment and accepts only an ASCII-armored public key, stored at `data/keys/user-public.asc`.
- `GET /api/librelink/status` reports session state and `POST /api/librelink/login` accepts one-shot credentials.

There is no dashboard-creation endpoint and no per-deployment path prefix: unscoped `/api/keys` and `/api/librelink/*` are the product, and the dashboard is at `/`.

`Kernel` forwards LibreLink requests to `AuthIntakeServer`, a small listener bound only to `AUTH_LISTEN` on loopback. The password is used for the Abbott login and is never logged or persisted; only the encrypted session is stored. Credential POSTs require direct loopback with a loopback host, or a trusted private-address proxy supplying `X-Forwarded-Proto: https`. In Docker, the API port is exposed only to Caddy; Caddy publishes 80/443, reverse-proxies `/api/*` to `127.0.0.1:8765`, and serves `public/` statically.

Enrollment is first-write-wins: the same fingerprint is idempotent and a different key receives `409`; private keys are refused. The single user public key is the sole recipient for published snapshots, so no snapshot is encrypted to the server key or written as plaintext.

## Snapshot and bucket protocol

`BucketWriter` atomically writes ASCII-armored OpenPGP ciphertext beneath `public/`:

| File | Contents |
| --- | --- |
| `public/current.json.asc` | Latest reading, or an all-null reading before a successful poll |
| `public/status.json.asc` | `schemaVersion: 2`, provider, bucket size, reading bounds, browser poll interval, encryption and login state |
| `public/b/<bucket>.json.asc` | `schemaVersion: 1`, bucket epoch, and a `readings` array |

Stored readings contain `glucoseMgDl`, `trend`, `trendArrow`, and UTC `timestamp`. The default bucket size is 300 seconds. The browser computes bucket names from time and status bounds; a missing file is a 404. Files use a temporary sibling and rename, then web-readable ciphertext permissions.

Each poll fetches the provider graph, merges the current measurement, and sorts by timestamp. Unseen timestamps enter an in-memory pending set. Readings whose sensor-time bucket has closed are written to that sensor-time bucket when it is the poller’s former open bucket or no file exists; a late reading for an existing closed file remains pending and is written to the current clock bucket instead, where the browser deduplicates it by timestamp. The current clock bucket is rewritten on successful polls. `data/poll-state.json` records successfully finalised timestamps in a sorted rolling set (maximum 2,000) and the earliest emitted timestamp. It contains timestamps only. A restart can rewrite an open bucket; the provider's rolling graph is the recovery limit for data never published.

## OpenPGP and enrollment

The server keypair (`data/keys/public.asc` and `private.asc`) is generated by `bin/init-pgp.php`, protected by `PGP_PASSPHRASE`, and required to decrypt the encrypted LibreLink session cache. It is never the outbound recipient: published glucose is encrypted only to the enrolled user key.

The dashboard generates or imports a keypair in the browser, keeps the private key in browser storage and its download backup, and POSTs only the public key to `/api/keys`. The user public key is the sole recipient for published snapshots, so no snapshot is ever encrypted to the server key or written as plaintext. User-key snapshots are recipient-only and unsigned. The PHP process sees provider readings in memory before encryption; published payloads are encrypted at rest and decrypted only in the browser.

## Persistent state and migrations

The live path uses `data/keys/user-public.asc` (the enrolled recipient), the encrypted session cache `data/libre-session.json.asc`, poll state `data/poll-state.json` (timestamps only), and encrypted files under `public/`. Historical storage formats are supported only as migration sources: `bin/migrate-history.php` and `bin/migrate-sqlite.php` convert them into buckets and refresh exports, while `bin/upgrade-glucose-data-version.php` produces the older dense CSV import format. `bin/poll-glucose-offline.php` polls with `.env` credentials and merges readings into the dashboard-importable dense CSV (`data/mylibre.history.csv` by default) through `CsvHistoryStore`. Run bucket migrations with the poller stopped. `src/Database/` is not constructed by the live poller.

## Deployment and verification

For local use, `composer start`/ `composer serve` runs PHP's built-in server and the combined API/poller. `./run-server.sh` wraps `docker compose up -d --build` for the containerized stack, and `systemd/libre-glucose.service` provides a user-service option.

Docker Compose has a `poller` service based on `php:8.4-cli-bookworm` with GnuPG and Composer, and a Caddy `web` service. The poller mounts `./data` and `./public`, exposes port 8765 only on the Compose network, and has a health check that the public API is listening (so `/api/keys` is reachable before any snapshots exist). Caddy serves `public` read-only, handles HTTP/HTTPS and certificates, and proxies API requests. The entrypoint creates data directories and generates server keys before starting `bin/serve.php`.

LibreLinkUp authentication follows regional redirects, caches the bearer session encrypted, and retries one 401 once. Poll failures use backoff and leave the last successful snapshots available. Redacted UTC stderr logs report freshness and gaps; readings older than 180 seconds produce `SENSOR LOST`. PHPUnit tests under `tests/` run with `composer test`.
