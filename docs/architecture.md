# BracketBird Architecture

## 1. High-Level Architecture

BracketBird is a server-rendered PHP MVC-style application (lightweight, framework-free):

- Router maps HTTP routes to controller methods.
- Controllers coordinate request validation, auth checks, and model calls.
- Models encapsulate SQL access via PDO prepared statements.
- Views render Bootstrap-based HTML templates.

Design goals:

- shared-hosting compatibility
- minimal abstraction overhead
- clear, readable PHP

## 2. Project Structure

```text
public/
  index.php              # Front controller
  .htaccess              # Rewrite and web-root protections
  uploads/               # Public assets (logos), execution-restricted
src/
  bootstrap.php          # Autoload + service bootstrapping
  Router.php             # Lightweight router
  config/                # app + local config
  controllers/           # Request handlers
  models/                # Database access layer
  views/                 # Server-rendered templates
  migrations/            # Schema migration files
scripts/
  migrate.php            # Migration runner
storage/                 # Runtime storage (non-public)
docs/                    # Project documentation
```

## 3. Routing Overview

- Entry point: `public/index.php`
- Route registration: `src/Router.php` + controller wiring in front controller
- Main route groups:
  - Setup: `/setup`
  - Superadmin auth + dashboard: `/admin/...`
  - Tournament admin auth + management: `/tournament/{slug}/...`
  - Public read-only screens: `/public/{slug}/...`

## 4. Authentication Model

## Superadmin

- Global administrator role
- First account creation requires an out-of-band setup token and is serialized with a database advisory lock
- Auth routes:
  - `GET /admin/login`
  - `POST /admin/login`
  - `POST /admin/logout`
- Access to dashboard and cross-tournament administration

## Tournament Admin

- Tournament-scoped role
- Auth route:
  - `GET|POST /tournament/{slug}/login`
  - `POST /tournament/{slug}/logout`
- Session bound to a specific tournament ID/slug
- Session stores a fingerprint of the current password hash; changing the tournament credential invalidates existing sessions

## Public Access

- No login required
- Read-only routes only (`/public/{slug}/...`)
- Visibility controlled by `public_view_enabled`

## 5. Database Schema Summary

Primary tables:

- `superadmins`
- `tournaments`
- `tournament_groups`
- `teams`
- `matches`
- `match_sets`
- `schema_migrations`
- `schema_migration_steps`
- `login_attempts`
- `tournament_public_screens`

Conceptual model:

- One tournament has many groups, teams, and matches.
- Matches are split by stage (`group`, `knockout`).
- Per-set score details are in `match_sets`.
- Public screen toggles/order are stored per tournament.

## 6. Migrations Overview

Migrations build initial schema and apply incremental features including:

- team group assignment support
- public display settings and metadata
- public map URL/embed support
- match mode split for group and knockout

`scripts/migrate.php` is CLI-only and remains the preferred runner. URL-only shared hosts may temporarily enable `public/migrate-once.php` with an HTTPS POST and a server-only one-time token of at least 32 random bytes. Both entry points serialize runs with a per-database advisory lock, track completed versions in `schema_migrations`, and record each statement's recovery state in `schema_migration_steps`. Existing version-only records from the legacy runner are hash-backfilled without replaying DDL; partial/mismatched metadata, unknown newer versions, and interrupted or failed statements fail closed and require operator inspection rather than blind replay.

## 7. Tournament Lifecycle

1. Superadmin creates tournament.
2. Tournament settings configured (date, time, courts, modes, advancement count).
3. Teams added.
4. Teams assigned to groups (manual/automatic).
5. Group matches generated and scheduled.
6. Group scores entered, standings computed.
7. Knockout bracket generated from standings.
8. Knockout results entered with progression.
9. Public screens used for event display.

## 8. Group Stage Logic

- Round-robin pairings per group
- Scheduling uses available courts and match duration
- Regeneration requires confirmation
- Unassigned teams are handled with confirmation safeguards

## 9. Score Entry Logic

- Supports:
  - `fixed_2_sets`
  - `best_of_3`
- Input validation ensures valid set structure and winner derivation
- Group-stage fixed-two-set matches may draw; knockout fixed-two-set matches use total points as the required tiebreak
- Saves:
  - per-set details (`match_sets`)
  - summary + winner on `matches`
- Supports resetting finished group matches back to scheduled
- Uses a `lock_version` optimistic lock plus expected participants; repeated identical submissions are idempotent and stale conflicting writes are rejected
- Changing a group result after knockout generation requires explicit confirmation and atomically removes the stale knockout bracket
- Uses a monotonic tournament `state_version` so group/knockout generation cannot commit data assembled before a concurrent settings, team, or result change

## 10. Standings Logic

Computed from finished group matches only, including:

- played, wins, draws, losses
- sets for/against
- points for/against
- point difference
- tournament points

Sorting priority:

1. tournament points
2. head-to-head only when exactly two teams share first-level points
3. point difference
4. points scored
5. team ID as a stable deterministic fallback

## 11. Knockout Generation Logic

Features:

- Per-group advancement (`floor(N/G)` base)
- Wildcards for remaining slots
- Global seeding by position + performance
- Bracket size expanded to next power of two
- BYE support for top seeds
- Standard seed placement keeps the top two seeds in opposite halves
- Source mapping (`team_a_source`, `team_b_source`) for progression

Progression behavior:

- Saving knockout result advances winner automatically
- Downstream matches update between `pending` and `scheduled`
- If upstream result is edited after downstream scoring, confirmation is required
- Confirmed change resets dependent branch and re-applies progression
- Re-saving a result with the same winner preserves valid downstream scores
- Every generated knockout round receives planned court/time values

## 12. Public View System

Screens:

- overview
- next matches
- standings
- schedule
- knockout
- recent results

Display capabilities:

- configurable screen order/enable flags
- autoplay rotation
- dedicated display endpoint (`/public/{slug}/display`)
- QR code links to current screen URL
- overview metadata:
  - public title override
  - description
  - logo
  - map button URL
  - sanitized Google Maps embed URL

## 13. Security Architecture

## CSRF

- CSRF token generated in session
- POST requests validated globally
- Forms include CSRF token (auto-injection + explicit helper support)

## Session Hardening

- Cookie flags: `httponly`, `samesite`, `secure` (on HTTPS)
- Base-path-specific cookie name/path to avoid collisions between subdirectory deployments
- Session ID and CSRF-token rotation on login/logout and role changes
- Mutually exclusive superadmin/tournament-admin identities
- Configurable idle and absolute authentication lifetimes
- Persistent IP/scope brute-force throttling with atomic database updates; session fallback exists only before its migration is available

All generation-relevant model writes lock the owning tournament row and advance `tournaments.state_version` exactly once in the same transaction. Whole-form settings, team/assignment, Public View, and destructive-confirmation forms post the version captured when the page was rendered; their models compare it under the row lock before writing. Future team, structure, match, or form-scoped mutations that affect generated stages or overwrite multiple fields must preserve this invariant.

## Upload Validation

- Logo upload size limits
- Extension allowlist: PNG/JPG/JPEG/WEBP
- MIME and image-structure validation (`finfo_file` + `getimagesize`)
- Maximum 4096 pixels per side and 16 megapixels
- Randomized server filename
- New file is removed on database failure; old file is removed only after a successful database update
- Upload directories protected from script execution

## Environment and Errors

- Environment-controlled error display (`APP_ENV`)
- Production mode disables error display to end users
- Error logging remains enabled
- Canonical origins come from `APP_URL`; reverse-proxy headers are ignored unless `APP_TRUST_PROXY` is explicitly enabled
- Authenticated/setup routes use `Cache-Control: no-store`

## Protected Internal Folders

- Recommended: web root at `public/`
- Fallback root protections block direct access to internal directories when needed
- Directory listing disabled via Apache rules
