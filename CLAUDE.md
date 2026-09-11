# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Was das ist

kachink: Zeiterfassung als Harvest-Ersatz (keine Rechnungsstellung). Monorepo mit drei Teilen:

- `api/` Laravel 12 + Sanctum, PostgreSQL 16, liefert die JSON-API unter `/api` und die gebaute SPA aus `api/public`
- `web/` Vue 3 + TypeScript + Pinia + vue-router + vue-i18n, Vite, SCSS/BEM
- `menubar/` Swift/SwiftUI-Menuleisten-App fuer macOS (Timer, Idle-Watchdog, Hotkey)

Spezifikation ist `PRD_kachink_v2.0.0.md` (Abschnitt 4 zum Deploy-Ziel ist veraltet, `docs/betrieb.md` gilt). `prd.md` ist die v1 und nur noch historisch. Betrieb, Deploy und Rollback stehen in `docs/betrieb.md`; Hosts, Zugaenge und Secrets ausschliesslich in `CLAUDE.local.md` (untracked).

## Befehle

### Lokal starten

Postgres und Redis kommen aus Docker Compose, API und Vite laufen nativ. Die `.env` zeigt auf Docker-Hostnamen, deshalb die Overrides auf der Kommandozeile:

```bash
docker compose up -d pgsql redis                     # Ports 5433 / 6380
cd api && DB_HOST=127.0.0.1 DB_PORT=5433 CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=sync php artisan serve --port=8091
cd web && VITE_API_PROXY_TARGET=http://127.0.0.1:8091 npx vite --port 5175
```

Port 8081 (Compose-Default) ist lokal von einem anderen Projekt belegt. `php artisan migrate:fresh --seed` legt die Organisation "freise design+digital" (Slug `freise`) mit Admin `markus@freise.design` / `password` an.

### Backend (`api/`)

```bash
php artisan test                                          # alle Tests, SQLite in-memory
php artisan test --filter=TimerTest                       # eine Klasse
php artisan test --filter=test_start_stop_and_single_running_timer
vendor/bin/pint                                           # Code-Style
php artisan harvest:import --org=<slug|uuid> [--fresh]    # Harvest-Import, Token/Account aus .env
```

### Frontend (`web/`)

```bash
npm run build-only      # Produktions-Build (NICHT `npm run build`: bricht wegen npm-run-all2 `{@}` ab)
npm run type-check      # vue-tsc
npm run lint            # oxlint + eslint mit --fix
npm run test            # vitest, nur src/__tests__/**/*.test.ts
npx vitest run src/__tests__/useReportPeriod.test.ts
```

### Frontend-Build committen

Auf dem Server laeuft kein npm. Das Bundle wird lokal gebaut und nach `api/public` committed. Nach jeder Frontend-Aenderung vor dem Push:

```bash
cd web && npm run build-only && rm -rf ../api/public/assets && cp -r dist/* ../api/public/
```

### Menubar (`menubar/`)

```bash
brew install xcodegen
xcodegen generate && xcodebuild -project klingeLING.xcodeproj -scheme klingeLING -configuration Release build
```

Xcode-Projekt heisst `klingeLING`, das Produkt `Kachink`. `menubar/build/` ist ignoriert.

## Deploy

Push auf `main` ist ein Live-Deploy: GitHub-Webhook auf Batcave LXC 114, dort laeuft `deploy.sh` (Tag `deploy-<datum>`, `git pull --ff-only`, `composer install --no-dev`, `migrate --force`, Caches, php-fpm reload, Healthcheck `/api/health`). Kein Staging. Nach jeder abgeschlossenen Aufgabe committen und pushen (der Owner arbeitet remote).

```bash
ssh batcave 'pct exec 114 -- tail -f /var/log/kachink-deploy.log'
```

Rollback: `git checkout deploy-<tag> && ./deploy.sh --no-pull` auf dem Server. Migrationen sind nur vorwaerts.

Stage: Push auf `hamlet` deployt https://hamlet.croeso.de (Checkout `/var/www/hamlet` auf LXC 114, Log `/var/log/hamlet-deploy.log`). Die Stage nutzt dieselbe Datenbank wie prod, ihre Migrationen laufen also gegen Prod-Daten. Lokal mit Prod-Daten testen: `_bootstrap/db-spiegeln.sh`.

Commit-Konvention: `typ(scope): beschreibung` mit `feat|fix|chore|refactor|test|docs` und Scope `api|web|menubar|docs`. Branch `legacy` und Tag `legacy-snapshot-2026-09-09` halten den Stand vor v2.

## Architektur

### Multi-Tenancy (Kern des Backends)

- `organizations` + Pivot `organization_user` (Pivot-Rolle `owner|admin|member`). Zusaetzlich hat `users.role` den Wert `admin|member`, und `User::isAdmin()` prueft nur diesen globalen Wert.
- Jede API-Route ausser Auth und `/organizations` steht hinter der Middleware `resolve.organization` (`app/Http/Middleware/ResolveOrganization.php`). Sie liest den Header `X-Organization-Id`, prueft die Mitgliedschaft und bindet `app('current_organization')`.
- Scoped Models (Client, Project, Task, TimeEntry, Tag) nutzen den Trait `BelongsToOrganization`: Global Scope auf `organization_id` und automatisches Setzen beim Erstellen, jeweils nur wenn `current_organization` gebunden ist. Ohne Bindung (Artisan, Tinker, Tests) sieht man alle Mandanten, also vor Datenarbeit `app()->instance('current_organization', $org)` setzen. Der Harvest-Import und der Seeder machen genau das.
- In `bootstrap/app.php` ist `ResolveOrganization` vor `SubstituteBindings` in die Middleware-Prioritaet gesetzt, damit Route-Model-Binding schon mandantengefiltert laeuft. Nicht aendern.
- Fremd-IDs in Requests werden ueber `App\Rules\InOrganization::exists('projects')` validiert statt mit einem nackten `exists`-Rule.
- Alle IDs sind UUIDs (`HasUuids`).

### Domaene

- Tasks sind global pro Organisation, nicht pro Projekt (Migration `make_tasks_global`).
- Ein User hat hoechstens einen laufenden Timer; `POST /time-entries/start` stoppt einen laufenden vorher. `source` ist `web|menubar|manual|api|harvest`.
- Stundensatz liegt am Projekt; `amount` in Reports = gerundete Dauer x `hourly_rate`, nur wenn `is_billable`.
- Reports (`App\Services\ReportService`) aggregieren komplett in PHP, damit Postgres und SQLite identische Ergebnisse liefern. Scopes `organization|client|project|user`, Parameter `date_from`, `date_to`, `rounding` (Minuten, erlaubte Werte in `config/reports.php`), `format=json|pdf|csv`, `locale=de|en` (Default de). Zeitzone fuer Tagesbuckets ist `REPORT_TIMEZONE`. Nicht-Admins sehen nur eigene Eintraege.
- PDF ueber dompdf mit `resources/views/reports/pdf.blade.php`; Emojis werden durch `App\Support\EmojiPdf` als Twemoji-Inline-Bilder ersetzt, weil dompdf sie nicht rendern kann. Uebersetzungen fuer PDF/CSV in `lang/{de,en}/reports.php`.
- Device-Code-Flow fuer die Menubar-App: `POST /auth/device/start`, Browser bestaetigt unter `/connect`, App pollt `GET /auth/device/{code}`.
- `routes/web.php` liefert fuer alles ausser `/api/*` die `public/index.html` (SPA-Fallback).

### Datenbank

Prod und lokal PostgreSQL 16, Tests SQLite in-memory: Migrationen muessen DB-agnostisch bleiben (keine Postgres-spezifischen Typen oder Raw-SQL ohne Fallback).

### Tests (Backend)

`tests/TestCase.php` bringt Helfer mit: `createOrganization()`, `memberOf($org)`, `adminOf($org)`, `actingInOrg($user, $org)` (Sanctum + Header) und `bindOrg($org)` (damit Factories `organization_id` fuellen). Neue Feature-Tests folgen dem Muster in `tests/Feature/TimeEntries/TimerTest.php`, insbesondere fuer Mandantentrennung.

### Frontend

- `src/api/client.ts` ist der einzige Axios-Client. Er haengt Bearer-Token (`localStorage['auth:token']`) und `X-Organization-Id` (`localStorage['org:current']`) an jede Anfrage. Ausloggen passiert nur bei 401 auf `/auth/me`.
- Stores in `src/stores`: `auth`, `org` (aktuelle Organisation), `timer`, `settings`, `toast`. Router-Guard in `src/router/index.ts` laedt User und Organisationen einmalig.
- i18n: Basis-Kataloge `src/i18n/{en,de}.ts`, Feature-Strings als `src/i18n/additions/<feature>.ts` mit Export `{ en, de }`. Sie werden per `import.meta.glob` deep-gemerged; neue Features bekommen eine eigene Additions-Datei statt Aenderungen an `en.ts`/`de.ts`. `globalInjection: true` muss bleiben.
- Styles: `src/scss/site.scss` laedt die base_resources-Struktur (reset, properties, typography, scaffold, header, footer, tables, forms, ui, components, views, utilities). Komponenten-Styles in `src/scss/components/<name>.scss` (Aggregator `components.scss`), View-Styles in `src/scss/views/<name>.scss` (Aggregator `views.scss`). BEM, Design-Tokens als `--c-*`, `--fs-*`, `--gap*` in `properties.scss`, px-Werte ueber `pxtorem()`. Kein Tailwind mehr; `api/package.json` und `api/resources/{css,js}` sind Laravel-Skeleton-Reste und werden nicht benutzt.
- Wiederverwendbare Bausteine: `ComboBox` (barrierefreie Auswahl mit Suche), `BaseModal`, `ConfirmDialog`, `ToastHost`, `AppPagination`, `TimerWidget`.
- Report-Detailseiten laufen unter `/reports/:scope(clients|projects|users)/:id`; Periodenlogik (Monatsbericht, letzter Werktag) in `src/composables/useReportPeriod.ts`, dazu der einzige Vitest.

### Menubar

Swift-Client gegen dieselbe API (`Services/APIClient.swift`, Token im Keychain). `TimerService` pollt alle 30 s, `IdleMonitor` fragt nach Inaktivitaet oder Sleep, ob die Zeit behalten, verworfen oder der Timer zum Idle-Zeitpunkt gestoppt wird. Server-Default ist `https://kachink.croeso.de`.
