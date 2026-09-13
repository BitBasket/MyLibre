# Self-host MyLibre

This is the custody path: you run the poller and dashboard on a machine you control. LibreLink (or LibreLink EG) remains the Bluetooth receiver. MyLibre only calls the unofficial LibreLinkUp HTTP API, encrypts readings with your PGP key, and serves a static PWA.

The poller sees LibreLinkUp plaintext in RAM while it encrypts. Keys, the passphrase, and Abbott credentials stay on your host. They are never sent to the browser.

A 1 GB DigitalOcean Droplet (or any small Linux VPS with Docker) is enough. A laptop or Raspberry Pi works the same way.

## What you get

```text
Libre 2 → LibreLink / LibreLink EG → LibreLinkUp
  → poller container (PHP + GnuPG)
  → encrypted store in ./data
  → encrypted snapshots in ./public
  → Caddy on ports 80/443
  → browser PWA (decrypts locally)
```

Two containers:

| Service | Role |
| --- | --- |
| `poller` | Logs into LibreLinkUp about once a minute, stores history, writes `public/*.json.asc` |
| `web` | Caddy serves `public/` and, with a hostname, obtains a Let's Encrypt certificate |

Host directories that must persist:

- `./data` — PGP keys, encrypted glucose store, encrypted LibreLinkUp session
- `./public` — PWA assets plus encrypted dashboard snapshots
- `.env` — LibreLinkUp credentials and `PGP_PASSPHRASE` (gitignored)

## Requirements

- Docker Engine with the Compose plugin (`docker compose version`)
- Git
- A LibreLinkUp account that can see the sensor (accept any pending terms in the official app first)
- Outbound HTTPS to Abbott
- Inbound TCP **22**, **80**, and **443** if this host is public

PHP, Composer, and GnuPG are inside the image. You do not install them on the host.

## 1. Create the host

On DigitalOcean: create a Droplet from the Docker image, 1 GB RAM, in the region you want. Add your SSH key. In the Networking / Firewalls panel allow SSH, HTTP, and HTTPS.

SSH in as root (or a sudo user):

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
docker compose version
```

## 2. Clone and configure

```bash
git clone -b v2.x https://github.com/BitBasket/MyLibre.git
cd MyLibre
cp .env.example .env
chmod 600 .env
```

Edit `.env`. Required for a live sensor:

```env
APP_ENV=production
GLUCOSE_PROVIDER=librelinkup
LIBRELINK_EMAIL=you@example.com
LIBRELINK_PASSWORD=your-librelinkup-password
LIBRELINK_REGION=AUTO
PGP_PASSPHRASE=a-long-passphrase-you-will-keep
MYLIBRE_SITE=:80
```

Notes:

- `LIBRELINK_REGION=AUTO` follows Abbott's JSON region redirect (Egyptian LibreLink EG accounts often land on `ae`). Set `LIBRELINK_BASE_URI` only if discovery is wrong.
- `LIBRELINK_PATIENT_ID` is needed when the LibreLinkUp account has more than one connection.
- Do not set `ABBOTT_POLL_SECONDS` below 60. Abbott rate-limits.
- `MYLIBRE_SITE=:80` serves HTTP on the droplet IP. Change it after DNS (next section).
- Do not commit `.env`.

### Bring existing keys and history

If this is a new install, skip this. The poller generates a keypair on first start from `PGP_PASSPHRASE`.

If you already have v2 keys and encrypted files, copy them onto the host **before** the first `docker compose up`, using the same passphrase:

```text
data/keys/public.asc
data/keys/private.asc
data/glucose.json.asc
data/libre-session.json.asc   # optional; the poller can log in again
```

Keep a copy of `public.asc`, `private.asc`, and the passphrase off the droplet. Without all three, the dashboard cannot decrypt, and a new keypair cannot read the old store.

## 3. Start

```bash
docker compose up -d
docker compose ps
docker compose logs -f poller
```

The first poll can take a minute. You should see a successful fetch in the poller log, and these files appear:

```text
public/current.json.asc
public/status.json.asc
public/history-YYYYMMDD.json.asc
```

Open `http://<droplet-ip>/`. The PWA asks for the public key, private key, and passphrase. Use the files in `data/keys/`.

## 4. HTTPS

Point an A (and AAAA, if you have IPv6) record at the droplet. Wait until it resolves, then in `.env`:

```env
MYLIBRE_SITE=glucose.example.com
```

Reload Caddy so it can obtain a certificate:

```bash
docker compose up -d
```

Caddy listens on 80 and 443. Let's Encrypt HTTP-01 must reach port 80 on that hostname. If issuance fails, `docker compose logs web` shows the ACME error.

IP addresses do not get public certificates. Leave `MYLIBRE_SITE=:80` until you have a hostname.

## 5. Unlock and install the PWA

1. Open the site in Chrome (or another Chromium browser).
2. Paste or upload `data/keys/public.asc` and `data/keys/private.asc`.
3. Enter `PGP_PASSPHRASE`.
4. Optionally: Install page as app / Create shortcut → Open as window.

Keys stay in the browser. The server never receives the private key from the PWA.

## Operate

Logs:

```bash
docker compose logs -f poller
docker compose logs -f web
```

Restart after editing `.env`:

```bash
docker compose up -d
```

Update the app:

```bash
git pull
docker compose up -d --build
```

`./data` and `./public` snapshots are bind-mounted and survive rebuilds.

Stop:

```bash
docker compose down
```

That does not delete `./data`, `./public`, or the Caddy certificate volume. `docker compose down -v` would delete the certificate volume.

### Backup

Copy off the host, and treat them as secret:

```text
.env
data/keys/public.asc
data/keys/private.asc
data/glucose.json.asc
```

`public/history-*.json.asc` is a convenience export of the same encrypted history. The durable store is `data/glucose.json.asc`.

### Force a fresh LibreLinkUp login

```bash
rm -f data/libre-session.json.asc
docker compose restart poller
```

The password is never written to the session file.

## Troubleshooting

**Containers restart, poller log says keys or passphrase are wrong.** `PGP_PASSPHRASE` must unlock `data/keys/private.asc`. If you generated a new pair by starting without keys, you will not be able to read an older `glucose.json.asc`. Restore the original keys, or start a new store.

**Dashboard is stale or disconnected.** The PWA reads `public/current.json.asc`. Age over ~3 minutes warns; over ~10 minutes is treated as disconnected. Confirm `docker compose ps` shows `poller` running, the poller log is fetching, and LibreLink still has an active sensor session. The poller logs `SENSOR LOST` / `SENSOR RESTORED` and `READING GAP`. It never invents missing points. Abbott `graphData` is typically only about 15 minutes, so a longer outage cannot be backfilled.

**Authentication failed.** Check `LIBRELINK_EMAIL` / `LIBRELINK_PASSWORD`, accept pending terms in the official app, and keep `LIBRELINK_REGION=AUTO`. If Abbott starts returning 403, raise `LIBRELINK_CLIENT_VERSION`.

**HTTP 429.** The poller backs off. Do not poll faster than once a minute.

**Empty or wrong patient.** Set `LIBRELINK_PATIENT_ID` to the id shown in LibreLinkUp.

**Caddy certificate fails.** DNS must point at this host, ports 80 and 443 must be open, and `MYLIBRE_SITE` must be the hostname (not `:80` and not an IP).

**Nothing on port 80.** Another process may already bind 80. `ss -lptn 'sport = :80'` on the host; `docker compose logs web` for Caddy.

Passwords, complete tokens, Authorization headers, and credential cookies are not logged.

## Laptop / no Docker

For a local-only checkout without containers, see `README.md` (`composer install`, `php bin/init-pgp.php`, `composer poll`, `composer serve` or `./run-nginx.sh`). That path binds the dashboard to `127.0.0.1` by default.

## Limits

LibreLinkUp is unofficial and can change without notice. This app does not talk to the sensor over BLE, does not implement LibreLinkUp two-factor or in-app terms, and does not recommend insulin. Complete pairing, 2FA, and terms in the official app.
