#!/usr/bin/env bash
# db-anlegen.sh — Datenbank und App-Rolle fuer kachink auf der zentralen
# PostgreSQL (Batcave, LXC 110 data) anlegen. Idempotent.
#
#   # im Datencontainer, Peer-Anmeldung als postgres:
#   APP_PASSWORT=... runuser -u postgres -- ./db-anlegen.sh
#
# Variablen: DB_NAME (Vorgabe kachink), APP_USER (Vorgabe kachink_rw),
# APP_PASSWORT (nur beim Anlegen der Rolle noetig; sonst wird eines gewuerfelt
# und einmal ausgegeben), MCP_USER (Vorgabe mcp_ro, nur wenn vorhanden).
set -euo pipefail

DB_NAME="${DB_NAME:-kachink}"
APP_USER="${APP_USER:-kachink_rw}"
MCP_USER="${MCP_USER:-mcp_ro}"

log() { printf '==> %s\n' "$*"; }
die() { printf 'FEHLER: %s\n' "$*" >&2; exit 1; }
q() { psql -v ON_ERROR_STOP=1 -qAt "$@"; }

command -v psql >/dev/null || die "psql fehlt"
[ "$(q -d postgres -c 'select usesuper from pg_user where usename = current_user')" = "t" ] || die "kein Superuser"

NEUES_PASSWORT=""
if [ "$(q -d postgres -c "select count(*) from pg_roles where rolname = '${APP_USER}'")" = "0" ]; then
  NEUES_PASSWORT="${APP_PASSWORT:-$(openssl rand -base64 27 | tr -d '/+=' | head -c 32)}"
  q -d postgres -c "create role ${APP_USER} login password '${NEUES_PASSWORT}'" >/dev/null
  log "Rolle ${APP_USER} angelegt"
else
  log "Rolle ${APP_USER} existiert — Passwort bleibt unveraendert"
fi

if [ "$(q -d postgres -c "select count(*) from pg_database where datname = '${DB_NAME}'")" = "0" ]; then
  q -d postgres -c "create database ${DB_NAME} owner ${APP_USER} template template0 encoding 'UTF8' lc_collate 'C.UTF-8' lc_ctype 'C.UTF-8'" >/dev/null
  log "Datenbank ${DB_NAME} angelegt (Owner ${APP_USER})"
else
  log "Datenbank ${DB_NAME} existiert"
fi

q -d "$DB_NAME" <<SQL >/dev/null
GRANT CONNECT ON DATABASE ${DB_NAME} TO ${APP_USER};
GRANT ALL ON SCHEMA public TO ${APP_USER};
ALTER SCHEMA public OWNER TO ${APP_USER};
SQL
log "Rechte fuer ${APP_USER} gesetzt"

if [ "$(q -d postgres -c "select count(*) from pg_roles where rolname = '${MCP_USER}'")" = "1" ]; then
  q -d "$DB_NAME" <<SQL >/dev/null
GRANT CONNECT ON DATABASE ${DB_NAME} TO ${MCP_USER};
GRANT USAGE ON SCHEMA public TO ${MCP_USER};
GRANT SELECT ON ALL TABLES IN SCHEMA public TO ${MCP_USER};
ALTER DEFAULT PRIVILEGES FOR ROLE ${APP_USER} IN SCHEMA public GRANT SELECT ON TABLES TO ${MCP_USER};
SQL
  log "Leserechte fuer ${MCP_USER} gesetzt"
fi

if [ -n "$NEUES_PASSWORT" ]; then
  echo
  echo "Passwort fuer ${APP_USER} (einmalig):"
  echo "  ${NEUES_PASSWORT}"
fi
