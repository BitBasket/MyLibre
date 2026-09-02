# MyLibre Glucose Dashboard

A local-first Progressive Web App that shows FreeStyle Libre 2 glucose readings on a Linux laptop.

The official LibreLink EG Android app remains the Bluetooth receiver. This project never talks to the sensor over BLE. It only reads the unofficial LibreLinkUp HTTP API, stores measurements in SQLite, and writes a static JSON snapshot for a localhost dashboard.

```text
Libre 2
  → LibreLink EG
  → LibreLinkUp
  → RESTSpeaker
  → LibreLinkUpProvider
  → GlucoseReadingDTO
  → SQLite
  → public/*.json
  → Vanilla JavaScript PWA
```

## Architecture

Abbott-specific URLs, headers, region names, account IDs, and JSON field names stay inside `src/LibreLink/`. The rest of the app only sees `GlucoseReadingDTO` values from `phpexperts/simple-dto`.

`phpexperts/rest-speaker` is the only HTTP client used against Abbott. Login uses `RESTSpeaker` + `NoAuth`. Authenticated calls use `LibreLinkUpAuth`, a custom `RESTAuth` strategy that injects the Bearer token, SHA-256 `Account-Id`, product, and client version. RESTSpeaker is not passed into repositories or DTOs.

`phpexperts/simple-dto` is the contract for `GlucoseReadingDTO` and `LibreLinkUpSessionDTO`. Those objects are immutable. Session token refresh creates a new DTO rather than mutating the old one.

The poller is the only process that calls LibreLinkUp, about every 60 seconds. After each successful poll it stores the latest reading plus any graph history Abbott still has (`INSERT OR IGNORE`), then writes `public/current.json`, `public/history-{YYYYMMDD}.json` (UTC calendar days), and `public/status.json`. The browser fetches those files about every 5 seconds and computes age/stale flags from the reading timestamp. Any static file server (PHP's built-in server or `./run-nginx.sh`) is enough for the UI.

This is a display. It does not recommend insulin, invent missing points, or alter Abbott values.

## Arch Linux requirements

```bash
sudo pacman -S php php-sqlite composer
```

Needed PHP extensions: `json`, `pdo`, `pdo_sqlite`. PHP 8.4 or newer.

## Install

```bash
git clone <this-repo> MyLibre
cd MyLibre
composer install
cp .env.example .env
php bin/init-db.php
```

Credentials belong only in `.env`. That file is gitignored.

## Configuration

| Variable | Purpose |
| --- | --- |
| `GLUCOSE_PROVIDER` | `mock` (default) or `librelinkup` |
| `LIBRELINK_EMAIL` / `LIBRELINK_PASSWORD` | LibreLinkUp login. Never sent to the browser. |
| `LIBRELINK_REGION` | `AUTO` (preferred) or a region code such as `AE`, `EU`, `EU2` |
| `LIBRELINK_BASE_URI` | Optional full override, must be usable as a RESTSpeaker base URI |
| `LIBRELINK_PATIENT_ID` | Optional when more than one connection exists |
| `LIBRELINK_CLIENT_VERSION` | LibreLinkUp client version header. Bump if Abbott starts returning 403. |
| `HOST` | Bind address. Default `127.0.0.1`. LAN exposure requires an explicit change. |
| `SQLITE_PATH` | Default `data/glucose.sqlite` |
| `SESSION_PATH` | Default `data/libre-session.json` (token cache, gitignored, mode 0600) |
| `ABBOTT_POLL_SECONDS` | Upstream poll interval, default 60 |
| `BROWSER_POLL_SECONDS` | Dashboard poll interval, default 5 |

## LibreLink EG / Egypt

Do not assume the US host. Start at `https://api.libreview.io/` when `LIBRELINK_REGION=AUTO`. LibreLinkUp login can return `{ "redirect": true, "region": "ae" }` (region varies). The adapter then logs in again at `https://api-{region}.libreview.io/`. That is a JSON redirect, not an HTTP 3xx.

Egyptian LibreLink EG accounts are expected to land on whatever regional backend Abbott currently assigns. AUTO follows that assignment. If discovery is wrong, set `LIBRELINK_BASE_URI` for debugging.

## Mock provider

Leave `GLUCOSE_PROVIDER=mock` to develop the dashboard without hitting Abbott. The mock still returns real `GlucoseReadingDTO` instances. The poller writes them to SQLite and the dashboard JSON files. The PWA never calls Abbott.

## Run

Terminal 1:

```bash
php bin/poll-glucose.php
```

`php bin/poll-glucose.php --once` stores a single poll and exits.

Terminal 2:

```bash
php -S 127.0.0.1:8765 -t public
```

Or:

```bash
composer poll
composer serve
```

Or serve `public/` with nginx:

```bash
./run-nginx.sh
```

Open http://127.0.0.1:8765 (or the URL printed by `run-nginx.sh`).

In Chrome: Install page as app / Create shortcut → Open as window.

## Tests

```bash
composer test
```

Unit tests cover DTOs, trend mapping, SQLite deduplication, mock readings, and LibreLinkUp login/region/normalization against fixtures. They do not call live Abbott servers.

## systemd user service

```bash
mkdir -p ~/.config/systemd/user
cp systemd/libre-glucose.service ~/.config/systemd/user/
# edit WorkingDirectory and ExecStart if this clone is not /code/MyLibre
systemctl --user daemon-reload
systemctl --user enable --now libre-glucose.service
journalctl --user -u libre-glucose -f
```

No root service is required.

## Sessions

Reusable LibreLinkUp tokens are stored in `data/libre-session.json`. The password is never written there. On startup the poller loads the session, tries it, and replaces it with a new immutable `LibreLinkUpSessionDTO` if Abbott rejects it.

To force a fresh login:

```bash
rm -f data/libre-session.json
```

## Troubleshooting

**Stale dashboard:** the PWA is reading `public/current.json`. If age is over 3 minutes the UI warns; over 10 minutes it treats the feed as disconnected. Check that `php bin/poll-glucose.php` is running and that LibreLink EG still has an active sensor session. After the laptop wakes, the next poll backfills whatever LibreLinkUp `graphData` still contains (typically ~15-minute samples over a limited window, not every missed 1-minute point).

**Authentication failed:** confirm LibreLinkUp email/password in `.env`, accept any pending terms in the official app, and try `LIBRELINK_REGION=AUTO`. If Abbott starts requiring a newer client string, raise `LIBRELINK_CLIENT_VERSION`.

**Rate limited (HTTP 429):** the poller backs off. Do not lower `ABBOTT_POLL_SECONDS` below 60.

**Wrong or empty connection:** set `LIBRELINK_PATIENT_ID` to the patient id shown in LibreLinkUp.

Logs go to stderr and to the user journal when run under systemd. Passwords, complete tokens, Authorization headers, and credential cookies are not logged.

## Unresolved LibreLinkUp limits

The API is unofficial and can change without notice. Client `product`/`version` headers are currently required. `Account-Id` must be the SHA-256 hash of `data.user.id`, not the raw UUID and not the patient id. Graph `graphData` is typically ~15-minute history; `connection.glucoseMeasurement` is the latest uploaded value. This app does not implement LibreLinkUp two-factor or in-app terms acceptance; complete those in the official app.
