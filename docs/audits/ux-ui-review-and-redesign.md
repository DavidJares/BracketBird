# BracketBird UX/UI Review and Redesign

Date: 2026-08-07  
Branch: `review/ux-ui-redesign`  
Scope: setup, authentication, global administration, tournament administration, live scoring, public results, print outputs, empty/error states, responsive behavior, and accessibility.

## 1. Product and user-role overview

BracketBird is a small-tournament operations product, not only a bracket renderer. It supports the complete event lifecycle from first installation to public results and printed court sheets.

| Role | Primary goals | High-pressure tasks | Typical device and timing |
| --- | --- | --- | --- |
| Superadmin | Create, find, open, share, and remove tournaments; maintain global access | Open the correct event, copy an admin/public link, avoid deleting the wrong event | Desktop before the event; laptop or phone during the event |
| Tournament administrator | Configure an assigned tournament and run the event | Start the correct match, enter scores quickly, correct a result safely, identify incomplete matches, advance the knockout stage | Laptop, tablet, or phone before and during play |
| Public viewer | Understand what is happening without instruction | Find the current/next match, court, standings, and final result | Phone, venue display, projector, or shared link during and after the event |
| Team representative / participant | Consume public information | Confirm court/time, read standings, understand qualification state | Primarily phone during the event |
| Installer / first superadmin | Complete one-time application setup | Supply the protected setup token and create the first account | Desktop during deployment |

There is no participant account or self-service team role. Participants are read-only public users. Tournament administrators are scoped to one tournament through its slug and password; superadmins can administer every tournament.

## 2. Existing user journeys

The verified lifecycle is:

1. Apply database migrations from the CLI.
2. Open protected setup and create the first superadmin.
3. Sign in as superadmin.
4. Create a tournament; BracketBird generates its slug, groups, and default public-screen records.
5. Open the tournament as superadmin, or share its slug-based login with a tournament administrator.
6. Configure name, date, time, location, group/court counts, duration, advancement, match modes, and tournament password.
7. Add teams and descriptions; assign them manually or with balanced assignment.
8. Generate the group schedule. Regeneration can remove group and knockout data and therefore requires explicit confirmation.
9. Filter matches by group/court, start a match, enter set scores, and correct/reset results when authorized.
10. Review calculated standings and exact qualifiers.
11. Generate the seeded knockout stage; winners progress and dependent results are protected from accidental invalidation.
12. Configure public overview content, theme, rotation, logo, map, and screen order.
13. Use direct public overview, live/next, standings, schedule, knockout, results, or rotating display routes.
14. Open browser-printable schedules, group matrices, and knockout sheets.
15. Correct data or, as superadmin, delete the tournament with a stale-write check and explicit confirmation.

Additional verified behavior includes optimistic match locking, tournament-wide state-version checks, fixed-two-set draws in groups, fixed-two-set knockout tiebreaks by total points, byes, planned courts/times across knockout rounds, and read-only public access.

## 3. Current UX/UI findings

### Critical and high-impact findings

| Impact | Finding | Affected screens/workflows |
| --- | --- | --- |
| High | Tournament pages used the generic heading “Tournament detail” instead of the active tournament name. Long workflows lacked a strong, persistent answer to “which event am I editing?” | All tournament-admin routes and match detail |
| High | The main navigation mirrored implementation sections, not the tournament lifecycle. “Teams” duplicated much of “Teams & Groups,” while “Tournament” did not communicate that it was the overview/configuration starting point. | Tournament navigation, team setup |
| High | There was no readiness summary or recommended next action. New administrators had to infer whether to add teams, assign groups, generate matches, complete results, create knockout, or enable the public display. | Tournament overview and every setup handoff |
| High | The group-stage list exposed many controls and made the entire row/card clickable, but the score-entry action was not consistently visible. During a live event, operators had to distinguish “start,” “open detail,” and score correction from indirect affordances. | Group stage and score entry |
| High | The home route immediately redirected to superadmin login, making tournament-admin access undiscoverable unless the administrator already possessed the exact deep link. | First visit, tournament-admin sign-in |
| High | The setup and login pages inherited full administration chrome, making one-purpose authentication tasks feel like an empty application shell. | Setup, superadmin login, tournament-admin login |

### Medium-impact findings

| Impact | Finding | Affected screens/workflows |
| --- | --- | --- |
| Medium | The dashboard had no document-level `h1`; tournament pages repeated a generic `h1` above a more meaningful `h2`. | Dashboard and tournament pages |
| Medium | The combined teams/groups page rendered 41 forms and 67 buttons with the realistic 12-team test set. Editing and destructive actions competed visually with assignment, standings, and add-team work. | Teams & Groups |
| Medium | Status relied heavily on colored Bootstrap badges. Text labels were present, but the layout did not consistently group live, ready, and completed work. | Match lists, knockout, dashboard |
| Medium | Flash feedback was visually styled but did not distinguish assertive error announcements from polite success/status announcements. | All administrative POST flows |
| Medium | No skip link existed and focus did not move to validation feedback. | Keyboard and assistive-technology navigation |
| Medium | The dark theme was the administrative default. It was visually dramatic but less suitable than a light, high-contrast operational surface for long event-day sessions and outdoor tablets. | All administrative pages |
| Medium | Empty states usually described absence but did not always contain the next action in the same component. | Dashboard, teams, matches, public configuration |
| Medium | Public unavailable/no-screen states used generic centered Bootstrap cards and did not share the stronger public display identity. | Disabled and misconfigured public views |

### Lower-impact findings

- Several copy actions did not announce success or failure.
- The live clock updated every second even though the interface only displays minutes.
- Some route titles remained implementation-oriented (“detail”).
- Native `confirm()` is appropriate as a no-dependency safety fallback, but destructive controls need stronger surrounding copy and separation.
- The stylesheet had grown to roughly 5,600 lines and retained many page-specific class families. The visual layer was broadly themed but expensive to reason about and easy to drift.

## 4. Realistic-state inspection

The application was run on PHP 8.3.30 and MySQL 8.4.3 against a new isolated database named `bracketbird_ux_test`. The existing `futnet_tournament_tracker` database and production data were not modified.

Test content covered:

- three tournaments: empty, active, and completed/past;
- a very long tournament name and location;
- twelve teams, long team names, descriptions, and one unassigned team;
- three groups and three courts;
- scheduled, in-progress, finished, and pending matches;
- completed, partial, and empty set data;
- fixed-two-set group results and best-of-three knockout configuration;
- a multi-round knockout with completed, live, scheduled, and unresolved rounds;
- enabled and disabled public views;
- every public screen and the rotating display route;
- anonymous authorization redirects, missing routes, and post-setup unavailability.

All primary routes returned the expected HTTP response. With the realistic active tournament, the baseline rendered:

- 41 forms and 67 buttons on Teams & Groups;
- 25 forms on Group Stage;
- a generic `Tournament detail` document heading across every tournament section;
- no `h1` on the superadmin dashboard.

The requested browser automation surface was unavailable in this session, so screenshots, browser-console inspection, and computed-layout measurements could not be produced truthfully. Responsive behavior was inspected from the server-rendered markup and CSS breakpoints at the requested mobile/tablet/desktop ranges; live HTTP workflows were exercised independently of JavaScript.

## 5. Alternative design directions considered

### Direction A — Conventional tabbed administration

- Information architecture: global dashboard plus one flat set of tournament tabs.
- Navigation: horizontal tabs on desktop and scrollable tabs on mobile.
- Main layout: section heading followed by forms/tables.
- Score entry: separate match detail page.
- Mobile: stacked cards.
- Character: neutral Bootstrap-style business application.
- Advantages: smallest change and lowest implementation risk.
- Risks: preserves the weak lifecycle model, duplicated team destinations, and lack of operational guidance.

### Direction B — Dense event-day scoreboard console

- Information architecture: courts and live matches first; setup grouped into a secondary area.
- Navigation: operational left rail with a persistent court board.
- Main layout: high-density tables and status columns.
- Score entry: inline in the live board.
- Mobile: court-by-court feed.
- Character: broadcast-control-room interface.
- Advantages: fastest possible event-day scanning.
- Risks: intimidating before the event, harder to make safe without larger backend/API changes, and less compatible with the existing server-rendered flow.

### Direction C — Tournament Command Center (selected)

- Information architecture: global tournaments; within a tournament, Overview → Participants & Groups → Group Stage → Knockout → Public Display → Print Center.
- Navigation: persistent tournament identity plus lifecycle steps; compact horizontal step navigation on small screens.
- Main layout: tournament masthead, readiness/next action, then a focused task workspace.
- Score entry: explicit “enter score” destinations, prioritized live/ready work, and a keyboard/touch-optimized score form.
- Mobile: information is reordered into operational cards; primary actions remain visible and at least 44 px tall.
- Character: light operational canvas, deep navy structure, restrained orange sport accent, high-contrast state colors, and dense but calm data presentation.
- Advantages: materially improves learning, orientation, error prevention, event-day speed, and maintainability without changing the core architecture.
- Risks: lifecycle status is derived from existing data and cannot yet represent manual milestones such as check-in or awards.

Direction C was selected because it delivers most of Direction B’s live-event benefits while keeping setup understandable and preserving server-rendered forms, deep links, authorization, and shared-hosting compatibility.

## 6. New information architecture and navigation model

### Global level

- Welcome/sign-in choice
- Superadmin tournament dashboard
- Create tournament

### Tournament level

1. **Overview & setup** — identity, readiness, structure, match rules, access.
2. **Participants & groups** — roster, descriptions, assignment, group standings.
3. **Group stage** — generation, filters, live desk, score entry.
4. **Knockout** — readiness, generation, rounds, progression.
5. **Public display** — visibility, content, branding, screen order, direct display access.
6. **Print center** — operational schedules, matrices, and bracket sheets.

The separate Teams route remains functional as a legacy deep link but is removed from primary navigation because its capabilities are already available in Participants & Groups.

The tournament masthead shows the active tournament, event metadata, public/private state, administration scope, and one recommended next action. Section labels describe user goals rather than controller concepts.

## 7. Design-system principles and tokens

Principles:

- Operational clarity before decoration.
- One strong primary action per decision area.
- Status always includes text, not color alone.
- Compact data surfaces, generous control targets.
- A predictable panel/header/action-bar grammar across all sections.
- Light administration by default; optional dark mode and tournament-controlled public theme remain available.

Token groups are defined as CSS custom properties for:

- font family, weights, and a compact type scale;
- 4/8/12/16/24/32/48 px spacing rhythm;
- content and reading widths;
- neutral surfaces, borders, and text hierarchy;
- primary, live/warning, success, danger, and information states;
- 6/10/14 px corner radii;
- restrained elevation levels;
- 3 px focus rings;
- mobile, tablet, and desktop breakpoints;
- sticky header/navigation layers.

## 8. Accessibility improvements

The redesign targets WCAG 2.2 AA and includes:

- a visible-on-focus skip link;
- exactly one meaningful `h1` on primary screens;
- explicit navigation labels and `aria-current="page"`;
- polite success/status announcements and assertive error announcements;
- visible `:focus-visible` treatment across links, controls, summaries, and clickable match surfaces;
- 44 px minimum touch targets for primary interactive controls on narrow screens;
- field labels retained independently of placeholders;
- input modes and selection behavior suited to numeric score entry;
- a non-color live indicator and textual status labels;
- reduced-motion handling;
- responsive card alternatives for wide match tables and bracket rounds;
- retained server-rendered form behavior when JavaScript is unavailable.

Native confirmation dialogs remain as robust fallbacks for destructive actions. Existing CSRF, authorization, stale-write, optimistic-lock, and server-validation controls are unchanged.

## 9. Responsive strategy

- **360–390 px:** compact top bar, horizontally scrollable lifecycle navigation, single-column workspaces, full-width primary actions, score fields in a stable two-team grid, and round-based knockout cards.
- **768 px:** two-column summary metrics where useful; stacked setup rails; no dependence on hover.
- **1280–1440 px:** persistent lifecycle sidebar, paired setup/work areas, desktop match tables plus a dedicated live-work strip.
- **Wide desktop:** content remains bounded for readable forms while operational tables and bracket boards can use the available width.

Horizontal scrolling is reserved for bracket relationships and controlled navigation strips. All other primary workflows reflow.

## 10. Major implementation changes

- Replaced the post-setup root redirect with a role-oriented access page. Tournament administrators can enter a slug and reach the correct scoped login without knowing the full route; authenticated users still return directly to their workspace.
- Rebuilt setup and both login screens as focused, single-purpose flows with clear account scope, autofocus, complete labels, and a route back to access options.
- Made the light operational theme the default while preserving browser-local dark mode and tournament-controlled public themes.
- Replaced the generic tournament-detail header with a tournament masthead containing name, event metadata, public state, administration scope, and a recommended next action derived from existing data.
- Reorganized primary navigation into six lifecycle destinations. The redundant standalone Teams link was removed from primary navigation while its route remains functional and is represented as Participants & groups in lifecycle context.
- Added lifecycle progress indicators for participant assignment, group completion, knockout completion, and public visibility.
- Added an Overview & setup readiness panel covering participants, assignment, generated schedule, public display, and courts.
- Added an event-day live desk that surfaces every in-progress group match plus the next scheduled match on each visible court.
- Replaced implicit clickable match rows/cards with explicit Start, Open match, Enter score, and Review result controls. This removes nested-interactive and ambiguous click-target behavior.
- Added optional client-side status filters while preserving the full server-rendered match list without JavaScript.
- Moved destructive/regenerative stage generation behind native, keyboard-accessible disclosure once a stage already exists; server confirmations and stale-write checks are unchanged.
- Optimized the score form with numeric input modes, large stable fields, select-on-focus, Enter-to-next-field behavior, result-impact copy, and a reachable mobile submit bar.
- Added standings captions, scoped table headers, row headers, and concise tie-break explanations in both administrative and public views.
- Added skip links, meaningful page-level headings, `aria-current`, improved focus-visible styling, reduced-motion behavior, and assertive-versus-polite feedback announcements.
- Added branded 403/404 recovery pages. The global CSRF check still rejects the request before any state mutation and now renders a recoverable explanation.
- Preserved non-sensitive tournament-creation values after server validation failure while deliberately discarding the password.
- Reworked disabled/no-screen public states to use the public visual system.
- Added and documented reusable typography, spacing, radius, width, color, focus, status, and layering tokens without introducing a build step or frontend dependency.

## 11. Before-and-after evidence

Before evidence is recorded in the realistic-state inspection above. Browser screenshots could not be captured because no connected browser backend was available.

After implementation:

- every audited primary administration route rendered exactly one `main` and one meaningful `h1`;
- every audited public route rendered one `main`, one `h1`, and the skip link;
- lifecycle and public navigation exposed `aria-current="page"`;
- no duplicate HTML IDs were found across the dashboard, all tournament sections, the legacy Teams route, or group-match detail;
- tournament pages rendered the actual tournament name as the document heading instead of “Tournament detail”;
- group-stage rows/cards exposed visible action controls rather than relying on whole-row click behavior;
- the root slug lookup reached the expected tournament-scoped login, while invalid slugs rendered an inline labelled error;
- anonymous dashboard access still resolved to superadmin login and cross-tournament administration remained denied;
- both branded 403 and 404 responses retained their correct status codes.

## 12. Testing performed

- PHP syntax lint across every PHP file: passed.
- EN/CS JSON parsing and key parity: 532/532 keys, passed.
- Static translation-reference scan: passed (dynamic `match_status.*` keys intentionally resolved at runtime).
- CSS structural brace check: 1,012 opening / 1,012 closing, passed.
- `git diff --check`: passed; line-ending notices reflect the existing Windows checkout configuration.
- Local test-credential and setup-token scan of repository files: passed.
- Dependency-free MySQL/HTTP integration and security suite: 208/208 assertions passed.
- Major-route HTTP matrix: setup/login, dashboard, every tournament section, group and knockout score detail, all public screens, rotating display, public-disabled state, 403, 404, anonymous redirects, tournament-admin scope, and cross-tournament denial passed.
- DOM structure scan on primary admin routes: one `main`, one `h1`, no duplicate IDs, passed.
- Failed tournament creation: non-sensitive values preserved, password absent from the response, passed.
- JavaScript-independent coverage: server navigation, filters, forms, start/score/reset/generation flows, authorization, CSRF, and public routes remain functional through ordinary links/forms and the HTTP suite. Status filtering and score-field key advancement are optional enhancements.
- Realistic content checks before the automated suite used three tournaments, twelve participants, long content, three groups/courts, unassigned teams, all match states, partial/completed results, and a multi-round knockout.

Browser-console, computed-layout, screenshot, and physical keyboard/touch checks remain pending because the session had no connected graphical browser backend.

## 13. Remaining limitations

- There is no participant login, check-in, roster import, or self-service score submission.
- Lifecycle readiness is derived from existing records and does not include manual event milestones.
- Public QR codes and Bootstrap remain runtime third-party dependencies.
- A connected graphical browser is still required for final screenshot comparison, computed color-contrast verification, browser-console checks, and device-specific touch testing.
- The CSS retains legacy class names for backward-compatible view coverage; the new token and component layer normalizes their presentation.

## 14. Prioritized future improvements

1. Run the final browser/device matrix on staging and retain screenshot baselines.
2. Add a dedicated court-mode filter and “next unresolved match” shortcut backed by persisted operator preference.
3. Add CSV team import with preview, duplicate detection, and rollback-safe validation.
4. Add explicit tournament lifecycle/status fields if organizers need check-in, paused, completed, or archived states.
5. Add CI for PHP lint, translation parity, integration tests, and static accessibility checks.
6. Move remaining inline scripts to local static assets and adopt CSP nonces or hashes before removing `'unsafe-inline'`.
