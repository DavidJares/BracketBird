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
   - `src/Config/local.example.php` -> `src/Config/local.php`
2. Fill DB credentials in `src/Config/local.php`.
   - Public "Now" labels use the viewer browser timezone when JavaScript is available. `app.timezone` or `APP_TIMEZONE` is only an optional server fallback.
3. Run migrations:
   - `php scripts/migrate.php`
4. Configure web root to `public/`.
5. Open `/setup` and create the first superadmin.
6. Sign in at `/admin/login`.

## 🚀 Deployment (Shared Hosting)

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
        'name' => 'DB_NAME',
        'user' => 'DB_USER',
        'pass' => 'DB_PASS',
        'charset' => 'utf8mb4',
    ],
];
```

5. Set environment:

   `APP_ENV=prod`

6. Run migrations:

   via CLI:

   `php scripts/migrate.php`

or temporarily via browser (if CLI is not available)

7. Open:

   `/setup`

8. Create first superadmin

## Security Summary

- CSRF protection on POST actions
- Session cookie hardening (`httponly`, `samesite`, `secure` on HTTPS)
- Session ID regeneration on login/logout
- Session-based login throttling
- Prepared statements via PDO
- Upload validation (size, extension, MIME) and upload execution blocking
- Internal folder protection for shared-hosting fallback setups
- Production-safe error display controls and baseline security headers

## Documentation

- [Deployment Guide](docs/deployment.md)
- [Architecture Documentation](docs/architecture.md)

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

Admin pages support a browser-local dark/light theme preference stored in `localStorage`.
Public screens use the tournament-specific `public_view_theme` setting so a tournament can choose either the dark broadcast theme or a lighter outdoor-friendly theme independently from the admin UI.

Admin pages include a compact language selector in the top bar. The selected language is stored in the `bracketbird_lang` cookie, supports `en` and `cs`, falls back to English for unsupported values, and defaults to Czech only when no cookie exists and the browser `Accept-Language` header starts with `cs`. This first language pass only adds preference handling and selector-related translation scaffolding; the full UI string migration is intentionally left for a later step.

The main server-rendered UI now reads labels from `resources/lang/en.json` and `resources/lang/cs.json`, including admin navigation, tournament setup, team/group management, match control, public display settings, public screens, and print outputs. English is the source language; Czech uses matching keys and falls back through English when a key is missing.

The Tournament Settings admin tab uses a responsive product-style configuration layout with themed cards for general information, tournament structure, match rules, access, and the save action bar while preserving the existing server-rendered form behavior.

The Teams & Groups admin area uses a responsive workspace layout with roster metrics, add-team controls, balanced assignment, unassigned-team handling, group cards, compact reassignment rows, and inline edit/delete actions while keeping the existing PHP form submissions.

The Group Stage admin area uses a responsive match-management workspace with generation warnings, compact filters, a desktop match table, mobile match cards, start actions, winner treatment, and row/card detail navigation while preserving existing match generation and score-entry flows.

The Match Detail admin area uses a responsive scorekeeping workspace with a match hero, status/control card, and structured set-entry panel while preserving the existing start, save, reset, and knockout progression flows.

The Knockout admin area uses a responsive bracket-management workspace with generation warnings, themed table and bracket views, human-readable source labels, winner treatment, and match detail navigation while preserving existing knockout generation and progression behavior.

The Superadmin Dashboard uses a responsive tournament overview with summary metrics, searchable compact tournament rows, copy actions, and a grouped create-tournament panel while preserving existing create, detail, and delete flows.

The Public View admin area uses a responsive display-configuration workspace with display controls, overview content, branding/logo upload, and compact public-screen management while preserving existing public view and screen list save flows.

The Exports & Print admin area provides read-only, browser-printable tournament papers for operational use: full match schedule, schedule by court, schedule by group, group round robin matrices, and knockout bracket. Print pages use dedicated A4 print CSS, include a non-print toolbar, support `prefill=0` and `prefill=1`, and keep status/progression logic untouched. Full print pack is intentionally left for a later pass because mixing portrait and landscape outputs in one browser print flow should be tested carefully before release.
