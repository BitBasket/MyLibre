#!/usr/bin/env bash
#
# Bring up MyLibre with Docker Compose (poller + Caddy).
#
# With no arguments this builds the poller image and starts the stack detached,
# then prints the URL from MYLIBRE_SITE. Any arguments are passed straight
# through to `docker compose`, so day-to-day operations are:
#
#   ./run-server.sh                 # build + start (docker compose up -d --build)
#   ./run-server.sh ps
#   ./run-server.sh logs -f poller
#   ./run-server.sh restart poller
#   ./run-server.sh down
#
# See SELF_HOST.md for .env and DNS/TLS details.

set -euo pipefail

cd "$(dirname "$0")"

die() {
    printf 'run-server.sh: %s\n' "$*" >&2
    exit 1
}

# Reads KEY from .env the way App\Support\Env::plainValue does: surrounding
# whitespace and one layer of matching quotes are stripped. Prints nothing when
# the key is unset.
env_value() {
    local value
    value="$(sed -n "s/^[[:space:]]*$1[[:space:]]*=//p" .env | tail -n 1)"
    value="${value%$'\r'}"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    case "$value" in
        \"*\") value="${value#\"}"; value="${value%\"}" ;;
        \'*\') value="${value#\'}"; value="${value%\'}" ;;
    esac
    printf '%s' "$value"
}

# Errors out (listing every offender) when any named .env key is unset or empty.
require_env() {
    local key value missing=0
    for key in "$@"; do
        value="$(env_value "$key")"
        if [ -z "$value" ]; then
            printf 'run-server.sh: %s is missing or empty in .env.\n' "$key" >&2
            missing=1
        fi
    done
    [ "$missing" -eq 0 ] \
        || die "set it in .env, then re-run (see SELF_HOST.md, section 2)."
}

# If a referenced PGP key file already exists on the host, it must be a real
# ASCII-armored key block. A missing file is fine: the entrypoint generates it.
check_pgp_key() {
    local value path
    value="$(env_value "$1")"
    [ -n "$value" ] || return 0
    case "$value" in
        /*) path="$value" ;;
        *) path="./$value" ;;
    esac
    if [ -f "$path" ] && ! grep -qF "$2" "$path"; then
        die "$1 points at $path, which is not an ASCII-armored PGP key block."
    fi
}

command -v docker >/dev/null 2>&1 || die "docker is required but was not found in PATH."
docker compose version >/dev/null 2>&1 \
    || die "the Docker Compose plugin is required ('docker compose version' failed)."

if [ ! -f .env ]; then
    cp .env.example .env
    chmod 600 .env
    printf 'Created .env from .env.example.\n'
    printf 'Edit .env (LIBRELINK_EMAIL, LIBRELINK_PASSWORD, PGP_PASSPHRASE, MYLIBRE_SITE) before exposing this host.\n'
fi

# Anything else is a docker compose subcommand (ps, logs, down, ...); run it
# directly so those work without a usable build context or vendor tree.
if [ "$#" -gt 0 ]; then
    exec docker compose "$@"
fi

# The containerized poller has no terminal, so its entrypoint cannot prompt for
# the PGP passphrase. Without PGP_PASSPHRASE, php bin/init-pgp.php exits and the
# poller crash-loops under `restart: unless-stopped`. Catch that (and a corrupt
# existing key) before starting the stack.
require_env PGP_PASSPHRASE
check_pgp_key PGP_PUBLIC_KEY_PATH 'BEGIN PGP PUBLIC KEY BLOCK'
check_pgp_key PGP_PRIVATE_KEY_PATH 'BEGIN PGP PRIVATE KEY BLOCK'

# The poller image is built from the sibling engine library (see Dockerfile).
[ -d ../mycgm-core ] \
    || die "sibling ../mycgm-core is missing; the poller image is built from it."

# Caddy serves the engine PWA through symlinks in ./public that point at
# ./vendor/bitbasket/mycgm-core/pwa (bin/link-pwa.php). That directory must
# exist on the host before the web container starts.
[ -d vendor/bitbasket/mycgm-core/pwa ] \
    || die "engine PWA is missing at vendor/bitbasket/mycgm-core/pwa; run 'composer install' here first (see README.md)."

docker compose up -d --build
docker compose ps

site="$(env_value MYLIBRE_SITE)"
case "$site" in
    "" | ":80") url="http://localhost/" ;;
    :*) url="http://localhost${site}/" ;;
    http://* | https://*) url="${site%/}/" ;;
    *) url="https://${site}/" ;;
esac

printf '\nMyLibre is up. URL: %s\n' "$url"
printf 'Logs: ./run-server.sh logs -f poller  (or -f web)\n'
printf 'Stop: ./run-server.sh down\n'
