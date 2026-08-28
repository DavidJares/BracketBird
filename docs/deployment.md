# BracketBird Deployment Guide

## 1. Hosting Requirements

- PHP 8.x
- MySQL or MariaDB
- PDO MySQL extension
- Apache 2.4 with `mod_rewrite`/`.htaccess` support recommended
- File upload support (for tournament logos)

Shared hosting is supported. No Node.js or websocket infrastructure is required.

## 2. Recommended Web Root

Set document root to:

```text
<project>/public
```

This is the safest and cleanest production setup.

## 3. Fallback When Document Root Cannot Point to `public/`

If hosting forces document root to project root:

- Keep root `.htaccess` enabled.
- Keep the root `index.php` front-controller fallback in place.
- Verify directory listing is disabled.
- Verify direct access to internal folders is blocked (`src/`, `storage/`, `scripts/`, `docs/`).

## 4. Deploying Files (Git or FTP)

## Git-based deployment

1. Clone/pull repository on server.
2. Ensure writable permissions for `public/uploads/` (and managed subfolders).

## FTP deployment

1. Upload full project directory.
2. Preserve `.htaccess` files.
3. Ensure upload directory permissions allow image uploads.

## 5. Database Provisioning

1. Create production database.
2. Create a dedicated runtime DB user with only the data privileges BracketBird needs on its own database.
3. Run migrations with a separate, temporary deployment identity that can create/alter schema; do not grant schema-change or global privileges to the long-lived runtime user.

## 6. Configuration (`src/config/local.php`)

Create `src/config/local.php` on server (never commit it).

Use `src/config/local.example.php` as template.

Typical values:

- host
- port
- database
- username
- password
- charset (`utf8mb4`)

## 7. Environment Configuration

Set production environment:

```text
APP_ENV=prod
APP_URL=https://tournaments.example.com
APP_SETUP_TOKEN=<random value of at least 32 bytes; remove after setup>
APP_TRUST_PROXY=false
```

`APP_URL` is the canonical origin without a path. Use `APP_BASE_PATH=/subdirectory` separately when needed. Enable `APP_TRUST_PROXY` only when a trusted reverse proxy overwrites `X-Forwarded-Proto` and direct clients cannot reach the origin; otherwise a client could spoof transport metadata.

Optional authentication lifetime overrides are `APP_AUTH_IDLE_TIMEOUT_SECONDS` (default 1800) and `APP_AUTH_ABSOLUTE_TIMEOUT_SECONDS` (default 43200). Use unsigned decimal seconds. Idle values are clamped to 60–86400 seconds and absolute values to 600–604800 seconds; syntactically invalid values use the defaults. The effective idle lifetime is never longer than the absolute lifetime. For example, `APP_AUTH_IDLE_TIMEOUT_SECONDS=60` and `APP_AUTH_ABSOLUTE_TIMEOUT_SECONDS=600` are honored as configured.

Also ensure DB environment variables are set if you use environment-driven config:

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

## 8. Run Migrations

From project root:

```bash
php scripts/migrate.php
```

The preferred migration endpoint is CLI-only and internal web paths are denied. Never make `scripts/migrate.php` browser-accessible. Both supported runners use a per-database advisory lock, record each statement as `running`, `complete`, or `failed`, and refuse to blindly replay an interrupted statement. On the first upgrade from the legacy version-only runner, they reconstruct statement hashes for versions already recorded in `schema_migrations` without replaying their DDL; any partial/mismatched recovery metadata or database version unknown to the deployed release fails closed.

### WEDOS or other URL-only shared hosting

WEDOS Webhosting cannot run normal shell commands and restricts its databases to WEDOS servers and phpMyAdmin. Do not remove the `scripts/` deny rule. Use the temporary web wrapper instead:

1. Export and verify a database backup.
2. Generate at least 32 random bytes, for example `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` on a trusted computer.
3. Temporarily set `'migration_token' => '<generated value>'` under `app` in server-only `src/config/local.php`.
4. Temporarily configure the database administrator/schema-change identity in `src/config/local.php`. On WEDOS this is the administrator identity used by phpMyAdmin, not the limited long-lived web identity.
5. Upload `public/migrate-once.php` and all lowercase `src/migrations/` files.
6. Open `/migrate-once.php` when `public/` is the document root, or `/public/migrate-once.php` when `/public` is the configured visible base path. HTTPS is required.
7. Submit the token once and confirm the success message.
8. Immediately remove `migration_token`, switch configuration back to the limited web database identity, and delete the server copy of `public/migrate-once.php`.

The wrapper returns 404 unless a token of at least 32 bytes is configured, accepts execution only by POST after a constant-time token check, and delegates to the same locked/recoverable migration model as the CLI command.

If a migration reports a `running` or `failed` step:

1. Stop deployment and keep the application on the previous compatible version.
2. Inspect the named migration statement and actual schema.
3. If the statement did not take effect, remove only that exact row from `schema_migration_steps` and rerun.
4. If it did take effect, mark only that exact row `complete`, set `completed_at`, and rerun.
5. Take and verify a backup before either recovery action. Do not guess or clear the entire migration table.

Migrations create/update schema for:

- superadmins
- tournaments
- groups
- teams
- matches
- match sets
- generated-stage consistency versioning
- public view settings
- login throttling
- migration version and step state

## 9. One-Time Setup and First Superadmin

1. Open `/setup`.
2. Enter the out-of-band `APP_SETUP_TOKEN` and create the first superadmin account.
3. Remove `APP_SETUP_TOKEN` from the environment/configuration.
4. Sign in at `/admin/login`.

The setup token must contain at least 32 bytes. If it is missing or too short, `/setup` fails closed with 404. After the first account exists, `/setup` is also unavailable.

## 10. Post-Deployment Verification

1. Superadmin login works.
2. Tournament admin login (`/tournament/{slug}/login`) works.
3. Public routes under `/public/{slug}/...` work.
4. Logout works without CSRF errors.
5. Uploaded logo renders in public overview when configured.
6. HTTP responses do not disclose `X-Powered-By` or exception details.
7. Authenticated and setup responses contain `Cache-Control: no-store`.
8. Cookies have `Secure` over HTTPS, `HttpOnly`, `SameSite=Lax`, and the expected base-path scope.
9. Direct requests for `src/`, `scripts/`, `storage/`, `.git`, backup files, and the physical `public/` prefix are denied in the project-root fallback.
10. A backup restore has been tested before event-day use.

## 11. Security Checklist

- Directory listing disabled.
- Internal folders not publicly accessible.
- `/setup` unavailable after first superadmin.
- Setup token removed after initialization.
- CSRF protection active on POST actions.
- Login throttle table exists and repeated failures are blocked across fresh sessions.
- Upload hardening active:
  - PNG/JPG/WEBP only
  - file size limits enforced
  - dimensions limited to 4096 px per side and 16 megapixels
  - upload path blocks script execution
- Production does not show PHP stack traces to users.
- The web server redirects HTTP to HTTPS; HSTS is enabled only after HTTPS is verified.
- `APP_URL` matches the only accepted public hostname.
- Reverse proxy trust and accepted `Host` values are constrained at the web server.
- Response headers present:
  - `X-Content-Type-Options`
  - `Referrer-Policy`
  - `X-Frame-Options`
  - `Content-Security-Policy`

## 12. Troubleshooting

## 404 on root

- Check document root mapping.
- If root mapping cannot be changed, verify fallback root `.htaccess` and root `index.php` exist.

## "Database credentials are not configured."

- Verify `src/config/local.php` exists on server (directory names are case-sensitive on Linux).
- Verify DB values and/or environment variables are correct.

## Migrations not applied

- Run `php scripts/migrate.php`.
- Confirm DB user has schema change permissions.

## Public routes not working

- Confirm tournament slug exists.
- Confirm Public View is enabled for that tournament.
- Confirm rewrite rules are active (`.htaccess` support enabled).

## Uploads not showing

- Confirm upload succeeded and path saved.
- Confirm `public/uploads/` permissions.
- Confirm server serves static files from `public/`.
- Confirm blocked-script rules are present but do not block image extensions.
