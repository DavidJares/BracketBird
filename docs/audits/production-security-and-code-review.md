# BracketBird Production Security and Code Review

Review date: 2026-07-24
Review branch: `review/security-hardening`
Baseline: `5b0c6f2` (`main` / `origin/main`)

## 1. Executive summary

This was a repository-wide review of BracketBird's PHP request lifecycle, routes, controllers, models, migrations, templates, JavaScript, configuration, Apache rules, uploads, documentation, and operational assumptions. The starting tree was clean and no production system, production database, or production secret was accessed.

No confirmed Critical issue, SQL injection, arbitrary file inclusion, command injection, or exploitable stored/reflected XSS was found. Prepared statements, contextual HTML escaping, global POST CSRF validation, tenant-filtered model queries, password hashing, and read-only public routes were already useful controls.

Seven High-risk defect classes were confirmed. The most serious were:

- a case-sensitive Linux checkout could not load configuration, views, or migrations, and the ignored local-secret path did not match the tracked directory;
- the documented project-root web layout could expose internal files and a browser-callable migration utility on common Apache configurations;
- an unprotected first-run setup race allowed an internet user to claim the first superadmin account;
- edits to group results, teams, assignments, or tournament structure could leave a stale knockout bracket;
- concurrent score submissions could overwrite newer participants/results and corrupt progression;
- multi-statement MySQL migrations were neither serialized nor recoverable after implicit DDL commits;
- standings used an inconsistent/non-transitive tiebreak in one context.

Repository-level fixes were implemented for those issues and the confirmed Medium issues that could be changed safely. The changes add out-of-band setup authorization, schema-readiness checks, atomic login throttling, authentication lifetimes and credential invalidation, transactional tournament-stage resets, form-captured optimistic concurrency checks, optimistic match locking, deterministic standings and bracket topology, strict input/resource limits, hardened uploads and web rules, canonical-origin handling, migration recovery state, and a dependency-free MySQL/HTTP regression suite.

The repository is materially safer, but production readiness still depends on server work that cannot be verified here: backup/restore, TLS redirect and HSTS, accepted-host enforcement, reverse-proxy isolation, least-privilege DB identities, current PHP/MySQL patch levels, protected log collection, and applying the new migrations to a backed-up production database.

## 2. Scope and method

The review covered every tracked source/documentation file and hidden repository/server-control files available in the workspace. Important behavior was traced across the front controller, router, authorization checks, tenant-scoped queries, state-changing forms, score calculations, stage generation, template output contexts, upload lifecycle, migrations, and both supported Apache document-root layouts.

Work was performed on a dedicated branch. A clean, disposable MySQL 8.4 database and local PHP server were used; production data was not copied or queried. No Composer or Node dependency install was performed because the repository has no package manifest and the project explicitly avoids Node build tooling.

## 3. Verified architecture

BracketBird is a framework-free, server-rendered PHP application:

```text
HTTP request
  -> public/index.php (environment, session, headers, CSRF, route registration)
  -> Router (static or path-parameter match)
  -> controller (authentication, authorization, validation, orchestration)
  -> model (PDO/native prepared SQL and transactions)
  -> view/layout (escaped HTML plus small inline vanilla JS)
  -> MySQL/InnoDB
```

The root `index.php` and `.htaccess` provide an Apache shared-hosting fallback when the document root cannot point at `public/`. The preferred production document root remains `public/`.

The roles and entry points are:

- superadmin: global tournament creation/deletion and all tournament administration under `/admin/...`;
- tournament admin: password-authenticated, single-tournament administration under `/tournament/{slug}/...`;
- public user: read-only enabled screens under `/public/{slug}/...`;
- setup operator: one-time creation of the first superadmin at `/setup`, now requiring an out-of-band token;
- deployment operator: CLI-only schema migration through `scripts/migrate.php`.

Core state is stored in `superadmins`, `tournaments`, `tournament_groups`, `teams`, `matches`, `match_sets`, and `tournament_public_screens`. Security/operations state is stored in `login_attempts`, `schema_migrations`, and `schema_migration_steps`.

There are no application-side AJAX mutation endpoints, shell invocations, WebSockets, Composer dependencies, npm dependencies, or user-controlled PHP include paths.

## 4. Threat model and trust boundaries

### Assets

- superadmin credentials and global authority;
- tournament-admin credentials and tournament-scoped authority;
- PHP session identifiers and CSRF tokens;
- tournament, team, schedule, score, standings, and bracket integrity;
- database credentials and local configuration;
- uploaded public logos;
- migration state, backups, and application/security logs.

### Realistic attackers

- an unauthenticated internet user probing setup, login, public routes, backup files, uploads, and malformed route parameters;
- an authenticated tournament admin attempting to cross into another tournament or global administration;
- a malicious/compromised browser submitting CSRF requests;
- two legitimate operators concurrently editing the same score or tournament structure;
- an operator deploying to Linux/shared hosting using the documented fallback;
- a client spoofing `Host` or forwarding headers when the origin/proxy is misconfigured.

### Trust boundaries and abuse cases

- request data becomes trusted only after controller validation;
- numeric IDs/slugs must be re-bound to the authenticated tournament in model queries;
- sessions must not carry both roles or survive credential changes indefinitely;
- group-stage data is an upstream dependency of knockout data;
- a score, settings, assignment, or destructive-confirmation form can be stale by the time it is submitted;
- uploaded bytes remain untrusted even after an extension check;
- forwarded transport headers are trusted only when a configured reverse proxy owns them;
- migration files are trusted code, but migration execution can be concurrent or interrupted.

## 5. Findings

Severity reflects realistic impact in the documented internet-facing deployment. Confidence is High unless stated otherwise.

### Critical

No Critical finding was confirmed.

### High

#### H-01 — Case-sensitive deployments failed and local credentials could miss ignore protection

- **Severity / confidence:** High / High
- **Affected locations:** Git tree under `src/config`, `src/controllers`, `src/models`, `src/views`, and `src/migrations`; `src/bootstrap.php`; controller render helpers; `src/Support/Language.php`; `scripts/migrate.php`; `.gitignore`; deployment documentation.
- **Scenario:** A Linux checkout contains lowercase directories, while runtime literals used uppercase paths. Normal pages fail to find configuration/views and migrations silently apply zero files. An operator following the uppercase documentation could create `src/Config/local.php`, which was not the ignored/loaded lowercase secret path.
- **Impact:** Production outage, unapplied security migrations, and possible accidental credential commit.
- **Evidence:** `git ls-files` showed lowercase canonical directories while the original literals/documentation referenced `Config`, `Views`, and `Migrations`.
- **Remediation:** Normalize every runtime/documented path and new file to the canonical lowercase Git tree; fail if the migration directory is absent or empty.
- **Status:** Fixed.
- **Test:** PHP lint; canonical-path scan; clean migration run using `src/migrations`; migration runner now throws on a missing/empty directory.

#### H-02 — Project-root hosting exposed internals and a browser-executable migration utility

- **Severity / confidence:** High / High
- **Affected locations:** root `.htaccess`, `public/.htaccess`, `public/uploads/.htaccess`, root `index.php`, `scripts/migrate.php`, `public/index.php`, deployment guidance.
- **Scenario:** The documented fallback served the project root. Existing files/directories bypassed the front controller, directory indexes were not comprehensively disabled, and `scripts/migrate.php` had no CLI guard. Source, logs, dumps, backup files, Git metadata, or DDL execution could therefore become reachable depending on Apache/vhost defaults.
- **Impact:** Source/secret disclosure and unauthenticated schema mutation.
- **Evidence:** The original root rules exempted existing paths and the migration script bootstrapped regardless of SAPI.
- **Remediation:** Prefer `public/` as document root; add strict deny/allow rules for the fallback; disable indexes/multiviews; allow only generated image-shaped uploads; make migrations CLI-only; suppress errors before bootstrap.
- **Status:** Fixed for supported Apache layouts.
- **Test:** Apache 2.4.66 syntax and request matrices for both document roots. Legitimate assets and all seven public screens reached the front controller; internal, dot, backup, physical `public/`, script, and invalid-upload paths returned 403.

#### H-03 — Public first-run setup could be claimed, raced, or run against an incomplete schema

- **Severity / confidence:** High / High
- **Affected locations:** `SetupController::index/store`, `SuperadminModel::hasAny/createFirst`, `MigrationModel::allMigrationsAreComplete`, setup view, `src/config/app.php`.
- **Scenario:** When no account existed, any internet user could submit `/setup` first. Two concurrent requests could both pass the count check, database errors were converted into “no admin”, and setup considered an old/partially migrated schema ready merely because `superadmins` existed.
- **Impact:** Complete superadmin takeover or creation of an administrator for an installation whose first dashboard request immediately fails.
- **Evidence:** The original authorization was only `COUNT(*) === 0`, with no out-of-band proof, atomic first-account operation, or verification that every current migration statement was recorded complete.
- **Remediation:** Require `APP_SETUP_TOKEN` of at least 32 bytes, compare with `hash_equals`, fail closed when absent, stop swallowing database errors, serialize/recheck first-admin creation under a database advisory lock and transaction, and verify the exact current migration versions, statement hashes, step counts, statuses, and completion timestamps before rendering or accepting setup.
- **Status:** Fixed.
- **Test:** Integration coverage for missing/wrong/correct token, incomplete migration GET/POST denial, migration-integrity corruption, and a single resulting account; MySQL advisory-lock path exercised.

#### H-04 — Upstream and stale operator edits could corrupt generated tournament stages

- **Severity / confidence:** High / High
- **Affected locations:** migration `20260724_000013_add_tournament_state_version.php`; tournament settings/team/group/score/generation controllers and forms; `TournamentModel`, `TeamModel`, and `MatchModel` mutation paths.
- **Scenario:** A group result was corrected, teams were deleted/reassigned, groups/modes/scheduling changed, or group matches regenerated after a knockout bracket existed. Separately, an old whole-form settings or destructive-confirmation page could overwrite a newer operator's fields and delete matches regenerated after that page was rendered.
- **Impact:** Wrong qualifiers, missing participants, invalid results, silent lost updates, and loss of tournament integrity.
- **Evidence:** Dependent stages were not invalidated transactionally, and locking the latest row without comparing the version captured by the browser did not prevent stale-form writes.
- **Remediation:** Detect structural changes, require explicit destructive confirmation, lock the tournament, and atomically remove affected generated matches. Add monotonic `tournaments.state_version`; post the rendered version with whole-form/team/assignment/Public View operations; compare it after `FOR UPDATE`; advance it exactly once with every generation-relevant mutation; reject stale generation and stale destructive confirmations before any delete.
- **Status:** Fixed.
- **Test:** Model/HTTP regressions verify confirmation-required rollback, atomic removal with confirmation, first-generation success/second-generation staleness, upstream-team invalidation, stale whole-form rejection, stale automatic assignment, and preservation of regenerated matches after a stale confirmed request.

#### H-05 — Concurrent score submissions could overwrite participants and progression

- **Severity / confidence:** High / High
- **Affected locations:** match score/start/reset controller handlers; `MatchModel` score/progression methods; migration `20260724_000009_add_match_lock_version.php`; match forms.
- **Scenario:** Two operators loaded the same score form. A later submission used stale participants/version after another score or bracket progression update and silently overwrote newer state or advanced the wrong winner.
- **Impact:** Corrupted scores and knockout progression.
- **Evidence:** The original write path validated a previously read row but did not include an expected version/participants in the transactional update.
- **Remediation:** Add `matches.lock_version`; post it with forms; lock tournament/match rows; compare expected participants; update with a version predicate; return explicit stale/idempotent/confirmation states. Preserve downstream state when the winner is unchanged and reset confirmed descendants only when it changes.
- **Status:** Fixed.
- **Test:** Clean migration schema check plus repeated identical and stale/conflicting score regression cases.

#### H-06 — Migration execution was concurrent and unrecoverable after MySQL DDL commits

- **Severity / confidence:** High / High
- **Affected locations:** `MigrationModel::migrate/runMigrationStep`, `scripts/migrate.php`, `schema_migration_steps`.
- **Scenario:** Two deployments ran migrations simultaneously, or the process failed between statements in a migration. MySQL implicitly committed DDL, the version was not recorded, and rerunning could hit duplicate/half-applied schema changes.
- **Impact:** Deployment outage and manual schema inconsistency.
- **Evidence:** The original runner wrapped multi-statement DDL in a transaction even though MySQL can implicitly commit it and had no advisory lock or per-step state.
- **Remediation:** Obtain a per-database `GET_LOCK`, validate unique migration metadata, record each statement as `running/complete/failed`, skip completed steps, refuse blind replay of ambiguous steps, and hash-backfill recovery rows for legacy version-only records without re-executing their DDL.
- **Status:** Fixed with an intentional fail-safe recovery requirement.
- **Test:** MySQL 8.4 clean run applied 13 versions/28 steps; a second run applied zero; all steps were `complete`. Dropping the step table while retaining all version rows reconstructed exactly 28 hashes with zero DDL replays. Unknown-version, changed-hash, bad-count, and non-complete metadata were rejected by readiness checks; the migration runner also refused a database newer than the deployed release. A seeded `running` step was refused, and an explicitly verified completed step resumed only after its recovery marker was corrected.
- **Residual:** A process crash after DDL commits but before the `complete` update deliberately blocks future runs. An operator must inspect and resolve that exact step as documented.

#### H-07 — Standings ordering was inconsistent and non-transitive

- **Severity / confidence:** High / High
- **Affected locations:** `TournamentController::buildGroupStandings/resolveStandingsTieClusters`; equivalent `PublicViewController` methods.
- **Scenario:** Three or more teams tied on tournament points. Pairwise head-to-head comparisons were used inside a general sort comparator, which could be non-transitive; admin/public contexts also used different final ordering.
- **Impact:** Wrong or inconsistent knockout seeding.
- **Evidence:** The original comparator mixed pairwise match outcomes with multi-team sorting and a random/different fallback.
- **Remediation:** Partition equal-points clusters; apply head-to-head only to exactly two tied teams; otherwise use point difference, points scored, then team ID. Keep admin/public implementations identical.
- **Status:** Fixed.
- **Test:** Deterministic tie-cluster regression cases and EN/public parity review.

### Medium

#### M-01 — Login throttling was session-local and burst-raceable

- **Severity / confidence:** Medium / High
- **Affected locations:** `BaseController` login reservation helpers; `LoginAttemptModel`; auth controllers; migration `20260724_000012_create_login_attempts.php`.
- **Scenario:** An attacker discarded `PHPSESSID` between guesses or sent parallel requests before check-then-record updates completed.
- **Impact:** Online password guessing was not meaningfully bounded.
- **Evidence:** The original counter lived only in the attacker-controlled session lifecycle.
- **Remediation:** Store hashed scope/IP keys in MySQL, reserve attempts atomically before expensive verification, reset on success, and keep only a temporary session fallback while the migration is unavailable.
- **Status:** Fixed.
- **Test:** Six sequential fresh-cookie failures plus eight simultaneously started PHP processes contending for one MySQL throttle bucket; exactly five reservations succeeded and one active lock remained.
- **Residual:** NAT users share an IP bucket and distributed multi-IP attempts require edge/WAF controls.

#### M-02 — Authentication state could coexist indefinitely or survive credential changes

- **Severity / confidence:** Medium / High
- **Affected locations:** `BaseController` authentication lifecycle; both auth controllers; `TournamentController::resolveTournamentBySlugWithAdminAccess/handleUpdate`.
- **Scenario:** Superadmin and tournament-admin identities could coexist in one session; logout removed only one role; there was no idle/absolute expiry; changing a tournament password did not revoke an already-authenticated session. Configuring a stricter lifetime below the old hard-coded minimum silently replaced it with a much longer default.
- **Impact:** Latent privilege, unexpected role confusion, and continued access with an old credential.
- **Evidence:** Session arrays were independently set/unset with no timestamps or server-side credential check.
- **Remediation:** Centralize role transitions; clear the other identity; rotate session ID and CSRF token; add bounded idle/absolute timestamps; honor and document 60–86400-second idle and 600–604800-second absolute bounds using explicit clamping; bind tournament sessions to a SHA-256 fingerprint of the current password hash and compare it on protected requests.
- **Status:** Fixed.
- **Test:** Login/logout rotation, cross-role access, expired-session/CSRF rotation, short configured lifetimes, boundary clamping, and password-change invalidation coverage.

#### M-03 — Fixed-two-set match semantics contradicted standings and knockout requirements

- **Severity / confidence:** Medium / High
- **Affected locations:** score validation, standings builders, match/public views.
- **Scenario:** A 1:1 fixed-two-set match stored a total-points winner, while standings displayed/awarded it as a draw. Knockout, conversely, cannot legally advance without a winner.
- **Impact:** Wrong tables, UI contradictions, or a bracket unable to progress.
- **Evidence:** Winner calculation and standings interpretation used different rules.
- **Remediation:** Treat 1:1 as a draw in the group stage (one table point each). In knockout, require unequal total points and advance the total-points winner.
- **Status:** Fixed.
- **Test:** Group draw and knockout tiebreak/balanced-total validation cases.

#### M-04 — Multi-step writes and file replacement were not atomic

- **Severity / confidence:** Medium / High
- **Affected locations:** `TournamentModel::create/update/savePublicViewSettings/savePublicScreens/deleteById`; `TeamModel` bulk/assignment/delete; `MatchModel` replacement/progression; logo upload and tournament-deletion handlers.
- **Scenario:** A later insert/update failed after the tournament, groups, assignments, screens, sets, or uploaded file had already changed. Concurrent Public View forms could also restore a deleted old logo path or overwrite unrelated general settings, and deleting a tournament left its managed logo behind.
- **Impact:** Partial database state, orphaned files, or broken logo references.
- **Evidence:** Related statements and file/database ordering were previously independent.
- **Remediation:** Add transaction ownership helpers and tournament row locks; perform related DB writes atomically; scope screen-list writes to screen rows; version Public View forms; make the model preserve/read the current logo under the lock and return the actual previous path; delete a losing upload on stale/failure; delete the prior logo only after commit; return and remove the managed logo after tournament deletion.
- **Status:** Fixed for normal request completion.
- **Test:** Forced transactional rollback, Public View scope isolation, same-version competing logo writes, returned locked prior path, and real managed-file removal during tournament deletion.
- **Residual:** The filesystem and MySQL cannot share one transaction. A process/host crash in the narrow file-move/DB-commit window can still leave an unreferenced file; periodic managed-upload reconciliation is appropriate at larger scale.

#### M-05 — Server validation allowed malformed, oversized, or resource-heavy input

- **Severity / confidence:** Medium / High
- **Affected locations:** tournament/team/public-view/score handlers and views; `TeamModel::create`; logo upload handler.
- **Scenario:** Invalid calendar dates, non-canonical integers, excessive names/descriptions/passwords, score values beyond `TINYINT`, unlimited teams, or a tiny compressed image with extreme pixel dimensions reached persistence/processing.
- **Impact:** 500 errors, truncation, scheduler blow-up, authentication ambiguity, or memory/renderer pressure.
- **Evidence:** Browser constraints were stronger than several server checks and team scheduling is quadratic.
- **Remediation:** Strict integer and calendar parsing; explicit character/byte limits; bcrypt-safe 72-byte passwords; scores 0–99; advancement 2–64; at most 64 teams and 32 groups (the maximum compatible with at least two teams per group); image MIME/structure plus 4096-side/16-MP limits.
- **Status:** Fixed.
- **Test:** Boundary/malformed tournament inputs, long Unicode team names, scores, and passwords are exercised by the suite; image MIME/structure/dimension handling was verified by code-path and Apache upload-policy review.

#### M-06 — Later knockout rounds lacked operational court/time assignments

- **Severity / confidence:** Medium / High
- **Affected locations:** knockout generation in `TournamentController`; knockout detail query/views.
- **Scenario:** Only the first knockout round had usable timing/court data; later matches displayed `TBD` and could not be operationally scheduled from generated output.
- **Impact:** Event-day scheduling failure.
- **Evidence:** Generated later-round rows omitted planned values.
- **Remediation:** Carry schedule slots through all rounds and select `court_number`/`planned_start` in detail views.
- **Status:** Fixed.
- **Test:** Generated bracket inspection and detail-query coverage.

#### M-07 — Production errors, cache policy, proxy trust, and generated origins were unsafe/incomplete

- **Severity / confidence:** Medium / High
- **Affected locations:** root/public front controllers; `src/config/app.php`; `BaseController::canonicalOrigin`; absolute URL/QR/share call sites; language/session cookie handling.
- **Scenario:** Bootstrap errors occurred before production error settings; authenticated pages could be cached; `X-Powered-By` remained; forwarded HTTPS was trusted implicitly; raw `HTTP_HOST` entered QR/share links; subdirectory deployments shared a root cookie name/path.
- **Impact:** Information disclosure, session collision, insecure cookies behind a proxy, or attacker-influenced generated links.
- **Evidence:** Header/session configuration was late or absent and absolute links concatenated request headers.
- **Remediation:** Disable display before bootstrap, add a generic exception handler and baseline headers, no-store protected routes, remove technology header, ignore forwarded proto by default, support explicit `APP_URL`/`APP_TRUST_PROXY`, validate canonical/fallback origins, and scope session name/path by base path.
- **Status:** Fixed in repository.
- **Test:** Front-controller/configuration code-path review and local HTTP/session smoke coverage. Exact post-proxy header, origin, cookie, redirect, and cache behavior remains in the production checklist below.
- **Residual:** Enforce the accepted host, HTTP-to-HTTPS redirect, and HSTS at the production proxy/web server.

#### M-08 — Upload serving and replacement needed defense in depth

- **Severity / confidence:** Medium / High
- **Affected locations:** logo handler; `public/uploads/.htaccess`.
- **Scenario:** Polyglot/mislabeled files, executable handlers, alternate filenames, or failed DB replacement could leave unsafe/orphaned content.
- **Impact:** Stored active content on permissive hosting or broken public pages.
- **Evidence:** Extension/MIME checks existed, but handler/type removal, strict generated-name serving, image-structure/dimension checks, and atomic cleanup were incomplete.
- **Remediation:** Cross-check extension, `finfo`, and `getimagesize`; randomize a fixed filename format; limit bytes/dimensions; remove handlers/types/CGI; serve only generated PNG/JPEG/WEBP names; add nosniff/sandbox headers; use versioned replacement ordering and managed-file cleanup.
- **Status:** Fixed for Apache; non-Apache servers need equivalent rules.
- **Test:** Apache valid/invalid upload request matrix and application validation review.

#### M-09 — Third-party frontend asset was behind current patch and one page lacked SRI

- **Severity / confidence:** Medium / Medium
- **Affected locations:** admin and public layouts.
- **Scenario:** Bootstrap 5.3.3 was loaded from jsDelivr while the supported 5.3 line had newer fixes; the public CSS include did not pin expected bytes with Subresource Integrity.
- **Impact:** Avoidable dependency defects and CDN supply-chain exposure.
- **Evidence:** Repository URLs were pinned to 5.3.3. Official Bootstrap documentation currently identifies 5.3.8 and publishes matching SRI values.
- **Remediation:** Upgrade within the compatible 5.3 patch line and add official SRI/crossorigin attributes everywhere.
- **Status:** Fixed to Bootstrap 5.3.8.
- **Test:** Source scan for old CDN URLs/missing integrity and HTTP render checks.

#### M-10 — No automated security/workflow regression suite

- **Severity / confidence:** Medium / High
- **Affected locations:** repository-wide; new `tests/`.
- **Scenario:** Authorization, CSRF, setup, scoring, and stage-integrity regressions could ship unnoticed.
- **Impact:** High-risk defects recur despite otherwise readable code.
- **Evidence:** No test directory, framework, CI definition, or test command existed.
- **Remediation:** Add a dependency-free PHP runner against an explicitly disposable MySQL database, with destructive safety guards and real HTTP/session workflows.
- **Status:** Fixed locally; CI remains a recommended next step.
- **Test:** See section 9.

#### M-11 — Security-relevant actions were not distinguishable in application logs

- **Severity / confidence:** Medium / High
- **Affected locations:** centralized controller security-event helper and authentication/setup/destructive handlers.
- **Scenario:** A first-admin creation, privileged login/logout, credential change, or tournament deletion occurred without a stable security event.
- **Impact:** Weak incident reconstruction and alerting.
- **Evidence:** Only general PHP error logging existed.
- **Remediation:** Emit structured fixed-name events with timestamp and numeric object IDs only; never log usernames, slugs, IPs, credentials, tokens, cookie/session values, or request bodies.
- **Status:** Fixed for key authentication and destructive lifecycle events.
- **Test:** Code-path inspection; production log routing/alerting remains an operator check.

#### M-12 — Knockout seed topology did not reliably separate top seeds

- **Severity / confidence:** Medium / High
- **Affected locations:** knockout bracket construction in `TournamentController`.
- **Scenario:** A nominally seeded eight-team bracket could place seed 1 and seed 2 in the same half, making them meet before the final.
- **Impact:** The bracket contradicted standard seeding expectations and gave qualifiers an unintended competitive path.
- **Evidence:** First-round pairs were assembled linearly rather than from a recursively balanced seed order.
- **Remediation:** Generate a deterministic standard bracket order that keeps the top two seeds in opposite halves and preserves winner-source wiring across later rounds.
- **Status:** Fixed.
- **Test:** Eight-seed topology is asserted as `[1,8]`, `[5,4]`, `[3,6]`, `[7,2]`; semifinal source references are checked for opposite halves.

### Low

#### L-01 — Clickable match rows/cards were mouse-only

- **Severity / confidence:** Low / High
- **Affected locations:** `src/views/admin/tournament_detail/matches.php`.
- **Scenario:** A keyboard-only operator could not open non-button portions of a clickable match row/card.
- **Impact:** Accessibility and event-day usability defect.
- **Remediation:** Add link role/tab focus and Enter/Space activation while excluding nested controls.
- **Status:** Fixed.
- **Test:** Markup/handler inspection.

#### L-02 — Some no-op object mutations reported success

- **Severity / confidence:** Low / High
- **Affected locations:** for example `TeamModel::update` and its controller success path.
- **Scenario:** A validly authorized request supplies a missing team ID; the tenant-filtered update changes zero rows but UI reports success.
- **Impact:** Operator confusion; no cross-tenant write occurs.
- **Remediation:** Return an affected/not-found state consistently and render an accurate message.
- **Status:** Fixed.
- **Test:** Missing-team writes return `WRITE_NOT_FOUND`; the controller no longer reports them as successful. Tenant predicates still prevent cross-tournament writes.

#### L-03 — CSP still permits inline script/style execution

- **Severity / confidence:** Low / High
- **Affected locations:** `public/index.php` CSP and numerous server-rendered inline scripts.
- **Scenario:** The policy contains `'unsafe-inline'`, reducing CSP's value if a future output-encoding defect is introduced.
- **Impact:** Defense-in-depth gap, not a currently confirmed XSS.
- **Remediation:** Move scripts/styles to local static files or add per-response nonces, then remove `'unsafe-inline'` after browser testing.
- **Status:** Not changed because a safe repository-wide nonce refactor would be disproportionate in this pass.

#### L-04 — Public qualifier highlighting could mark the wrong wildcard

- **Severity / confidence:** Low / High
- **Affected locations:** public standings assembly and `src/views/public/screen.php`.
- **Scenario:** The display highlighted a fixed number of rows per group instead of the exact globally ranked wildcard team selected by advancement logic.
- **Impact:** Spectators and operators could see the wrong team presented as advancing even when the actual knockout generation was correct.
- **Remediation:** Compute the exact advancing team-ID set from base per-group qualifiers plus the global wildcard ranking and highlight only those IDs.
- **Status:** Fixed.
- **Test:** Regression data verifies guaranteed qualifiers plus the actual cross-group wildcard.

### Informational / verified secure behavior

- All reviewed SQL involving request values uses native PDO prepared statements. Dynamic `IN` lists are generated from integer arrays and dynamic modes/stages are controlled by application constants.
- Every registered state-changing route is POST and the front controller rejects a missing/mismatched 32-byte session CSRF token with constant-time comparison.
- Protected object queries include tournament ownership predicates; slug-admin resolution compares the authenticated tournament ID and current credential fingerprint.
- Database-originated text is escaped for HTML/attribute contexts; inline JSON uses `JSON_HEX_*`; no untrusted `innerHTML`, `eval`, `document.write`, or shell execution was found.
- Map embeds accept only HTTPS Google Maps embed URLs. The external QR image service still learns the encoded public URL and remains an availability/privacy dependency; self-hosted QR generation is a future option.
- Public screens are read-only and additionally gated by `public_view_enabled`.

## 6. Code-quality findings

Positive characteristics:

- strict types and final classes are used consistently;
- controllers generally orchestrate while models own SQL;
- prepared statements and explicit tenant filters are the norm;
- the code remains framework-free and compatible with shared hosting;
- translations now have key parity and deterministic standings logic is aligned between admin/public output.

Remaining maintainability risks:

- standings and parts of display formatting are duplicated between two controllers; parity tests are important until a small domain service is justified;
- `TournamentController` is large and carries many related workflows. Further extraction should be driven by tests and concrete change pressure, not a framework rewrite;
- route registration is manual and does not produce 405 responses;
- several copy-to-clipboard helpers do not surface browser permission/fallback failure;
- database constraints do not encode every tenant relationship (for example composite tournament/team foreign keys), so model predicates remain a critical control;
- duplicate team-name behavior is not explicitly documented or constrained.

## 7. Dependency and supply-chain findings

- No `composer.json`, `composer.lock`, `package.json`, npm lockfile, vendored dependency tree, or Node build step exists; Composer/npm audit commands are therefore not applicable.
- Bootstrap is the only executable/style framework dependency and is pinned to 5.3.8 with official SRI hashes. Official source: <https://getbootstrap.com/docs/5.3/getting-started/introduction/>.
- jsDelivr availability is still a runtime dependency. Self-hosting the pinned Bootstrap assets would reduce CDN/CSP exposure without introducing a build tool.
- Public QR images use `https://api.qrserver.com`; Google Maps embeds are optional. Their availability, privacy terms, and production acceptability need an owner decision.
- Local validation used PHP 8.3.30. PHP 8.3 is in security-fixes-only support through 2027-12-31; production should use a currently supported, fully patched PHP branch. Official lifecycle: <https://www.php.net/supported-versions.php>.
- Local validation used MySQL 8.4.3. MySQL 8.4 is an LTS line, but production's exact server/patch level was inaccessible. Official release model: <https://dev.mysql.com/doc/refman/8.4/en/mysql-releases.html>.

## 8. Production-server checks still outstanding

These cannot be truthfully marked fixed without production access:

1. Take a database and upload backup; perform a restore drill before applying migrations.
2. Confirm the production document root is `public/`. If the fallback is unavoidable, run the documented deny/allow request matrix on the real vhost.
3. Set `APP_ENV=prod`, an exact `APP_URL`, `APP_BASE_PATH` if needed, and a valid timezone. Configure `APP_SETUP_TOKEN` only for first setup, then remove it.
4. Apply migrations with a temporary schema-change identity during maintenance. Verify versions `20260724_000009` through `20260724_000013`, all 28 step records, `matches.lock_version`, `tournaments.state_version`, indexes, and `login_attempts`. Current PHP must not be deployed before `000013`.
5. Use a separate runtime DB identity limited to this database and necessary DML; deny global/file/schema administration.
6. Enforce the one accepted `Host`, redirect HTTP to HTTPS, and add HSTS only after HTTPS/subdomain impact is verified.
7. If TLS terminates at a proxy, set `APP_TRUST_PROXY=true` only after ensuring clients cannot reach the origin and the proxy overwrites forwarding headers.
8. Verify PHP is a supported, fully patched 8.x release with PDO MySQL, mbstring, fileinfo, GD/image support, JSON, and secure session storage/garbage collection.
9. Verify MySQL/MariaDB compatibility, strict SQL mode, charset/collation, timezone, connection limits, and backup retention.
10. Route PHP/security events to protected, rotated logs; alert on setup, rate limits, repeated auth failures, privileged credential changes, migration failures, and 5xx spikes. Ensure logs are not web-accessible.
11. Make only `public/uploads/` writable; deny execution and alternate MIME handlers at the actual web server/CDN; test upload size limits at PHP and proxy layers.
12. Verify security/cache headers after any CDN/proxy and test that authenticated HTML is never cached.
13. Decide whether the QR/Google/CDN external services meet privacy and availability requirements.
14. Add uptime/health monitoring and a documented event-day rollback procedure. There is no dedicated health endpoint.

## 9. Test and validation results

The final validation set includes:

- initial `git status`: clean; work performed on `review/security-hardening`;
- repository-wide PHP syntax lint;
- EN/CS translation JSON parsing, 446/446 key parity, and referenced-key scan;
- `git diff --check`;
- tracked/untracked secret/artifact scan;
- MySQL 8.4 clean migrations: 13 versions and 28 complete steps;
- second migration run: `Applied: 0`;
- legacy version-only migration backfill, integrity-corruption checks, unknown-version rejection, and interrupted-`running` refusal/explicit recovery;
- Apache 2.4.66 syntax plus both document-root request matrices;
- dependency-free HTTP/MySQL suite: 208 passing assertions covering setup/schema readiness, legacy migration-state upgrade/rollback refusal, auth/session rotation and expiry, eight-process login-throttle contention, authorization/tenant isolation, CSRF, injection-shaped input, XSS escaping, boundary validation, standings/bracket semantics, optimistic scoring/generation/forms, stale tournament deletion, destructive-reset transactions, Public View/logo races, and managed-logo deletion.

The test command is documented in `README.md`, and `tests/run.php` states and enforces its prerequisites. The production server, browser console under real CDN/proxy conditions, mail/monitoring systems, and production data were not accessible and were not tested.

## 10. Residual risks

- Applying schema and authentication changes to production carries normal migration/session invalidation risk; back up and stage first.
- The persistent login throttle is per IP and scope. NAT false positives and distributed guessing remain possible; add edge rate limiting and alerting.
- Migration recovery deliberately requires human inspection after an ambiguous interrupted DDL step.
- MySQL and the local upload filesystem do not share a transaction; a host crash during the narrow logo replacement window can leave an unreferenced managed file.
- The CSP permits inline code until a nonce/static-asset refactor is tested.
- Apache rules do not configure Nginx/IIS/CDN behavior; equivalent deny and upload rules are required there.
- Model-level tenant predicates are tested, but not all relationships are backed by composite tenant foreign keys.
- No CI workflow currently runs the new suite automatically.
- External CDN, QR, and map services remain operational/privacy dependencies.
- Production backup, monitoring, patch levels, TLS/HSTS, accepted hosts, proxy topology, and least privileges remain unverified.

## 11. Prioritized next steps

1. Review this diff and run `tests/run.php` against a staging clone with the production PHP/MySQL/MariaDB versions.
2. Back up and restore-test, then apply migrations `000009`–`000013` in a maintenance window before deploying the reviewed PHP.
3. Configure canonical host/TLS/proxy rules and rerun the HTTP/Apache checklist.
4. Rotate/invalidate existing tournament-admin sessions by deploying the credential-fingerprint behavior and communicate the one-time re-login.
5. Connect structured security/error logs to protected retention and alerts.
6. Add the lint/integration suite to CI with a disposable MySQL service.
7. Plan a smaller follow-up for CSP nonces/self-hosted assets, composite tenant constraints, and standings-domain deduplication.
