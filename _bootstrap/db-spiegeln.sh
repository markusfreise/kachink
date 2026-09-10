#!/usr/bin/env bash
# db-spiegeln.sh — Produktions-Datenbank von Batcave in das lokale Docker-Postgres
# spiegeln, um mit echten Daten zu testen. Lokal ausfuehren, im Repo-Root.
#
#   _bootstrap/db-spiegeln.sh              # Dump holen, lokal einspielen, migrieren
#   _bootstrap/db-spiegeln.sh --nur-dump   # nur den Dump nach /tmp legen
#
# Braucht: ssh batcave (Tailscale), lokal `docker compose up -d pgsql`.
# Die lokale Datenbank `timetracker` wird komplett ersetzt. Prod wird nur gelesen.
set -euo pipefail
cd "$(dirname "$0")/.."

SSH_HOST="${SSH_HOST:-batcave}"
DATA_CT="${DATA_CT:-110}"
PROD_DB="${PROD_DB:-kachink}"
LOCAL_CT="${LOCAL_CT:-timetracker-pgsql}"
LOCAL_DB="${LOCAL_DB:-timetracker}"
LOCAL_USER="${LOCAL_USER:-timetracker}"
DUMP="${DUMP:-/tmp/kachink-prod-$(date +%Y%m%d-%H%M%S).sql.gz}"

log() { printf '==> %s\n' "$*"; }
die() { printf 'FEHLER: %s\n' "$*" >&2; exit 1; }

command -v docker >/dev/null || die "docker fehlt"
docker ps --format '{{.Names}}' | grep -qx "$LOCAL_CT" || die "Container ${LOCAL_CT} laeuft nicht (docker compose up -d pgsql)"

log "Dump von ${SSH_HOST} (LXC ${DATA_CT}, DB ${PROD_DB}) nach ${DUMP}"
ssh -o ConnectTimeout=10 "$SSH_HOST" \
  "pct exec ${DATA_CT} -- runuser -u postgres -- pg_dump --no-owner --no-privileges --clean --if-exists ${PROD_DB} | gzip" \
  > "$DUMP"
[ -s "$DUMP" ] || die "Dump ist leer"
log "Dump: $(du -h "$DUMP" | cut -f1)"

[ "${1:-}" = "--nur-dump" ] && exit 0

log "Lokale DB ${LOCAL_DB} leeren und Dump einspielen"
docker exec -i "$LOCAL_CT" psql -U "$LOCAL_USER" -d "$LOCAL_DB" -v ON_ERROR_STOP=1 -q \
  -c "drop schema public cascade; create schema public;"
gunzip -c "$DUMP" | docker exec -i "$LOCAL_CT" psql -U "$LOCAL_USER" -d "$LOCAL_DB" -q -v ON_ERROR_STOP=0 >/dev/null

log "Migrationen des Checkouts nachziehen"
( cd api && DB_HOST=127.0.0.1 DB_PORT=5433 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=sync \
  php artisan migrate --force --no-interaction )

log "Fertig. Start lokal:"
printf '  cd api && DB_HOST=127.0.0.1 DB_PORT=5433 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=sync php artisan serve --port=8091\n'
printf '  http://127.0.0.1:8091 (gebaute SPA aus api/public), Login mit dem Prod-Admin\n'
