# Mosaiq Development Roadmap

Mosaiq is a real-time collaborative whiteboard: a shared canvas of sticky notes, shapes, connectors and live cursors, with a time-travel replay of how a board was built.

This roadmap covers only that product. Nothing here is claimed as production-ready. A phase is **IMPLEMENTED** only when the acceptance checks below have been run and recorded; otherwise it is **PARTIALLY IMPLEMENTED**, **PREPARED**, **PLANNED**, **BLOCKED** or **NOT VERIFIED**.

## Audit addendum (2026-11-15)

A full audit and repair pass is recorded in [`docs/technical-audit.md`](docs/technical-audit.md)
and [`docs/repair-report.md`](docs/repair-report.md). It corrected the previously documented
root commands and added the missing gates:

| Check | Result |
| --- | --- |
| Root `pnpm run lint` / `build` / `test` | **PASS** — 7 files, 11 frontend tests |
| `composer validate --no-check-publish` | **PASS** |
| `composer audit --locked` | **PASS** — no advisories after Symfony 7.4.23 patch update |
| `composer analyse` (PHPStan level 5, src + tests) | **PASS** — 0 errors |
| `composer cs:check` | **PASS** — 0 of 54 files need fixing (`migrations/` excluded) |
| `composer test` | **PASS** — 41 tests, 263 assertions after Sprint 0 |
| `doctrine:migrations:migrate` | **PASS** — `Version20261115090000` applied (workspace name index) |
| Fresh-database migration sequence | **PASS** — 5 migrations / 55 statements on a disposable database |
| `doctrine:schema:validate` | **PASS** — including a fresh migrated database |
| `docker compose config --quiet` | **PASS** |
| `.github/workflows/ci.yml` | **FAILED ON GITHUB** — run `43715665395`: frontend passed, backend failed at `composer install` because the lock required PHP 8.4. Sprint 0 pinned resolution to PHP 8.3 and re-verified the lock; a GitHub re-run is pending |

Phase 7 below is **PARTIALLY IMPLEMENTED** for the SSE transport only. The planned Node +
Yjs/Hocuspocus service is **PLANNED** and remains the recorded future architecture.

## Sprint 0 — stabilization (2026-11-15)

Full record: [`docs/sprints/sprint-0-report.md`](docs/sprints/sprint-0-report.md).

- **CI root cause:** the lock was resolved on PHP 8.5 without a platform pin, selecting
  `doctrine/collections 3.1.0` and `doctrine/instantiator 2.1.0` (both PHP ^8.4) while CI runs 8.3.
  `backend/composer.json` now pins `config.platform.php` to `8.3.0`; the lock resolves
  `collections 2.6.0` / `instantiator 2.0.0`, and `composer why-not php 8.3.0` reports no blocker.
  CI also runs `composer check-platform-reqs`, creates the `_test`-suffixed database, runs the
  migrations and performs full schema validation; the test harness now resets only `_test`
  databases and works after migrations.
- **Security fix:** `GET /api/workspaces/{id}` no longer returns every board in the workspace.
  It filters through `BoardRepository::findAccessibleInWorkspace()` (board membership or workspace
  administrator, the same rule as `/api/boards` and `BoardVoter`), and `boardCount` counts only
  those boards. Seven regression tests cover owner, administrator, ordinary member, outsider,
  identifier swapping, nested leakage and direct-endpoint consistency.
- **Tests:** 41 backend tests / 263 assertions; 7 frontend files / 11 jsdom tests; PHPStan level 5
  clean; PHP-CS-Fixer 0/54.
- **Unverified:** the repaired workflow has not been re-run on GitHub (no push performed), and no
  real-browser E2E was added.

## Sprint 1 — authentication and security hardening (2026-11-15)

Full record: [`docs/sprints/sprint-1-report.md`](docs/sprints/sprint-1-report.md).

- **Token lifecycle:** opaque 48-byte bearer tokens remain SHA-256-hashed at rest. They now expire
  after `AUTH_TOKEN_TTL` (default 7 days), rotate on login (still one active session per user) and
  are revoked on logout. The authenticator rejects missing, expired and revoked tokens across all
  protected endpoints. Existing tokens are invalid after the migration because their expiry is null.
- **Passwords:** registration and login use Symfony's configured `UserPasswordHasherInterface`; the
  shared policy requires 12–72 bytes with at least one letter and one digit. There is no
  breach-list check or password reset.
- **Throttling:** login is limited per email + IP and per IP; registration is limited per IP.
  Limits are env-configured; exhaustion returns `429` with `Retry-After`; a successful login clears
  the failures. State lives in the additive `auth_attempts` table, not Redis.
- **Browser behaviour:** an authenticated 401 clears `sessionStorage` and redirects to
  `/sign-in?expired=1`, where FR/EN/AR explain the expiry. Login/registration 401s are treated as
  credential errors, not session expiry. Logout clears local state even when the API is unreachable.
- **Headers:** `/api` responses set `nosniff`, `no-referrer` and `DENY`; `/api/auth` adds
  `Cache-Control: no-store`.
- **Migration:** `Version20261115120000` adds `users.api_token_expires_at` and `auth_attempts`;
  verified up/down/up on a fresh database and applied additively to the dev database (4 boards
  preserved). Six migrations now exist.
- **Tests:** 51 backend tests / 325 assertions (10 new security tests / 62 assertions); 8 frontend
  files / 17 tests (6 new session and validation tests); PHPStan level 5 clean; PHP-CS-Fixer 0/60;
  PHP 8.3 parse clean.
- **Explicitly not delivered:** email verification, password reset/change, multi-device sessions,
  CSP, HttpOnly cookies/CSRF, breach-list checks and distributed throttling. Email verification and
  password reset are blocked on an approved product workflow; the rest are recorded in the Sprint 1
  report and audit addendum.
- **Unverified:** no GitHub Actions re-run (no push) and no real-browser pass of the expired-session
  redirect. The jsdom tests cover the route and message.

## Sprint 2 — workspace administration (2026-11-15)

Full record: [`docs/sprints/sprint-2-report.md`](docs/sprints/sprint-2-report.md).

- Membership is persisted on `User` as active/removed with `membership_updated_at`; removal revokes
  the token and removes board memberships. There is still one workspace per account and one Owner.
- Owner/Admin/Member permissions are centralized in `WorkspaceMembershipPolicy`; owner removal,
  self-role changes and owner grants are denied. Workspace owners/admins retain the pre-existing
  explicit board-management bypass.
- Workspace invitations are hashed, email-bound, seven-day, revocable and accepted during
  registration for new accounts. Resend/decline, ownership transfer, self-leave, removed-account
  re-invitation and a durable audit table remain **PLANNED/BLOCKED**.
- Workspace settings, member list, role changes, removal, invitations and name updates use the real
  API; FR/EN/AR and RTL are preserved. Migration `Version20261115180000` is additive and applied.
- Verified at completion: 69 backend tests / 437 assertions, 9 frontend files / 24 tests, PHPStan and
  CS-Fixer clean, build/lint pass.

## Sprint 3 — board lifecycle (2026-11-15)

Full record: [`docs/sprints/sprint-3-report.md`](docs/sprints/sprint-3-report.md).

- Boards now have one persisted state: `active`, `archived` or `trashed`, with nullable
  `archived_at`/`trashed_at`. Active, archive and trash listings are separate backend queries.
- Archive/restore/trash/restore use `BOARD_MANAGE` (board Owner/Admin or workspace administrator).
  Archived boards are view-only; trashed content and public reads are denied until restore.
- The dashboard loads the selected lifecycle view from the API, confirms archive/trash, restores
  from cards, and shows loading, empty and error/retry states. The old local-only trash and the
  invented 30-day retention copy were removed.
- Permanent deletion and retention are **PLANNED**: no approved policy exists, so trashed boards are
  retained indefinitely and there is no purge endpoint. Durable audit logging is also **PLANNED**;
  lifecycle actions currently use the application logger.
- Migration `Version20261115190000` is additive; eight migrations were verified on a fresh database
  with up/down/up of the latest, and dev/test schema validation passes. Backend: 75 tests / 532
  assertions. Frontend: 10 files / 28 tests. PHPStan, CS-Fixer, lint and production build pass.

## Sprint 4 — correctness and security stabilization (2026-11-17)

Full record: [`docs/sprints/sprint-4-report.md`](docs/sprints/sprint-4-report.md).

- **Public canvas lifecycle (security):** the anonymous fallback of
  `GET /api/boards/{id}/elements` now requires `hasPublicAccess()` **and** `isActive()`, matching
  `BoardApiController::show()`/`timeline()`. Archived and trashed public boards expose no canvas
  anonymously; active public boards and authenticated member access are unchanged.
- **JSON payload contract:** `JsonPayloadTrait` decodes without associative mode and rejects every
  top-level value that is not a JSON object (`[]`, `["unexpected"]`, `"text"`, `123`, `null` →
  `422`; malformed → `400`), then normalises objects to an associative array so no caller contract
  changes.
- **Canvas/timeline atomicity:** element create, update and delete run inside one
  `wrapInTransaction` with their timeline snapshot. Validation is hoisted before the transaction
  because `wrapInTransaction` closes the entity manager when the closure throws.
  `CanvasTimelineConsistencyTest` proves real rollback: a failing timeline insert leaves zero canvas
  elements and zero timeline rows.
- **Note persistence (frontend):** notes carry `saveState` (`saving`/`saved`/`failed`), a failed save
  shows a retry action that reuses the same local id (no duplicate `POST`), and polling/stream
  reconciliation goes through `mergeSyncedNotes()` so pending and failed notes survive refresh and
  persisted notes are not duplicated.
- **Privacy:** presence, canvas element authors, comment authors, timeline authors and the anonymous
  public payload use a board-safe `collaborator()` profile (id, name, role, accent); the anonymous
  payload exposes the display name only. Member-directory profiles keep email/city because the board
  share dialog and workspace settings already render them for the same audience.
- **Known limitation:** board create/rename in `BoardApiController` still use two flushes without a
  shared transaction. They were outside the canvas scope and are documented, not silently changed.
- Verified at completion: 95 backend tests / 639 assertions; 11 frontend files / 35 tests (serial and
  parallel); PHPStan, PHP-CS-Fixer (0/67), `composer validate`/`audit`/`check-platform-reqs`,
  `lint:container`, eight migrations on a fresh isolated database plus schema validation, ESLint and
  the production build all pass. GitHub Actions status for the branch is recorded in the report.

## Verified evidence (2026-11-14)

| Check | Result |
| --- | --- |
| `cd frontend && pnpm run lint` | **PASS** — ESLint exits 0 on 2026-11-14 |
| `cd frontend && pnpm run build` | **PASS** — `tsc -b` plus Vite 8.3.4; 2207 modules transformed into `index` 138.00 kB, `ui` 162.99 kB and `vendor` 247.30 kB. **Zero chunk-size warnings** |
| `cd frontend && pnpm test` | **PASS (re-run 2026-11-15)** — 7 files, 11 tests: theme modes, FR/EN/AR RTL, canvas mapping, invitation contract, signed-out public board, live/offline SSE badge and the jsdom sign-in → editor → replay → sign-out scenario |
| `cd backend && php bin/phpunit` | **PASS (re-run 2026-11-15)** — 41 tests, 263 assertions on PHP 8.5.12, including SSE framing, mailer assertions and workspace authorization |
| `cd backend && php bin/console lint:container` | **PASS** — all services injected with compatible types |
| `cd backend && php bin/console doctrine:schema:validate` | **PASS** — mapping correct and local database schema in sync |
| `cd backend && composer validate --no-check-publish` | **PASS** — `./composer.json is valid` |
| `cd backend && php bin/console doctrine:mapping:info` | **PASS** — 10 mapped entities (including `BoardInvitation`) |
| `cd backend && php bin/console doctrine:migrations:migrate --no-interaction` | **PASS** — existing `Version20261113180000` preserved; additive `Version20261114140000` applied (1 migration, 5 SQL queries). Local DB volume not reset |
| `cd backend && php bin/console app:fixtures:load` | **PASS** — created 2 workspaces, 5 users, 4 boards, 19 elements, 3 threads, 4 messages, 5 timeline entries; a second run creates 0 rows |
| `docker compose up -d` | **PASS** — `mosaiq-db` healthy, `mosaiq-redis` and `mosaiq-mailpit` running |
| API smoke test | **PASS** — `GET /api/boards` 200, `GET /api/workspaces` 200, `GET /api/boards/{id}/elements` 200, `POST /api/boards/{id}/star` 200; unknown element `DELETE` 404 |
| Browser check (2026-11-13) | **PASS** — dashboard rendered 4 API boards with resolved member avatars; the editor loaded 5 persisted notes and the API title; creating a note with the note tool persisted it (8 → 9 elements, color changed to mint via the canvas chips), inline text editing persisted ("Note validée en atelier"), and deleting the test note restored 8 elements; replay started at 0 visible notes and advanced when played. No console errors were reported |
| `cd frontend && pnpm test` | **PASS** — Vitest 3.2.7 with jsdom 26.1.0 |
| `docker compose up -d` | **PASS** — existing Mosaiq DB/Redis/Mailpit preserved and running; database volume not reset |
| API readiness | **PASS** — current Symfony server on port 8001 returns 401 for unauthenticated `/api/auth/me` and `/api/boards` |
| Vite readiness | **PASS** — exact root `npm start` returns HTTP 200 at `http://localhost:5176/`; default ports 5173–5175 were already occupied |
| Development CORS | **PASS** — OPTIONS from `http://localhost:5176` returns 204 with the matching allow-origin and authorization/content-type headers |
| `symfony serve` at required port 8000 | **BLOCKED** — port 8000 is occupied by another project; Mosaiq Symfony server is already running on 8001. No other project was changed |
| Repository state | Branch `main`, HEAD `a1cd2f6`, worktree intentionally uncommitted |

### Finalization checks (2026-11-14)

| Check | Result |
| --- | --- |
| `cd backend && composer require symfony/mailer` | **PASS** — `symfony/mailer` v7.4.22, `symfony/mime` v7.4.22 installed; `framework.mailer.dsn='%env(MAILER_DSN)%'` |
| Mailpit delivery | **PASS** — live invitation through the running API returned `emailSent=true`; Mailpit API reported subject `Mosaiq invitation · Brainstorming Sprint 12` to `mailpit-check-…@example.test` |
| SSE live endpoint | **PASS** — `GET /api/boards/{id}/stream` returned `text/event-stream`, `retry: 1000`, `event: ready`, `event: presence` and `event: canvas` with revision/element count |
| SSE dev-server concurrency | **PASS after change** — a 5-second multi-tick stream blocked a parallel board GET for 4.45 s; the shipped one-frame stream reduced it to 0.71 s while the client reconnects every 500 ms |
| Real-browser sign-in and badge | **PASS** — demo account reached the dashboard with 3 API boards, opened `Brainstorming Sprint 12`, and the editor displayed **En direct** after a clean reload |
| Signed-out public `/board` route | **PASS** — with `publicAccess` temporarily enabled, `/board/{id}` rendered the sanitized 5-note canvas, badge `Lecture seule (Accès public)` and CTA `Se connecter pour modifier` without a sign-in redirect; the flag was restored afterwards |
| GitHub Actions workflow | **PASS (syntax)** — `.github/workflows/ci.yml` parses with jobs `frontend,backend`; it has **not been executed on GitHub** |
| E2E routing bug found and fixed | **PASS** — `AppRoutes` now passes `useLocation()` into `<Routes>`; previously a successful login reused the stale “redirect to sign-in” element |

### Public sharing and invitations (2026-11-14)

| Check | Result |
| --- | --- |
| Anonymous public board read | **PASS (API)** — functional test asserts a public board returns title, note colors and canvas elements with author names only; members, comment threads, timeline and author emails are omitted |
| Anonymous public write protection | **PASS (API)** — unauthenticated `PATCH /api/boards/{id}` returns 401; board and element writes stay voter-protected |
| Public access off | **PASS (API)** — anonymous canvas read returns 403 after `publicAccess` is disabled |
| Invitation creation | **PASS (API)** — owner/admin creates a 7-day invitation; only the SHA-256 token hash is stored, asserted against the database |
| Invitation acceptance | **PASS (API)** — matching new invitee registers into the board workspace with a non-admin workspace role and the invited board role; existing account accepts once; reuse and expiry return 404; mismatched email and other workspace return 403/422 |
| Signed-out SPA route | **PASS** — unauthenticated `/board/:boardId` fetches only the sanitized public payload when `publicAccess` is enabled and renders it read-only with `Lecture seule (Accès public)` + `Se connecter pour modifier`; `/share/:boardId` remains an alias |
| Email delivery | **PASS** — branded invitation received in Mailpit at `http://localhost:8025`; the share dialog also copies a one-time `/sign-in?invite=…` link |

Environment: Node 22.13.0, pnpm 12.14.0, TypeScript 5.9.3, PHP 8.5.12, Composer 2.10.0, PostgreSQL 16 in Docker.

## Phase overview

| Phase | Status | Goal |
| --- | --- | --- |
| 0. Environment and repository readiness | **IMPLEMENTED** | Toolchain, Docker services and build verified |
| 1. Domain model and API contract | **IMPLEMENTED** | Whiteboard entities and HTTP contract implemented |
| 2. Backend persistence and migrations | **IMPLEMENTED** | PostgreSQL schema, six migrations and synthetic fixtures verified, including a fresh database |
| 3. Client shell and design system | **IMPLEMENTED** | Tokens, primitives, eight screens and FR/EN/AR shell |
| 4. Board editor interactions | **IMPLEMENTED** | Create, drag, edit and recolor sticky notes locally |
| 5. API resources and client bridge | **IMPLEMENTED** | Symfony JSON API plus typed client with offline fallback |
| 6. Authentication and board authorization | **IMPLEMENTED** | Expiring hashed tokens, rotation/revocation, password policy, credential throttling, 401 handling, workspace/board voters; email verification/reset remain BLOCKED |
| 7. Real-time collaboration service (SSE transport only) | **PARTIALLY IMPLEMENTED** | SSE presence/canvas stream with reconnect, three-state connection badge and REST fallback are verified; last-write-wins, no CRDT. The Node/Yjs service is **PLANNED** |
| 8. History, replay and restore | **IMPLEMENTED** | Canvas mutations record durable snapshots; replay reads snapshots and restore replaces board elements transactionally |
| 9. Comments and sharing workflows | **IMPLEMENTED** | Server-backed threads, roles, hashed single-use invitations, Mailpit email delivery and a read-only `/board/:boardId` view (`/share` alias) |
| 10. Quality and verification | **PARTIALLY IMPLEMENTED** | Lint, zero-warning build, Vitest, PHPUnit, PHPStan, CS-Fixer, container/schema checks pass locally; the repaired GitHub Actions workflow has not been re-run, and the jsdom scenario is not a real-browser E2E |
| 11. Workspace administration and board lifecycle | **IMPLEMENTED (reversible)** | Persisted workspace members/roles/invitations/settings and active/archive/trash board states; permanent deletion and retention are **PLANNED** |

## Out of scope

- Invoicing, billing, subscriptions, payments, tenant isolation policies and RLS.
- Production deployment, hosted environments and release publishing.
- Replacing the brand palette or adding a second accent color.
- Mobile board editing (the editor is desktop/tablet only by design).
- Any feature that requires a dependency or architecture change before the owner approves it.

## Target domain model

The canonical client shapes live in `frontend/src/mocks/types.ts`; the API wire shapes live in `frontend/src/api/client.ts` and mirror the PHP serializers.

- **User** — name, email, workspace role, city, presence accent.
- **Workspace** — name, plan label, seats, renewal date; members and boards.
- **Board** — title, starred flag, template flag, note-color palette, last update; members; canvas elements; comment threads; timeline entries.
- **Canvas element** — note, shape, connector or frame; position/size/rotation in the logical canvas space; content, color and metadata.
- **Comment thread / message** — canvas anchor, resolved flag, author and timestamp per message.
- **Timeline entry** — author, time, label, kind, and the elements revealed at that step.
- **Presence** — ephemeral cursor position and user identity; never stored as authoritative board data.

Logical canvas coordinates are fixed at 1400 × 880 with a zoom transform; persistence stores logical values, not screen pixels.

---

## Phase 0 — Environment and repository readiness

### Status: IMPLEMENTED (2026-11-13)

- Node 22.13.0 and pnpm 12.14.0 build the frontend from the lockfile.
- PHP 8.5.12 and Composer 2.10.0 run Symfony, Doctrine migrations and PHPUnit.
- Docker Compose starts project-named services `mosaiq-db`, `mosaiq-redis` and `mosaiq-mailpit`; `mosaiq-db` reports healthy.
- `backend/.env.local` holds the local-only PostgreSQL URL; tracked files contain documented defaults only.

---

## Phase 1 — Domain model and API contract

### Status: IMPLEMENTED (2026-11-13)

- Ten Doctrine mappings under `backend/src/Entity/` with UUID identifiers, invariant-checking constructors and domain methods: `Workspace`, `User`, `Board`, `BoardInvitation`, `BoardMembership`, `BoardPresence`, `CanvasElement`, `CommentThread`, `CommentMessage`, `TimelineEntry`.
- Roles are `Owner`, `Admin`, `Editor`, `Commenter`, `Viewer`; `BoardVoter` and `WorkspaceVoter` encode view/edit/comment/share and administer rules.
- Development JSON endpoints under `/api/workspaces`, `/api/boards`, `/api/boards/{id}`, `/api/boards/{id}/elements` and `/api/elements/{id}`; errors return `{ "error": "..." }` with 400/404/422.
- Timestamps are stored as `datetimetz_immutable` and serialized as ISO-8601; canvas colors are limited to the five board tokens; connector endpoints live in element `metadata`.

**Deliberate departure:** an OpenAPI document and generated client are not produced yet. Wire types are hand-written in `frontend/src/api/client.ts` and must stay aligned with the PHP serializers.

---

## Phase 2 — Backend persistence and migrations

### Status: IMPLEMENTED (2026-11-13)

- Migration `backend/migrations/Version20261113180000.php` creates workspaces, users, boards, board membership, canvas elements, comment threads/messages and timeline entries with unique email/workspace name, indexes, JSON columns and cascading foreign keys.
- Additive migrations add auth credentials, board roles and public access (`Version20261114120000`), board-role backfill (`Version20261114130000`), `board_invitations` (`Version20261114140000`), the duplicate-workspace-name index (`Version20261115090000`), token expiry plus `auth_attempts` (`Version20261115120000`), workspace membership/invitations (`Version20261115180000`) and board lifecycle (`Version20261115190000`); all were applied to the preserved local database without resetting it.
- The full sequence was re-run on a fresh disposable PostgreSQL database in Sprint 1: six migrations with up/down/up of the newest one, followed by a full `doctrine:schema:validate` pass. The CI-style `mosaiq_test` database was likewise recreated, migrated and validated before the 51-test suite.
- Synthetic fixtures and `app:fixtures:load` upsert realistic Tunisian data; the guard only permits a loopback PostgreSQL database named `mosaiq` (or containing `test`/`dev`/`fixture`) outside `prod`.
- Seeding is idempotent: the second run reports 0 created rows for every record type.

**Local commands (only against a disposable local database):**

```sh
cd backend
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:fixtures:load
```

**Do-not:** never reset the Compose Postgres volume, and never point the loader at a shared or production database.

---

## Phase 3 — Client shell and design system

### Status: IMPLEMENTED (2026-11-13)

- Tailwind CSS 4 with a single token file (`frontend/src/theme/tokens.css`) for neutral layers, primary `#6D4AFF` / `#8B73FF`, statuses, note colors and cursor colors.
- Light / Dark / System provider with persistence and a pre-paint theme script.
- Reusable primitives: button, inputs and fields, card, badge, avatar and stack, switch, dialog, dropdown menu, tooltip, segmented control, skeleton, empty state, toast.
- All eight screens from `docs/ui-spec.md` are implemented and navigable: auth, dashboard, board editor, time travel, share dialog, comments, command palette and workspace settings.
- FR/EN/AR dictionaries in `frontend/src/i18n/`, French default, with a language selector and light/dark/system toggle in the dashboard, editor, replay and sign-in headers. Arabic sets `document.documentElement.dir = 'rtl'`; French and English stay LTR.
- TypeScript was aligned to 5.9.3 so the existing `typescript-eslint` stack works; two effects that synchronously reset state were refactored instead of suppressing rules.

**Gap left for later:** screen-reader and tablet-width passes, plus loading/error states for data lists.

---

## Phase 4 — Board editor interactions

### Status: IMPLEMENTED (2026-11-13)

- Tool selection with active state, zoom in/out and “fit to screen” calculated from the available canvas area.
- Clicking the canvas with the note tool creates a sticky note at the click coordinates using the active color.
- Notes can be dragged with pointer events (logical 1400 × 880 coordinates, clamped to the canvas) and moved through the API when they exist on the server.
- Double-clicking a note edits its text inline; Enter or blur commits, and typing updates local state.
- The properties panel and canvas color chips change the selected note's fill in real time.
- Empty-canvas clicks clear the selection; comments pins, minimap, layer order and comment threads still work.
- Time travel starts from step 0, progressively reveals notes as the scrubber moves, shows the frame only after its timeline entry, and plays/pauses at the selected speed.

**Still future:** delete, shapes, connectors, frames, pen and image tools, undo/redo, multi-select.

**Verified in the browser:** created a note at canvas coordinates (persisted through `POST /api/boards/{id}/elements`), changed its fill with the canvas color chips (persisted through `PATCH /api/elements/{id}`), edited its text inline (persisted), and deleted the verification note afterwards. Drag handlers are implemented with pointer capture but were not exercised by the automated browser check.

---

## Phase 5 — API resources and client bridge

### Status: IMPLEMENTED (2026-11-13)

- Symfony JSON controllers serve boards, workspaces and canvas elements; `POST /api/boards/{id}/star` toggles a favorite and `DELETE /api/elements/{id}` removes an element.
- `frontend/src/api/client.ts` implements `getWorkspaces`, `getBoards`, `createBoard`, `toggleStarBoard`, `getCanvasElements`, `createCanvasElement`, `updateCanvasElement` and `deleteCanvasElement` against `VITE_API_BASE_URL` (default `http://127.0.0.1:8000/api`).
- Requests time out after 2.5 s. Screens catch failures, log a gentle warning and keep the demo interactive with typed mock data.
- The dashboard loads boards on mount and the editor loads canvas elements and the board summary on mount; failed writes fall back to local state.

**Deliberate departure:** API Platform and OpenAPI type generation remain future work. The current bridge uses Symfony controllers and hand-written wire types; it is not a generated client.

---

## Phase 6 — Authentication and board authorization

### Status: IMPLEMENTED (2026-11-15, hardened in Sprint 1)

- Login/register/logout/me use opaque bearer tokens stored as SHA-256 hashes; tokens expire after `AUTH_TOKEN_TTL` (default 7 days), rotate on login and are revoked on logout. The browser keeps its session token in `sessionStorage` and attaches it to API calls.
- Registration and login use Symfony's configured password hasher; the shared policy requires 12–72 bytes with at least one letter and one digit.
- Login (per email + IP and per IP) and registration (per IP) are throttled through the additive `auth_attempts` table; `429` responses carry `Retry-After`, and successful login clears failures. Limits are environment-configured.
- An authenticated browser 401 clears the session and redirects to `/sign-in?expired=1` with FR/EN/AR messaging. Login/registration 401s remain credential errors.
- API responses add `nosniff`, `no-referrer`, `DENY` and auth `no-store` headers.
- Board and workspace voters enforce membership and role permissions; public board reads are limited to board/timeline/canvas GETs when `publicAccess` is enabled.
- Signup creates an isolated workspace, and the sign-in screen offers Amine Ben Salah / Ons Trabelsi fixture account quick-select buttons.
- Functional tests cover token issuance, expiry, rotation, revoked-token rejection, password policy/hashing, throttling, headers, invalid credentials, isolated registration, board role scoping and public read access.
- **Not covered:** email verification, password reset/change, multi-device sessions, CSP/cookie sessions; no live two-account browser sign-in. The backend suite is now 51 tests / 325 assertions.

---

## Phase 7 — Real-time collaboration service (SSE transport only)

### Status: PARTIALLY IMPLEMENTED (2026-11-15) — SSE transport verified; CRDT service PLANNED

- Authenticated `GET/POST /api/boards/{id}/presence` stores short-lived cursor/tool state and scopes it to board members.
- `GET /api/boards/{id}/stream` is an authenticated Server-Sent Events endpoint. Each request emits `ready`, `presence` and `canvas` frames and closes; the browser reconnects every 500 ms, which keeps the requested 500 ms cadence without blocking the single-process PHP dev server.
- The stream's canvas revision comes from the board's `updatedAt` plus element count; element create/update/delete touch the board, so connected editors refetch only when the revision changes.
- `frontend/src/api/client.ts` reads SSE with authenticated `fetch` streaming, keeping the bearer token out of the URL. The editor shows **En direct / Synchronisation… / Mode hors ligne**, keeps its 250 ms cursor write throttle and falls back to the 3-second poll.
- Functional tests cover authentication, board isolation, coordinates, stream content type and the actual `retry`/`ready`/`presence`/`canvas` frames. A real-browser check showed the **En direct** badge against the running API.
- **Limit:** this is SSE presence/canvas invalidation with last-write-wins. There is no Yjs/Hocuspocus CRDT, offline operation queue or conflict-free merge, and the real-browser check used one client rather than two. The short one-frame streams trade continuity for PHP dev-server responsiveness.

---

## Phase 8 — History, replay and restore

### Status: IMPLEMENTED (2026-11-14)

- Board creation/rename and canvas create/update/delete persist timeline rows with snapshots; deletion records the post-delete snapshot.
- Timeline GET returns chronological entries; replay reads stored snapshots at each scrubber step.
- Restore validates same-board snapshot ownership, transactionally replaces canvas elements, and records a restore event.
- PHPUnit functional cases cover recording, snapshot payload and restore. The full browser restore path was not clicked through.

---

## Phase 9 — Comments and sharing workflows

### Status: IMPLEMENTED (2026-11-14)

- Comment thread/list/create/reply/resolve endpoints enforce board comment roles and the editor panel uses them.
- Owners/admins can add existing same-workspace accounts, update board-specific roles, remove members, and toggle public read access; owner membership cannot be changed or removed.
- Invitations are persisted in `board_invitations` with a normalized email, board role, seven-day expiry and single-use acceptance timestamp. Only the SHA-256 token hash is stored; the raw opaque token is returned once to the inviter.
- New invitees register through `/api/auth/register` with `invitationToken`, landing in the board's workspace with a non-admin workspace role and the exact invited board role. Existing accounts sign in and accept through `POST /api/boards/invitations/{token}/accept`. Acceptance checks expiry, email equality and workspace membership.
- `symfony/mailer` sends a branded HTML/text invitation through `MAILER_DSN` (SMTP `127.0.0.1:1025` locally, Mailpit UI `:8025`). Both the token-invitation endpoint and the existing-member endpoint dispatch mail; delivery failure is logged and returned as `emailSent=false` instead of failing the invite.
- Verified live: the running API created an invitation, returned `emailSent=true`, and Mailpit received subject `Mosaiq invitation · Brainstorming Sprint 12` for the test recipient.
- The share dialog copies a `/sign-in?invite=…` link. When `publicAccess` is enabled, unauthenticated `/board/{boardId}` renders the sanitized read-only canvas with a `Lecture seule (Accès public)` badge and a `Se connecter pour modifier` CTA; `/share/{boardId}` remains an alias. Writes stay authenticated.
- Anonymous board reads use a dedicated sanitized serializer: no member list, comment threads, timeline snapshots or author emails.
- Functional coverage includes create/reply/resolve, membership/access behavior, sanitized public payloads, denied anonymous writes, token hashing, wrong-email rejection, expiry, reuse, cross-workspace rejection, invited-role assignment and mail dispatch.
- **Not re-checked visually:** a live invite-link signup/login acceptance; its API and request contract are covered by tests.

---

## Phase 10 — Quality and verification

### Status: PARTIALLY IMPLEMENTED (2026-11-15)

**Verified gates:**

```text
cd frontend && pnpm run lint   -> PASS
cd frontend && pnpm run build  -> PASS (0 chunk warnings)
cd frontend && pnpm test       -> PASS (8 files, 17 tests)
cd backend && php bin/phpunit  -> OK (51 tests, 325 assertions)
cd backend && php bin/console lint:container          -> PASS
cd backend && php bin/console doctrine:schema:validate -> PASS (database in sync)
docker compose up -d -> PASS (existing Mosaiq services, persistent DB volume preserved)
npm start -> HTTP 200 at localhost:5176 (5173–5175 occupied)
symfony serve -> existing Mosaiq server on 8001 returns 401 for protected API routes; port 8000 belongs to another project
mailpit delivery -> PASS (branded invitation received at localhost:8025)
SSE stream -> PASS (text/event-stream frames; editor badge showed En direct)
CI YAML -> PASS (jobs parse); GitHub run 43715665395 failed at composer install because the lock required PHP 8.4; Sprint 0 fixed the PHP 8.3 lock and the workflow, re-run pending
```

**Known gaps:** the GitHub Actions workflow has not been re-run on GitHub (run `43715665395` failed before Sprint 0's PHP 8.3 lock fix); no real-browser E2E, accessibility/performance automation, signed-out public-board visual pass, invite-link visual pass, expired-session visual pass, or two-client cursor verification. Email verification and password reset remain unimplemented; tokens stay in `sessionStorage` without a CSP; multi-device sessions and distributed throttling are not supported. Collaboration is SSE/last-write-wins, not Yjs/Hocuspocus conflict-free sync. The Vite build has zero size warnings.

---

## Cross-phase constraints

- Mock data remains the fallback when the API is unreachable; no screen may claim persistence, auth or collaboration beyond what the API provides.
- Shared contracts (document schema, sync messages, API types, migrations) have one owner; never parallel-edit overlapping files.
- No destructive migration, deployment, push or dependency/architecture change without owner approval.
- Keep every color in `frontend/src/theme/tokens.css`; components use semantic tokens only.
- Report exact commands and real output. Distinguish passing, failing, blocked and unrun checks.

## Governance notes

- Update this roadmap whenever implemented behaviour, setup, dependencies or verification evidence changes.
- Current deferred work: domain/OpenAPI docs, Yjs/Hocuspocus conflict-free sync, a remote CI run,
  two-client browser verification, email verification/password reset, multi-device sessions, and a
  CSP or HttpOnly-cookie session model.
