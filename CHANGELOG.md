## v3.3.0

* **[2026-09-14]** Single-user self-host snapshot model: the poller relays readings to `public/current.json.asc`, `public/status.json.asc`, and `public/b/<bucket>.json.asc`, and the dashboard enrolls its public key over `POST /api/keys`.
* **[2026-09-14]** Encrypt published snapshots only to the enrolled user public key. No server-key fallback and no plaintext buckets.

## v3.2.0

* **[2026-09-14 01:19:34 EEST]** Refetch catch-up buckets for interior graph holes.
* **[2026-09-13 22:42:30 EEST]** Added a setting for controlling Hypoglaucemic, Normal, and Hyperglaucemic ranges.
* **[2026-09-13 18:13:08 EEST]** Added mechanism to show when below certain threshold.
* **[2026-09-13 17:20:34 EEST]** Copy the visible chart window as CSV.
* **[2026-09-13 16:58:53 EEST]** Enroll the dashboard public key over POST /api/keys.
* **[2026-09-13 16:39:09 EEST]** Add Docker Compose self-host stack for a DigitalOcean droplet.
* **[2026-09-13 16:17:55 EEST]** Accept OpenPGP-encrypted CSV on dashboard import.

## v3.1.0

* **[2026-09-13 16:14:13 EEST]** Add an offline poller that writes importable dense CSV.
* **[2026-09-13 16:02:19 EEST]** Stop false backfill gaps and skipped one-minute samples.
* **[2026-09-13 15:59:00 EEST]** Resample the glucose chart by visible window like a stock tracker.
* **[2026-09-13 15:41:10 EEST]** Store dashboard history as a dense 1440-slot CSV.
* **[2026-09-13 14:50:56 EEST]** Put a PHP API in front of the poller and require HTTPS for LibreLinkUp login.
* **[2026-09-13 11:17:54 EEST]** Add loopback LibreLinkUp login so the dashboard can connect without storing the password.

## v3.0.0

* **[2026-09-13 02:12:18 EEST]** Properly catchup the webapp with missed data after prolongued outages.
* **[2026-09-12 15:51:00 EEST]** Version 3.0.0: GPG refactor.

## v2.1.0

* **[2026-09-11 16:23:18 EEST]** More fixes.
* **[2026-09-11 14:56:12 EEST]** Fixed the unlocking of the page.

## v2.0.0

* **[2026-09-11 13:47:28 EEST]** Complete v2 rewrite with PGP support.
* **[2026-09-11 10:57:29 EEST]** Initial GPG implementation.

## v1.0.0

* **[2026-09-07 23:51:17 EEST]** Added core docs.
* **[2026-09-04 15:51:16 EEST]** Majorly improved backfilling of missed sensor readings.
* **[2026-09-04 08:06:49 EEST]** fix: refresh backfilled glucose history
* **[2026-09-03 16:15:59 EEST]** Modded @ Thursday 3 September 2026 16:15:59 EEST.
* **[2026-09-02 22:43:40 EEST]** Style changes and more.
* **[2026-09-02 21:05:37 EEST]** Initial.

