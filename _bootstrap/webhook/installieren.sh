#!/usr/bin/env bash
# installieren.sh — Auto-Deploy per GitHub-Webhook auf dem Web-Container
# einrichten (TASK-55).
#
# Auf LXC 114 als root ausfuehren, aus dem Checkout oder von anderswo —
# das Skript nimmt seine Dateien aus dem eigenen Verzeichnis und den
# Checkout aus KACHINK_DIR (Vorgabe /var/www/kachink):
#
#   cd /var/www/kachink && _bootstrap/webhook/installieren.sh
#   KACHINK_DIR=/var/www/kachink /root/webhook/installieren.sh
#
# Voraussetzung: der Webhook-Daemon (adnanh/webhook) laeuft dort schon fuer
# die anderen Sites, mit einer gemeinsamen Hook-Datei, und die nginx-Site von
# kachink reicht /__deploy/ an ihn durch (Beispiel in
# docs/beispiele/nginx-ohne-docker.conf). Beides legt dieses Skript nicht an.
#
# Es tut, idempotent:
#   1. kachink-deploy und kachink-deploy-trigger nach /usr/local/bin
#   2. den Hook "kachink-deploy" in die gemeinsame Hook-Datei einfuegen oder
#      ersetzen — mit einem Secret, das entweder schon dort steht, per
#      WEBHOOK_SECRET hereinkommt oder neu erzeugt wird
#   3. den Daemon neu starten und die Strecke nginx -> Daemon -> Hook pruefen
#
# Das Secret steht danach nur in der Hook-Datei (0600) und muss wortgleich in
# den GitHub-Webhook: dafuer gibt es _bootstrap/webhook/github-hook.sh, das
# lokal laeuft. Mit --neues-secret wird ein vorhandenes Secret ersetzt
# (dann auch bei GitHub erneuern).
set -euo pipefail
HIER="$(cd "$(dirname "$0")" && pwd)"
APP_DIR="${KACHINK_DIR:-/var/www/kachink}"

HOOKS="${WEBHOOK_HOOKS:-/etc/webhook/hooks.json}"
UNIT="${WEBHOOK_UNIT:-vicky-webhook}"
HOOK_ID="kachink-deploy"
NEUES_SECRET=0
[ "${1:-}" = "--neues-secret" ] && NEUES_SECRET=1

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWARNUNG:\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31mFEHLER:\033[0m %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "als root ausfuehren"
command -v python3 >/dev/null || die "python3 fehlt"
command -v webhook >/dev/null || die "webhook (adnanh/webhook) fehlt — apt-get install webhook"
[ -f "${APP_DIR}/deploy.sh" ] || die "kein kachink-Checkout in ${APP_DIR} (KACHINK_DIR setzen)"
[ -f "${APP_DIR}/api/.env" ] || die "${APP_DIR}/api/.env fehlt — ohne sie deployt deploy.sh nicht"

# ─── 1. Skripte ───────────────────────────────────────────────────────
install -m 0755 "${HIER}/kachink-deploy" /usr/local/bin/kachink-deploy
install -m 0755 "${HIER}/kachink-deploy-trigger" /usr/local/bin/kachink-deploy-trigger
touch /var/log/kachink-deploy.log
log "Skripte installiert: /usr/local/bin/kachink-deploy, kachink-deploy-trigger"

# ─── 2. Hook in die gemeinsame Datei ──────────────────────────────────
VORHANDEN=""
if [ -f "$HOOKS" ]; then
  VORHANDEN="$(python3 - "$HOOKS" "$HOOK_ID" <<'PY'
import json, sys
hooks = json.load(open(sys.argv[1]))
for h in hooks:
    if h.get("id") == sys.argv[2]:
        for m in h.get("trigger-rule", {}).get("and", []):
            s = m.get("match", {}).get("secret")
            if s and s != "__WEBHOOK_SECRET__":
                print(s)
PY
)"
fi

if [ -n "${WEBHOOK_SECRET:-}" ]; then
  SECRET="$WEBHOOK_SECRET"; HERKUNFT="aus WEBHOOK_SECRET"
elif [ -n "$VORHANDEN" ] && [ "$NEUES_SECRET" = 0 ]; then
  SECRET="$VORHANDEN"; HERKUNFT="unveraendert aus ${HOOKS}"
else
  SECRET="$(openssl rand -hex 32)"; HERKUNFT="neu erzeugt"
fi

mkdir -p "$(dirname "$HOOKS")"
python3 - "$HOOKS" "$HOOK_ID" "$SECRET" "$APP_DIR" "${HIER}/hook-kachink.json" <<'PY'
import json, os, sys
pfad, hook_id, secret, app_dir, vorlage = sys.argv[1:6]
neu = json.load(open(vorlage))
neu["command-working-directory"] = app_dir
for m in neu["trigger-rule"]["and"]:
    if "secret" in m["match"]:
        m["match"]["secret"] = secret
hooks = json.load(open(pfad)) if os.path.exists(pfad) else []
if not isinstance(hooks, list):
    sys.exit("Hook-Datei ist keine Liste: " + pfad)
hooks = [h for h in hooks if h.get("id") != hook_id] + [neu]
tmp = pfad + ".neu"
with open(tmp, "w") as f:
    json.dump(hooks, f, indent=2, ensure_ascii=False)
    f.write("\n")
os.chmod(tmp, 0o600)
os.replace(tmp, pfad)
PY
chmod 600 "$HOOKS"
log "Hook ${HOOK_ID} steht in ${HOOKS} (Secret ${HERKUNFT})"

# ─── 3. Daemon neu starten und Strecke pruefen ────────────────────────
if systemctl list-unit-files "${UNIT}.service" --no-legend 2>/dev/null | grep -q .; then
  systemctl restart "$UNIT"
  sleep 1
  systemctl is-active --quiet "$UNIT" || die "${UNIT} laeuft nach dem Neustart nicht: journalctl -u ${UNIT} -n 30"
  log "${UNIT} neu gestartet"
else
  warn "systemd-Unit ${UNIT} gibt es nicht — Daemon von Hand neu starten (WEBHOOK_UNIT=<name> setzen)"
fi

APP_URL="$(grep -E '^APP_URL=' "${APP_DIR}/api/.env" | cut -d= -f2- | tr -d '"' || true)"
HOST="$(printf '%s' "$APP_URL" | sed -E 's#^[a-z]+://##; s#[/:].*$##')"
if [ -n "$HOST" ]; then
  ANTWORT="$(curl -s --max-time 10 -X POST -H "Host: ${HOST}" -H 'Content-Type: application/json' \
    -d '{"ref":"refs/heads/develop"}' "http://127.0.0.1/__deploy/${HOOK_ID}" || true)"
  case "$ANTWORT" in
    *"rules were not satisfied"*) log "Strecke nginx -> Daemon -> Hook antwortet (unsignierte Probe wird abgewiesen)";;
    *"Hook not found"*) die "Daemon kennt den Hook nicht — Hook-Datei oder Unit pruefen: ${HOOKS}, ${UNIT}";;
    *) warn "Unerwartete Antwort der Probe: '${ANTWORT:-<keine>}' — reicht die nginx-Site /__deploy/ durch? (docs/beispiele/nginx-ohne-docker.conf)";;
  esac
else
  warn "APP_URL fehlt in .env — Strecke nicht geprueft"
fi

cat <<HINWEIS

Fertig auf dem Server. Der GitHub-Webhook braucht dasselbe Secret; lokal:

  WEBHOOK_SECRET=${SECRET} \\
  APP_URL=${APP_URL:-https://<domain>} _bootstrap/webhook/github-hook.sh

Beim naechsten Deploy liegen diese Dateien auch im Checkout unter
${APP_DIR}/_bootstrap/webhook/ — ein erneuter Lauf von dort aendert nichts.

Danach loest jeder Push auf main einen Deploy aus:
  tail -f /var/log/kachink-deploy.log

HINWEIS
