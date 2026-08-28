# BracketBird

A lightweight PHP tournament management system for futnet and similar sports.

BracketBird is built for small and medium local tournaments that need a practical admin workflow, public display screens, and shared-hosting-friendly deployment.

## Feature Overview

- Superadmin setup and authentication
- Tournament creation and management
- Tournament-admin access per tournament slug
- Team management and group assignment (manual + auto-balanced)
- Group stage match generation, scheduling, and score entry
- Standings with tie-break logic
- Knockout bracket generation with progression and dependent reset protection
- Public read-only screens (overview, next matches, standings, schedule, knockout, results)
- Rotating public display mode with QR links
- Public overview metadata (title, description, logo, map URL/embed)

## Stack

- PHP 8.x
- MySQL/MariaDB (PDO)
- Server-rendered pages
- Vanilla JS (small UX helpers)
- Bootstrap via CDN

## Quick Start (Local)

1. Copy local config:
   - `src/config/local.example.php` -> `src/config/local.php`
2. Fill DB credentials in `src/config/local.php`.
   - Public "Now" labels use the viewer browser timezone when JavaScript is available. `app.timezone` or `APP_TIMEZONE` is only an optional server fallback.
3. Set `APP_SETUP_TOKEN` to a cryptographically random value of at least 32 bytes. For example, generate one locally with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`; do not commit or log the resulting value.
4. Run migrations from the command line:
   - `php scripts/migrate.php`
5. Configure web root to `public/`.
6. Open `/setup`, enter the setup token, and create the first superadmin.
7. Remove `APP_SETUP_TOKEN` after the first account is created.
8. Sign in at `/admin/login`.

## Deployment (Shared Hosting)

BracketBird is designed to run on standard shared hosting (e.g. Wedos, Websupport).

### Basic steps

1. Upload project files (FTP or Git)
2. Create MySQL database
3. Create config file:

   `src/config/local.php`

4. Fill database credentials:

```php
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'DB_NAME',
        'username' => 'DB_USER',
        'password' => 'DB_PASS',
        'charset' => 'utf8mb4',
    ],
];
```

5. Set production environment and origin:

   - `APP_ENV=prod`
   - `APP_URL=https://tournaments.example.com`
   - `APP_SETUP_TOKEN=<random value of at least 32 bytes>` for first setup only
   - `APP_TRUST_PROXY=false` unless a trusted reverse proxy overwrites forwarded headers and the origin is not directly reachable

6. Run migrations (preferred method):

   `php scripts/migrate.php`

   Keep `scripts/migrate.php` CLI-only and blocked from the web. On URL-only shared hosting such as WEDOS, use the disabled-by-default, HTTPS-only `public/migrate-once.php` runner instead: take a database backup, configure a one-time `app.migration_token` of at least 32 random bytes in `src/config/local.php`, run the form once with a temporary schema-change database identity, and immediately remove the token and return to the limited runtime database identity. See `docs/deployment.md` for the full sequence.

7. Open `/setup`, provide the setup token, and create the first superadmin.
8. Remove `APP_SETUP_TOKEN` and verify `/setup` returns 404.

## Security Summary

- CSRF protection on POST actions
- Session cookie hardening (`httponly`, `samesite`, `secure` on HTTPS)
- Session ID and CSRF-token rotation on authentication changes, bounded idle/absolute lifetimes, and base-path-scoped cookies
- Persistent, atomic IP/scope login throttling (with a temporary session fallback only until migrations are applied)
- One-time setup protected by an out-of-band token and an atomic first-admin lock
- Tournament-admin sessions invalidated when the tournament credential changes
- Prepared statements via PDO
- Transactional stage changes plus tournament-wide form/version checks that reject stale settings, assignments, generation, and destructive confirmations
- Optimistic match locking to make score replays idempotent and reject conflicting stale submissions
- Upload validation (size, extension, MIME, dimensions), versioned logo replacement/deletion cleanup, and upload execution blocking
- Internal folder protection for shared-hosting fallback setups
- Serialized migrations with legacy-safe per-step recovery-marker backfill; the CLI runner is preferred, while the optional HTTPS shared-hosting runner is disabled without a strong one-time token
- Production-safe error display controls, authenticated-page cache controls, and baseline security headers

## Tests

The dependency-free security/integration suite requires a disposable MySQL database. It refuses destructive cleanup unless `APP_ENV=test`, the configured database name contains a standalone `test` or `audit` segment, and it contains no `prod`, `production`, or `live` segment.

```bash
APP_ENV=test \
DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_NAME=bracketbird_test DB_USER=bracketbird_test DB_PASS='test-only-password' \
php tests/run.php
```

Run an individual syntax check with `php -l path/to/file.php`; the validation commands and expected environment are also recorded in the production review.

## Documentation

- [Deployment Guide](docs/deployment.md)
- [Architecture Documentation](docs/architecture.md)
- [Production Security and Code Review](docs/audits/production-security-and-code-review.md)
- [UX/UI Review and Redesign](docs/audits/ux-ui-review-and-redesign.md)

## Current Status

BracketBird is an MVP with working:

- tournament administration
- group stage flow
- knockout generation and progression
- bracket views
- public display pages

The project is designed for incremental extension without introducing framework or build-tool complexity.

## UI Foundation

BracketBird includes a shared visual layer at `public/assets/css/bracketbird.css`.
It sits on top of Bootstrap CDN styles and provides the dark sports-product theme, admin shell, responsive navigation, themed cards, forms, tables, badges, match/bracket cards, flash messages, and public display styling.
No frontend build pipeline is required.

The 2026 Tournament Command Center redesign organizes tournament administration around the actual lifecycle: Overview & setup, Participants & groups, Group stage, Knockout, Public display, and Print center. Every tournament workspace has a prominent tournament identity, readiness/progress context, and a recommended next action. The older standalone Teams route remains available for existing deep links but is intentionally removed from primary navigation because its capabilities are present in Participants & groups.

The root page now provides a clear choice between tournament-scoped and superadmin access. Setup and sign-in use a focused shell instead of the full administration layout. BracketBird also renders branded, recovery-oriented 403 and 404 states while preserving the existing authorization and CSRF behavior.

Admin pages support a browser-local dark/light theme preference stored in `localStorage`.
Public screens use the tournament-specific `public_view_theme` setting so a tournament can choose either the dark broadcast theme or a lighter outdoor-friendly theme independently from the admin UI.

Admin pages include a compact language selector in the top bar. The selected language is stored in the `bracketbird_lang` cookie, supports `en` and `cs`, falls back to English for unsupported values, and defaults to Czech only when no cookie exists and the browser `Accept-Language` header starts with `cs`. This first language pass only adds preference handling and selector-related translation scaffolding; the full UI string migration is intentionally left for a later step.

The main server-rendered UI now reads labels from `resources/lang/en.json` and `resources/lang/cs.json`, including admin navigation, tournament setup, team/group management, match control, public display settings, public screens, and print outputs. English is the source language; Czech uses matching keys and falls back through English when a key is missing.

The Overview & setup screen adds readiness checks for participants, group assignment, schedule generation, public display, and court configuration above the responsive settings form. Server-side validation remains authoritative. Failed tournament creation preserves non-sensitive field values while deliberately requiring the password again.

The Teams & Groups admin area uses a responsive workspace layout with roster metrics, add-team controls, balanced assignment, unassigned-team handling, group cards, compact reassignment rows, and inline edit/delete actions while keeping the existing PHP form submissions.

The Group Stage admin area uses a responsive live desk that prioritizes in-progress matches and the next scheduled match on each court. Generation is progressively disclosed after a schedule exists, group/court filters remain server-rendered, optional status filters enhance the page without hiding content when JavaScript is disabled, and every match has an explicit Start, Enter score, Review result, or Open match action.

Group-stage generation builds deterministic circle-method rounds for every group, including natural byes for odd team counts, and then merges the rounds into one fairness-driven schedule. Candidate selection balances each group's normalized progress, gives priority to teams with more rest, rotates away from recently used groups, and uses stable IDs and round positions as final tie-breakers. Courts still cycle from 1 through the configured count within each shared start-time slot, and a team is never assigned to two courts in the same slot.

The Match Detail admin area uses a responsive scorekeeping workspace with a match hero, status/control card, structured set-entry panel, numeric mobile keyboards, select-on-focus score fields, Enter-to-advance keyboard behavior, and a reachable mobile save action while preserving the existing start, save, reset, optimistic-lock, and knockout progression flows.

The Knockout admin area uses a responsive bracket-management workspace with generation warnings, themed table and bracket views, human-readable source labels, winner treatment, and match detail navigation while preserving existing knockout generation and progression behavior.

The Superadmin Dashboard uses a responsive tournament overview with summary metrics, searchable compact tournament rows, copy actions, and a grouped create-tournament panel while preserving existing create, detail, and delete flows.

The Public View admin area uses a responsive display-configuration workspace with display controls, overview content, branding/logo upload, and compact public-screen management while preserving existing public view and screen list save flows.

Accessibility improvements include a skip link, meaningful page-level headings, `aria-current` navigation, table captions and scoped headers, textual status indicators, assertive error versus polite success announcements, strong `:focus-visible` styling, reduced-motion handling, and 44-pixel mobile action targets. The light operational theme is now the administrative default; the saved dark preference and tournament-controlled public themes remain available.

The Exports & Print admin area provides read-only, browser-printable tournament papers for operational use: full match schedule, schedule by court, schedule by group, group round robin matrices, and knockout bracket. Print pages use dedicated A4 print CSS, include a non-print toolbar, support `prefill=0` and `prefill=1`, and keep status/progression logic untouched. Full print pack is intentionally left for a later pass because mixing portrait and landscape outputs in one browser print flow should be tested carefully before release.
