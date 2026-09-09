#!/usr/bin/env bash
# github-hook.sh — den Push-Webhook im GitHub-Repo anlegen oder aktualisieren
# (TASK-55). Laeuft lokal, braucht gh (angemeldet) und das Secret aus
# installieren.sh auf dem Server.
#
#   WEBHOOK_SECRET=... APP_URL=https://<domain> _bootstrap/webhook/github-hook.sh
#
# Repo ist das Remote "origin" des Checkouts. Nur das Push-Event, JSON, TLS
# geprueft. Gibt es schon einen Hook mit dieser URL, wird er aktualisiert
# (auch das Secret) — sonst angelegt. Zum Schluss ein Ping und die Antwort des
# Servers: 200 mit "Hook rules were not satisfied" ist richtig, ein Ping ist
# kein Push auf main.
set -euo pipefail
cd "$(dirname "$0")/../.."

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mFEHLER:\033[0m %s\n' "$*" >&2; exit 1; }

command -v gh >/dev/null || die "gh fehlt"
gh auth status >/dev/null 2>&1 || die "gh ist nicht angemeldet"
[ -n "${APP_URL:-}" ] || die "APP_URL setzen (steht in CLAUDE.local.md)"
if [ -z "${WEBHOOK_SECRET:-}" ]; then
  read -rsp 'Webhook-Secret (aus installieren.sh): ' WEBHOOK_SECRET; echo
fi
[ -n "$WEBHOOK_SECRET" ] || die "Secret ist leer"

REPO="$(gh repo view --json nameWithOwner --jq .nameWithOwner)"
URL="${APP_URL%/}/__deploy/kachink-deploy"

ID="$(gh api "repos/${REPO}/hooks" --jq ".[] | select(.config.url == \"${URL}\") | .id" | head -n1)"
if [ -n "$ID" ]; then
  gh api -X PATCH "repos/${REPO}/hooks/${ID}" \
    --input - >/dev/null <<JSON
{"active": true, "events": ["push"],
 "config": {"url": "${URL}", "content_type": "json", "insecure_ssl": "0", "secret": "${WEBHOOK_SECRET}"}}
JSON
  log "Webhook ${ID} in ${REPO} aktualisiert: ${URL}"
else
  ID="$(gh api -X POST "repos/${REPO}/hooks" --input - --jq .id <<JSON
{"name": "web", "active": true, "events": ["push"],
 "config": {"url": "${URL}", "content_type": "json", "insecure_ssl": "0", "secret": "${WEBHOOK_SECRET}"}}
JSON
)"
  log "Webhook ${ID} in ${REPO} angelegt: ${URL}"
fi

gh api -X POST "repos/${REPO}/hooks/${ID}/pings" >/dev/null
sleep 4
gh api "repos/${REPO}/hooks/${ID}/deliveries?per_page=1" \
  --jq '.[0] | "Letzte Zustellung: \(.event) -> HTTP \(.status_code) (\(.status))"'
