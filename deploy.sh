#!/usr/bin/env bash
# deploy.sh — kachink auf dem Server aktualisieren (PRD §15).
#
# Im Checkout auf LXC 114 (web) ausfuehren. Ablauf: Vorbedingungen pruefen ->
# Stand taggen -> git pull --ff-only main -> composer install -> Migrationen ->
# Caches -> php-fpm neu laden -> Healthcheck. Bricht beim ersten Fehler ab;
# schlaegt der Healthcheck fehl, nennt das Skript den Rollback-Befehl.
#
# Die Frontend-Assets werden lokal gebaut und nach api/public committed
# (web: npm run build-only && cp -r dist/* ../api/public/), auf dem Server
# laeuft kein npm.
#
#   ./deploy.sh                 # normaler Deploy von origin/main
#   ./deploy.sh --no-pull       # aktuellen Checkout deployen (Rollback)
#
# Umgebung: KACHINK_FPM (Vorgabe php8.3-fpm), HEALTH_URL (Vorgabe APP_URL/api/health).
set -euo pipefail
cd "$(dirname "$0")"

FPM="${KACHINK_FPM:-php8.3-fpm}"
API_DIR="api"
APP_URL="$(grep -E '^APP_URL=' "${API_DIR}/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"
HEALTH_URL="${HEALTH_URL:-${APP_URL:-http://127.0.0.1}/api/health}"
PULL=1
[ "${1:-}" = "--no-pull" ] && PULL=0

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31mFEHLER:\033[0m %s\n' "$*" >&2; exit 1; }

# ─── 0. Vorbedingungen ────────────────────────────────────────────────
command -v git >/dev/null || die "git fehlt"
command -v curl >/dev/null || die "curl fehlt"
command -v php >/dev/null || die "php fehlt"
command -v composer >/dev/null || die "composer fehlt"
systemctl is-active --quiet "$FPM" || die "${FPM} laeuft nicht"
[ -f "${API_DIR}/.env" ] || die "${API_DIR}/.env fehlt — aus api/.env.example anlegen und fuellen"
grep -q '^APP_KEY=.\+' "${API_DIR}/.env" || die "APP_KEY in api/.env ist leer (php artisan key:generate)"
grep -q '^DB_PASSWORD=.\+' "${API_DIR}/.env" || die "DB_PASSWORD in api/.env ist leer"
[ -z "$(git status --porcelain --untracked-files=no)" ] || die "Checkout hat lokale Aenderungen — erst aufraeumen (git status)"

# ─── 1. Stand von vorher taggen (Rollback-Ziel) ───────────────────────
VORHER="$(git rev-parse --short HEAD)"
TAG="deploy-$(date +%Y%m%d-%H%M%S)"
git tag "$TAG" >/dev/null
log "Stand vor dem Deploy: ${VORHER} (Tag ${TAG})"

# ─── 2. Code holen ────────────────────────────────────────────────────
if [ "$PULL" = 1 ]; then
  [ "$(git rev-parse --abbrev-ref HEAD)" = "main" ] || die "Deploy nur von main (aktuell: $(git rev-parse --abbrev-ref HEAD))"
  git pull --ff-only origin main
  log "main ist auf $(git rev-parse --short HEAD)"
else
  log "Kein Pull: deploye $(git rev-parse --short HEAD) ($(git describe --tags --always))"
fi
[ -f "${API_DIR}/public/index.html" ] || die "Frontend-Build fehlt in api/public (lokal bauen und committen)"

# ─── 3. Anwendung aktualisieren ───────────────────────────────────────
cd "$API_DIR"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --quiet
php artisan migrate --force --no-interaction
php artisan optimize:clear --quiet
php artisan config:cache --quiet
php artisan route:cache --quiet
php artisan view:cache --quiet
# Schreibrechte fuer php-fpm (Logs, Cache, Sessions)
chown -R www-data:www-data storage bootstrap/cache
cd ..
# opcache haelt den alten Code fest, bis fpm neu laedt.
systemctl reload "$FPM"
log "Abhaengigkeiten und Migrationen aktuell, ${FPM} neu geladen"

# ─── 4. Healthcheck mit Wiederholung ──────────────────────────────────
log "Healthcheck ${HEALTH_URL}"
for versuch in 1 2 3 4 5 6; do
  if ANTWORT="$(curl -fsS --max-time 10 "$HEALTH_URL" 2>/dev/null)" && printf '%s' "$ANTWORT" | grep -q '"status":"ok"'; then
    log "Healthcheck bestanden: ${ANTWORT}"
    log "Deploy fertig: $(git rev-parse --short HEAD) (vorher ${VORHER}, Tag ${TAG})"
    exit 0
  fi
  sleep 5
done

printf '\033[1;31mHealthcheck fehlgeschlagen.\033[0m Letzte Antwort: %s\n' "${ANTWORT:-<keine>}" >&2
printf 'Rollback auf den Stand von vorher:\n  git checkout %s && ./deploy.sh --no-pull\n' "$TAG" >&2
printf 'Logs:\n  journalctl -u %s -n 100\n  tail -n 100 /var/log/nginx/error.log\n  tail -n 100 api/storage/logs/laravel.log\n' "$FPM" >&2
exit 1
