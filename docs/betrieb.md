# Betrieb: Deploy, Rollback, Zugaenge

Stand 2026-09-09. Keine Secrets in dieser Datei: Hosts, IPs, Passwoerter stehen in
`CLAUDE.local.md` (nicht im Repo) bzw. im Passwort-Manager.

## Umgebungen

| Umgebung | Branch | Ort | DB |
|---|---|---|---|
| lokal | `main` | `~/Sites/kachink`, Docker Compose (Postgres/Redis) + `php artisan serve` + Vite | Compose-Postgres `timetracker` |
| prod | `main` | Batcave LXC 114 (`web`), `/var/www/kachink`, nginx + php-fpm 8.3, davor Caddy im Edge-Container (`*.croeso.de`) und Cloudflare | zentrale Postgres 16 im Datencontainer (LXC 110), DB `kachink`, Rolle `kachink_rw` |

Blueprint ist Lucius (`~/Sites/lucius-app/docs/betrieb.md`): gleicher Container, gleicher
Webhook-Daemon, gleiche Ablaeufe.

## Deploy

Ein Push auf `main` ist ein Live-Deploy: GitHub meldet den Push per Webhook an LXC 114,
dort laeuft `deploy.sh` von selbst.

```bash
git push origin main                                                # loest den Deploy aus
ssh batcave 'pct exec 114 -- tail -f /var/log/kachink-deploy.log'   # zuschauen
```

Von Hand auf LXC 114:

```bash
/usr/local/bin/kachink-deploy           # derselbe Weg wie der Hook, ins Log
cd /var/www/kachink && ./deploy.sh      # direkt, ins Terminal
```

`deploy.sh`: Vorbedingungen (php, composer, php-fpm, `api/.env` mit APP_KEY und
DB_PASSWORD, sauberer Checkout) -> Tag `deploy-JJJJMMTT-HHMMSS` -> `git pull --ff-only`
-> `composer install --no-dev` -> `php artisan migrate --force` -> Caches -> `systemctl
reload php8.3-fpm` -> Healthcheck `GET /api/health` (sechs Versuche).

Die Frontend-Assets werden **lokal** gebaut und committed, auf dem Server laeuft kein npm:

```bash
cd web && npm run build-only && rm -rf ../api/public/assets && cp -r dist/* ../api/public/
```

## Auto-Deploy per Webhook

```
git push origin main
  -> GitHub-Webhook (HMAC, X-Hub-Signature-256)
  -> https://kachink.croeso.de/__deploy/kachink-deploy
  -> Cloudflare -> Caddy (Edge, *.croeso.de) -> nginx auf LXC 114
  -> adnanh/webhook (gemeinsamer Daemon, prueft Signatur und ref == refs/heads/main)
  -> /usr/local/bin/kachink-deploy-trigger  (antwortet sofort)
  -> /usr/local/bin/kachink-deploy          (Lock, Log)
  -> /var/www/kachink/deploy.sh
```

Einrichten oder Secret rotieren:

```bash
# auf LXC 114, als root
cd /var/www/kachink && _bootstrap/webhook/installieren.sh     # --neues-secret zum Rotieren
# lokal, mit dem Secret aus der Ausgabe
WEBHOOK_SECRET=... APP_URL=https://kachink.croeso.de _bootstrap/webhook/github-hook.sh
```

## Erstinstallation (so wurde es am 2026-09-09 eingerichtet)

1. Datencontainer: `APP_PASSWORT=... runuser -u postgres -- _bootstrap/db-anlegen.sh`
2. LXC 114: `git clone` nach `/var/www/kachink`, `api/.env` aus `api/.env.example`
   (pgsql, Host des Datencontainers, `REPORT_TIMEZONE=Europe/Berlin`,
   `QUEUE_CONNECTION=sync`, `CACHE_STORE=file`, `SESSION_DRIVER=file`), `php artisan key:generate`
3. nginx-Site aus `docs/beispiele/nginx.conf` nach `/etc/nginx/sites-available/kachink`,
   verlinken, `nginx -t && systemctl reload nginx`
4. `./deploy.sh --no-pull`, danach Webhook wie oben
5. Erste Organisation und Admin: `php artisan tinker` oder Harvest-Import
   (`php artisan harvest:import --org=<slug>`)

## Rollback

```bash
cd /var/www/kachink
git tag --list 'deploy-*' | tail -n 5
git checkout deploy-20260909-120000 && ./deploy.sh --no-pull
```

Migrationen sind vorwaertsgerichtet; ein Code-Rollback ueber eine Migration hinweg braucht
`php artisan migrate:rollback` von Hand.

## Backup

Die Datenbank liegt in der zentralen Postgres; dort laufen die Dumps des Datencontainers
(vgl. Lucius `umsatz-dump.timer`). Ein eigener Dump-Timer fuer `kachink` ist TODO.
