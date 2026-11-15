# Mosaiq technical audit

**Audit date:** 2026-11-15
**Branch / HEAD:** `main` at `4f3689c` (`feat: finalize Mosaiq collaboration and release checks`)
**Working tree at audit start:** clean except untracked `NEXT-AGENT-PROMPT.md` (preserved, untouched by this work)
**Toolchain observed:** PHP 8.5.12 (ZTS, Windows), Composer 2.10.0, Node 22.13.0, pnpm 12.14.0, PostgreSQL 16 in Docker

This document records what the repository actually does, distinguished from what its
documentation claims. Nothing here is a claim that a repair has been made; repairs and
their verification are recorded in [`repair-report.md`](repair-report.md).

> **Sprint 0 update (2026-11-15).** The historical baseline below is kept as recorded at
> `4f3689c`; the repository has since moved to `5b993fa` plus Sprint 0 changes. Two findings were
> confirmed and fixed after this audit:
>
> - **CI backend failure (new, P0 for release confidence).** GitHub run `43715665395` failed at
>   `composer install`: the lock was resolved on PHP 8.5 without a platform pin and selected
>   `doctrine/collections 3.1.0` and `doctrine/instantiator 2.1.0`, both requiring PHP ^8.4,
>   while CI runs PHP 8.3. `composer.json` now pins `config.platform.php=8.3.0`; the lock resolves
>   `collections 2.6.0` / `instantiator 2.0.0`. CI also checks platform requirements, creates the
>   `_test`-suffixed database, executes the migration sequence before schema validation, and the
>   test harness now refuses to reset non-`_test` databases. See the Sprint 0 report.
> - **Workspace board disclosure (P1).** `GET /api/workspaces/{id}` returned every board in the
>   workspace after only a workspace-membership check, exposing private board ids, titles, member
>   e-mails and element counts. It now filters through the same membership/administrator query as
>   `/api/boards`, and `boardCount` counts only accessible boards. Seven regression tests cover
>   the owner, workspace administrator, ordinary member, outsider, identifier swapping, nested
>   leakage and direct-endpoint consistency.
>
> **Sprint 1 update (2026-11-15).** Authentication hardening is recorded in §8. Current gates:
> PHPUnit **51 tests / 325 assertions**, PHPStan level 5 clean, PHP-CS-Fixer **0/60**, frontend
> **8 files / 17 jsdom tests**, six migrations verified on fresh and test databases with schema in
> sync. The GitHub workflow has not been re-run because no push was performed.

Priority meaning:

| Priority | Meaning |
| --- | --- |
| **P0** | Data loss, cross-tenant access, or a broken release with no workaround. |
| **P1** | Users hit it in normal use, or the documented setup fails, or security is materially weakened. |
| **P2** | Incorrect behaviour in an edge path, missing quality gate, or notable usability/accessibility defect. |
| **P3** | Maintainability, documentation, or scalability concern with no current user impact. |

---

## 1. Baseline checks (recorded before any change)

| # | Command | Result |
| --- | --- | --- |
| 1 | `pnpm run lint` (repo root) | **FAIL** — `Unknown command: "lint"` |
| 2 | `pnpm run build` (repo root) | **FAIL** — same root script defect |
| 3 | `cd frontend && pnpm run lint` | **PASS** — ESLint exits 0 |
| 4 | `cd frontend && pnpm run build` | **PASS** — `tsc -b` + Vite 8; `index` 138.00 kB, `ui` 162.99 kB, `vendor` 247.30 kB |
| 5 | `cd frontend && pnpm test` | **PASS** — 7 files, 10 tests |
| 6 | `cd backend && php bin/phpunit` | **PASS** — 27 tests, 151 assertions (~65 s) |
| 7 | `cd backend && composer validate --no-check-publish` | **PASS** |
| 8 | `cd backend && php bin/console lint:container` | **PASS** |
| 9 | `cd backend && php bin/console doctrine:schema:validate` | **PASS** — mapping valid, local DB in sync |
| 10 | `cd backend && php vendor/bin/phpstan analyse` | **FAIL** — `At least one path must be specified to analyse` (no config, no paths, no script) |
| 11 | `cd backend && php vendor/bin/php-cs-fixer fix --dry-run --diff` | **FAIL** — exit 8, 20 of 55 files need formatting |
| 12 | `cd backend && php vendor/bin/phpstan analyse src tests --level 5` | **FAIL** — 17 errors (see Q-01) |
| 13 | `docker compose config --quiet` | **PASS** |
| 14 | `docker compose ps` | `mosaiq-db` healthy, `mosaiq-redis` running, `mosaiq-mailpit` running |

The direct frontend commands pass; only the root wrapper is broken. This is why the README's
"all checks pass" table is misleading rather than false: it records the direct commands but the
root commands it also documents do not work.

### Environment files

`.env`, `.env.dev` and `.env.test` are tracked. Contents were **not** printed during the audit.
Only variable names and the presence/length of `APP_SECRET` were inspected:

| File | Variables present (names only) | `APP_SECRET` |
| --- | --- | --- |
| `backend/.env` | `APP_ENV`, `APP_SECRET`, `APP_SHARE_DIR`, `DEFAULT_URI`, `DATABASE_URL`, `MAILER_DSN`, `APP_FRONTEND_URL` | empty (forces an environment-supplied value in production) |
| `backend/.env.dev` | `APP_SECRET` | 32 characters, development-only value |
| `backend/.env.test` | `KERNEL_CLASS`, `APP_SECRET`, `DATABASE_URL`, `MAILER_DSN` | 15 characters, test-only value |

There is no `.env.example`, so the documented variable set is only discoverable from `.env` and
the README. No production credential was found in tracked files; the local database credentials
are the ones defined by `docker-compose.yml`.

---

## 2. Repository structure

```
AGENTS.md                     engineering rules (SSE stated as current sync; Yjs/Hocuspocus incomplete)
README.md                     product + setup + verification table
DEVELOPMENT_ROADMAP.md        phase status
docs/ui-spec.md               UI source of truth — still says "no backend calls yet"
docker-compose.yml            Postgres 16, Redis 7, Mailpit (dev only)
.github/workflows/ci.yml      frontend + backend jobs (no PHPStan / CS-Fixer gate)
backend/                      Symfony 7.4 API, 10 Doctrine entities, 6 controllers, 9 repositories,
                              6 services, 2 voters, 5 migrations, 34 tests
frontend/                     React 19 + Vite 8 + TS 5.9 app, 8 screens, 17 UI primitives, 7 test files
backend/compose.override.yaml  unreferenced Symfony Maker fragment (see I-03)
.agents/skills/**             bundled third-party agent skills — NOT application code
```

Structural facts that differ from the brief:

* The frontend is **not** a Vite starter. It has typed mocks, a design-token system, a
  Light/Dark/System provider, FR/EN/AR i18n with RTL, eight screens, and a hybrid API client.
  The requested `app/`, `features/` reorganisation would move working files without changing
  behaviour; see §5 and the "not corrected" section of the repair report.
* There is no `features/` directory, but the responsibilities it would hold are already
  separated by domain (`components/board`, `components/comments`, `components/share`,
  `api/client.ts`, `mocks/`, `theme/`, `i18n/`). This is a naming difference, not a missing layer.
* `.agents/skills` is a vendored skill library (shadcn, React best practices, API Platform
  skills). It is correctly excluded from lint/build scope and must not be modified or counted
  as product code.

---

## 3. Confirmed findings

"Confirmed" means reproduced from source, command output, or a schema/API contract inspection —
not inferred from documentation.

### 3.1 Backend

#### B-01 — PHP requirement contradicts the locked test toolchain · **P1** · *confirmed by `composer show`*

* **Files:** `backend/composer.json`, `.github/workflows/ci.yml`, `README.md` ("PHP >= 8.2")
* **Problem:** `composer.json` declares `"php": ">=8.2"` while `phpunit/phpunit` is locked at
  **12.5.40**, whose own requirement is `php >=8.3`. CI already pins PHP 8.3 and carries a
  comment explaining the mismatch, but the package manifest and README still advertise 8.2.
* **Why it matters:** `composer install` on PHP 8.2 cannot resolve the lock file, so the
  documented minimum setup fails before anything runs. This is a setup-blocking defect, not a
  cosmetic one.
* **Correction:** raise the documented and declared minimum to PHP 8.3 (the version CI uses and
  the lowest version the locked test runner supports).
* **Dependencies / decisions:** none. No package versions change; only the platform constraint.
* **Verification:** `composer validate --no-check-publish`, `composer check-platform-reqs`,
  full PHPUnit run.

#### B-02 — Fixture loader maps every connector endpoint to `toId` · **P1** · *confirmed by static analysis*

* **File:** `backend/src/DataFixtures/SyntheticFixtureLoader.php` (line ~138)
* **Problem:** the loop iterates `['fromKey', 'toKey']` but selects the target key with
  `'fromId' === $relation ? 'fromId' : 'toId'`. That comparison is always false, so the
  resolved metadata only ever writes `toId`; `fromId` is never populated and the second
  iteration overwrites the first value.
* **Why it matters:** `app:fixtures:load` writes canvas connector elements whose `fromId` is
  missing and whose `toId` points at the second endpoint. `BoardCanvas` resolves connectors by
  matching both ids against notes, so seeded connectors silently do not render. The database is
  seeded with wrong data that the idempotent loader will not repair on a second run.
* **Correction:** map `fromKey → fromId`, `toKey → toId`; on reload, repair only a missing or
  wrong `fromId`/`toId` on existing connector rows, preserving every other metadata key and never
  rewriting note style metadata.
* **Verification:** PHPStan level 5 no longer reports `strictComparison`; a fixture integration
  test asserts the resolved pair, then corrupts one id, removes the other and checks that a
  reload restores both while keeping unrelated metadata.

#### B-03 — Canvas geometry silently coerces invalid input to `0.0` · **P2** · *confirmed by code path*

* **Files:** `backend/src/Service/BoardService.php` (`addElement`), `backend/src/Controller/CanvasApiController.php` (`update`)
* **Problem:** required geometry keys are only checked for existence, then cast with
  `(float) $input['x']`. `(float) "abc"` is `0.0`; `(float) []` is `0.0`; huge values and
  `INF`/`NAN` strings pass through. `POST /api/boards/{id}/elements` therefore accepts
  `{"x":"left","y":null,...}` and stores a note at the origin. The PATCH path has the same
  behaviour for `x`, `y`, `width`, `height`, `rotation`.
* **Why it matters:** a malformed client or a future integration corrupts persisted board
  geometry with no error; the note becomes unreachable at `0,0`. The domain object validates
  only negative dimensions, not finiteness.
* **Correction:** validate each supplied geometry value with `is_numeric()` and `is_finite()`,
  reject with `422` and a specific message, and validate dimensions are `>= 0` before persisting.
* **Verification:** functional tests posting a non-numeric coordinate expect `422` and no
  element row; the existing create/update tests stay green.

#### B-04 — Registration returns 500 for a duplicate workspace name · **P2** · *confirmed by schema + code*

* **Files:** `backend/src/Entity/Workspace.php` (`uniq_workspaces_name`), `backend/src/Controller/AuthApiController.php` (`register`)
* **Problem:** self-service registration derives the workspace name as `"{name} Workspace"` and
  the schema enforces a unique constraint on `workspaces.name`. The second account registering
  with the same display name hits a `UniqueConstraintViolationException`, which the controller
  does not catch, so the request fails with 500 after the user row is prepared.
* **Why it matters:** duplicate display names are normal in a team product; the failure is a
  server error surfaced as a raw 500 and is unrecoverable from the UI.
* **Correction:** treat the workspace name as a label rather than an identifier — replace the
  unique constraint with a non-unique index in a new migration (existing migrations are
  immutable) and keep the entity metadata aligned.
* **Verification:** two registrations with the same `name` return `201` with distinct workspace
  ids; `doctrine:schema:validate` passes after the migration.

#### B-05 — Missing API foundation: validation, serialization, OpenAPI, pagination · **P2** · *confirmed by manifest*

* **Files:** `backend/composer.json`, all controllers
* **Problem:** Symfony Validator and Serializer are not installed, so every payload is
  hand-validated in controllers with `is_numeric`/`in_array`/`inst` checks and every response
  is hand-built by `BoardSerializer` / `CanvasSerializer`. There is no OpenAPI document; the
  client wire types in `frontend/src/api/client.ts` are maintained by hand. Collections
  (`/api/boards`, comments, timeline) return everything with no pagination.
* **Why it matters:** the request/response contract has no machine-checkable source, so drift is
  invisible until runtime; validation rules are duplicated per action; unbounded collections
  will not scale. This is a foundation gap, not a bug in current behaviour.
* **Correction:** adopt API Platform (or Validator + Serializer + an OpenAPI generator) and
  paginate collections. This requires package installation and an architecture decision.
* **Dependencies:** proposed in §6 — **blocked on approval**.

#### B-06 — No centralised API error format or exception mapping · **P2** · *confirmed by code*

* **Files:** controllers, `backend/src/Controller/JsonPayloadTrait.php`
* **Problem:** errors are ad-hoc `{ "error": "..." }` arrays produced by each `catch` block.
  Uncaught `UniqueConstraintViolationException` (see B-04) and any other runtime failure fall
  through to Symfony's default error page/HTML or a generic 500 JSON depending on the request
  format. `decodePayload` also accepts a JSON array (`[1,2]`) because `is_array` is true for
  lists.
* **Why it matters:** clients cannot rely on a stable error contract, and a JSON list body
  produces confusing `Missing "type"` messages rather than a clear 400.
* **Correction:** one exception listener producing RFC 7807-ish `{ error, code }` with correct
  statuses, and reject non-object JSON payloads. Depends on the §6 API foundation decision for
  the full version; a small listener is independent.
* **Verification:** malformed-body and forbidden/write tests assert status + content type.

#### B-07 — Token model is a single session per user, undocumented · **P3** · *confirmed by entity*

* **Files:** `backend/src/Entity/User.php` (`api_token_hash`), `AuthApiController::issueToken`
* **Problem:** issuing a token overwrites the single stored hash, so a new login silently signs
  out the other device. There is no token table, expiry, scope, or rotation, and the token never
  expires until logout or replacement.
* **Why it matters:** acceptable for a demo, unsuitable for a multi-device collaborative product.
  Also means a stolen token remains valid indefinitely.
* **Correction:** move tokens to a `user_tokens` table with `created_at`/`expires_at`/`last_used_at`,
  support multiple sessions and revocation; add expiry. Architecture/dependency decision.
* **Priority rationale:** P3 today because the product is single-session demo; it becomes P1
  before production.
* **Sprint 1 update:** token expiry, login rotation and logout revocation are now implemented (see
  §8). Multi-device sessions still require a token table and remain **PLANNED**.

#### B-08 — Timeline writes flush once per record inside outer request flows · **P3** · *confirmed by code*

* **Files:** `backend/src/Service/TimelineService.php`, controllers
* **Problem:** `record()` calls `flush()`; canvas create/update/delete call `record()`, and
  restore calls `record()` inside `wrapInTransaction`, so a single request performs several
  flushes. Combined with full-array snapshots per entry, timeline rows grow with element count.
* **Why it matters:** write amplification and snapshot storage growth; not a correctness bug.
* **Correction:** batch flushes per request, snapshot only changed elements, and prune/compact
  history later. Deferred (needs a retention decision).

#### B-09 — Public boards appear in authenticated board listings · **P2** · *confirmed by query*

* **File:** `backend/src/Repository/BoardRepository.php` (`queryAccessible`)
* **Problem:** the accessible-board query is
  `member OR (same workspace AND workspace admin) OR board.publicAccess = true`. The public
  clause is not scoped to the viewer's workspace, so any authenticated user sees every public
  board from every workspace in `GET /api/boards`, and `GET /api/boards?workspaceId=…` filters it
  but `findAccessibleTo` does not.
* **Why it matters:** public boards are already readable by anyone with the link, so this is not
  an access-control breach, but it makes the dashboard an unintended cross-workspace directory
  and contradicts the README's "per-user workspace isolation" claim. Bulk listing of public
  content is also a privacy surprise for users who shared one board.
* **Correction:** restrict the accessible query to memberships and workspace administration;
  keep public reads on the direct `GET /api/boards/{id}` path only. A public-discovery feature, if
  wanted, needs its own endpoint and opt-in.
* **Verification:** an authenticated outsider's `GET /api/boards` no longer includes another
  workspace's public board, while `GET /api/boards/{id}` still returns the sanitized payload.

#### B-10 — No rate limiting, email verification or password policy on auth · **P1** · *confirmed by code*

* **File:** `backend/src/Controller/AuthApiController.php`, `config/packages/security.yaml`
* **Problem:** `POST /api/auth/login` and `/register` are public, unthrottled and unverified.
  The only password rule is `strlen >= 12`; there is no breach-list check, no email confirmation,
  no account lockout and no audit log. Login compares with `password_verify` (correct) but an
  attacker can attempt unlimited passwords; registration allows unlimited account creation.
* **Why it matters:** credential stuffing and resource exhaustion are trivial; unverified email
  means invitations can be claimed by whoever controls the link, not the mailbox.
* **Correction:** add `symfony/rate-limiter` (login/register limiters), email verification
  (`symfony/uid` signed links + mailer), and a password policy. Rate limiting is the immediate
  mitigation; the rest is a product decision.
* **Dependencies:** **blocked on approval** (§6).
* **Sprint 1 update:** database-backed login/registration throttling and a shared password policy
  are now implemented without a new dependency (see §8). Email verification, reset and breach-list
  checks remain **BLOCKED / PLANNED**.

#### B-11 — Bearer tokens are stored in `sessionStorage` · **P2** · *confirmed by client*

* **File:** `frontend/src/api/client.ts`
* **Problem:** the session token is written to `sessionStorage`; any XSS in the app can read it.
  There is no CSP header, no token expiry, and the API is stateless. React escapes rendered
  strings, so the current XSS surface is limited, but the model is fragile.
* **Why it matters:** token theft persists for the session; the mitigation relies entirely on
  front-end escaping discipline.
* **Correction:** short-lived tokens + refresh, or an HttpOnly cookie with CSRF protection and a
  strict CSP. Architecture decision; documented, not changed in this pass.
* **Sprint 1 update:** tokens now expire and authenticated 401s clear the browser session; the
  storage model and missing CSP are unchanged (see §8).

#### B-12 — Serializers expose author email on all authenticated board payloads · **P3** · *confirmed by code*

* **Files:** `backend/src/Service/CanvasSerializer.php`, `BoardSerializer.php`
* **Problem:** `summary()`/`detail()` embed the full user array (including email) for every
  board member, canvas author, comment author and timeline author. Public payloads are
  correctly sanitized, but any board member can enumerate colleague emails.
* **Why it matters:** member emails are often personal data; the share dialog only needs
  `name` and role. This is a data-minimisation issue rather than a breach.
* **Correction:** split `userSummary` (id, name, accent) from `userSelf` (adds email), and use
  the summary in board payloads. Depends on the serialization-groups decision (§6).

### 3.2 Frontend

#### F-01 — Root scripts invoke npm incorrectly · **P1** · *confirmed by command*

* **File:** `package.json`
* **Problem:** `"lint": "npm --prefix frontend lint"`, `"build": "npm --prefix frontend build"`,
  `"dev"` and `"test"` follow the same pattern. `npm <script>` is only valid for `start` and
  `test`; `lint`/`build`/`dev` must be `npm run <script>`. The README and AGENTS.md both instruct
  `pnpm run lint` from the root, which fails with `Unknown command: "lint"`.
* **Why it matters:** the documented first-line quality gate fails on a clean checkout.
* **Correction:** use `npm --prefix frontend run <script>` for every forwarded script.
* **Verification:** `pnpm run lint`, `pnpm run build`, `pnpm test` from the repository root.

#### F-02 — Board title edits are never persisted · **P1** · *confirmed by code*

* **File:** `frontend/src/screens/BoardEditorScreen.tsx`
* **Problem:** the title `<input>` calls `setTitle` only. No client function sends
  `PATCH /api/boards/{id}`, and nothing reads the title back after a reload. The header shows a
  check icon and "Enregistré" next to the unsaved value, which actively misleads.
* **Why it matters:** a user renaming a board loses the change on reload while the UI says it was
  saved — a data-loss illusion.
* **Correction:** add a `updateBoard` client call and commit on blur/Enter with error feedback;
  only show the saved indicator for persisted state.
* **Verification:** a component test asserts the PATCH call and the saved indicator.

#### F-03 — "Move to trash" only hides the card locally · **P2** · *confirmed by code*

* **File:** `frontend/src/screens/DashboardScreen.tsx`
* **Problem:** the delete menu item filters the board out of local state and toasts
  "déplacé dans la corbeille", but there is no `DELETE /api/boards/{id}` endpoint and no local
  persistence, so the board reappears after reload. The Trash view is a static empty state and
  its copy promises a 30-day retention that does not exist.
* **Why it matters:** the UI reports a destructive action that did not happen; the trash promise
  is false.
* **Correction:** either implement archive/soft-delete end to end, or make the control explicitly
  demo-only. Deferred: board deletion semantics (hard vs soft delete, retention) need a product
  decision, and hard-deleting boards is not something to add silently.
* **Status:** **not corrected** — recorded in the repair report.

#### F-04 — Workspace settings role changes are local only · **P2** · *confirmed by code*

* **File:** `frontend/src/screens/SettingsScreen.tsx`
* **Problem:** member roles are held in component state; the toast confirms a role change that
  never reaches the server (there is no workspace membership endpoint). The members table and
  plan/billing values come from `mocks/`, not from `GET /api/workspaces`.
* **Why it matters:** an administrator believes access was changed when it was not — the exact
  class of bug that produces over-permissioned accounts.
* **Correction:** load the workspace summary/members from the API and implement membership
  role updates with server authorization. Deferred (new endpoints + tests).
* **Status:** **not corrected** — intentional scope control; flagged as required before claiming
  workspace administration works.

#### F-05 — Five of eight toolbar tools do nothing · **P2** · *confirmed by code*

* **Files:** `frontend/src/screens/BoardEditorScreen.tsx`, `frontend/src/components/board/BoardCanvas.tsx`
* **Problem:** the toolbar offers select, note, comment, shape, connector, text, pen, image.
  `BoardCanvas` only receives `'select' | 'note' | 'comment'`; every other tool falls back to
  `select`, so choosing "Forme", "Connecteur", "Texte", "Stylo" or "Image" highlights the button
  and changes nothing on click. The canvas renders notes, connectors and a frame from mocks; it
  has no shape or text element model, although the spec lists both.
* **Why it matters:** the toolbar advertises capabilities that do not exist, and the UI spec's
  visual requirement (shapes and text on the canvas) is only partly met.
* **Correction:** implement shape/text element rendering and creation with typed local state, or
  disable the unimplemented tools with an explicit "coming soon" affordance. Full implementation
  of pen/image persistence is a feature, not a repair.
* **Status:** partially addressed / documented; see the repair report.

#### F-06 — Comment creation uses `window.prompt` · **P2** · *confirmed by code*

* **File:** `frontend/src/screens/BoardEditorScreen.tsx` (`addCommentAt`)
* **Problem:** placing a comment opens a native `window.prompt`. It is not styleable, not
  localisable, blocks the event loop, and has inconsistent screen-reader behaviour; it also
  cannot show validation or the thread context.
* **Why it matters:** comments are a core screen and the brief requires accessible dialogs/forms.
* **Correction:** replace with an in-app dialog or composer using the existing `Dialog`/`Input`
  primitives.
* **Verification:** component test that creates a comment through the dialog.

#### F-07 — Hard-coded French strings inside shared components · **P2** · *confirmed by code*

* **Files:** `frontend/src/components/board/BoardCanvas.tsx` (`Canevas du tableau : …`,
  `Aperçu du tableau : …`, `Sélectionner la note : …`, `Texte de la note`),
  `frontend/src/components/ui/dialog.tsx` (close label `"Fermer"`),
  `frontend/src/screens/TimeTravelScreen.tsx` (fallback French board title, `kbd` literal "Espace")
* **Problem:** the app ships FR/EN/AR with RTL, but several accessible names stay French in
  English and Arabic modes. Screen readers announce French labels in an otherwise translated UI.
* **Why it matters:** accessibility and localisation correctness.
* **Correction:** move the strings into `i18n/dictionary.ts` for all three locales and use
  `useI18n()` in those components.
* **Verification:** existing i18n/theme tests plus a canvas label assertion in English.

#### F-08 — API errors surface as transport text · **P2** · *confirmed by code*

* **File:** `frontend/src/api/client.ts`
* **Problem:** `request()` throws `API 422 Unprocessable Content for /auth/register` and drops the
  JSON `{ error }` body. The sign-in form shows that raw string; other screens fall back with a
  console warning only.
* **Why it matters:** users cannot tell "email already registered" from a network fault.
* **Correction:** parse `{ error }` when the response is JSON, attach `status`, and map known
  statuses to translated messages.
* **Verification:** unit test for 401/422 error messages.

#### F-09 — No route-level error boundary or 404 screen · **P3** · *confirmed by code*

* **File:** `frontend/src/App.tsx`
* **Problem:** unknown paths redirect to `/`; a render error in any screen blanks the app. There
  is no error boundary and no offline shell.
* **Correction:** add an error boundary and a not-found state. Low impact today.

#### F-10 — Mock data and API data use two identity spaces · **P2** · *confirmed by code*

* **File:** `frontend/src/api/client.ts` (`localUserIds`, `localUserId`, `isRemoteId`)
* **Problem:** API users are mapped onto mock ids by matching display names; unmatched users keep
  their UUID. If two users share a name, avatars/colors collapse onto one mock identity; if a demo
  user is renamed on the server, the mapping silently stops matching. `isRemoteId` classifies
  anything that is not a UUID as local.
* **Why it matters:** presence, avatars and comment authorship can display the wrong person. The
  bridge is a demo device and should not survive into production.
* **Correction:** key everything by server user id and treat mocks as fixtures with the same ids,
  or seed the mock ids from the API. Deferred to the API foundation work.

### 3.3 Security summary

| Area | Status | Evidence |
| --- | --- | --- |
| Server-side board authorization | **Implemented** | `BoardVoter` checks workspace + role for VIEW/EDIT/COMMENT/SHARE; every controller calls `denyAccessUnlessGranted` |
| Workspace scoping | **Implemented** | `WorkspaceVoter` + explicit workspace-id comparisons in create/accept/invite paths |
| Anonymous public read | **Implemented and sanitized** | `security.yaml` allows only `GET /api/boards/{id}(/timeline|/elements)?`; controller requires `publicAccess`; `publicDetail`/`publicElement` drop members, comments, timeline and emails |
| Anonymous public write | **Denied** | functional test asserts 401 on PATCH; writes require voters |
| Token storage | **Hashed at rest** | SHA-256 digest in `users.api_token_hash`, unique index, compared by lookup |
| Token theft / XSS | **Weak** | `sessionStorage`, no expiry, no CSP (B-11) |
| CSRF | **Not applicable for bearer API**, but cookie sessions exist for the unimplemented `main` firewall | `api` firewall is stateless |
| CORS | **Restricted** | `DevelopmentCorsSubscriber` allows only `http(s)://localhost|127.0.0.1:517x`; verified 204 preflight |
| Rate limiting / brute force | **Missing** | B-10 |
| Email ownership | **Unverified** | B-10 |
| Password storage | **Correct** | `password_hash`/`password_verify`, cost configured in `when@test` |
| Invitation tokens | **Good** | 32 random bytes, SHA-256 at rest, 7-day expiry, single use, email + workspace match |
| IDOR | **Covered** | membership/element lookups always pass through a voter for the owning board |
| Error leakage | **Partial** | explicit JSON messages; uncaught exceptions fall back to framework output (B-06) |
| Secrets in git | **None found** | tracked `.env` files hold dev/test defaults only; `.env.local` ignored |
| Sensitive mock data | **No secrets** | mocks contain names/cities/emails of fictional users only |

### 3.4 Infrastructure

#### I-01 — Redis and Mailpit have no health checks or restart policy · **P3** · *confirmed by compose file*

* **File:** `docker-compose.yml`
* **Problem:** only `db` has a healthcheck. `redis` and `mailpit` are plain `image` + `ports`
  services with no readiness probe, no `restart` policy and no `depends_on` conditions. Nothing in
  the application uses Redis yet (no Messenger), so the container currently provides no function.
* **Why it matters:** the documented "running" state of Redis/Mailpit does not prove they are
  usable, and the app has no code path that needs Redis. The brief warns against claiming
  integration because a container starts — the current docs do not claim Redis integration, which
  is accurate.
* **Correction:** add healthchecks/restart policies; either wire Redis to Messenger (approved
  dependencies) or document it as reserved infrastructure.

#### I-02 — No automated database readiness gate for the app server · **P3** · *confirmed by setup*

* **Problem:** Symfony is started manually and can boot before Postgres accepts connections;
  migrations are a manual step with no check that they were applied.
* **Correction:** document a readiness loop, or add a `make`/script target that waits on
  `pg_isready` and runs migrations. No new dependency required.

#### I-03 — `backend/compose.override.yaml` is an unreferenced stray fragment · **P3** · *confirmed by compose layout*

* **File:** `backend/compose.override.yaml`
* **Problem:** Symfony Maker wrote a `mailer` service override, but there is no
  `backend/docker-compose.yml` for it to override, and the root Compose file already provides
  Mailpit. Running `docker compose` from `backend/` fails (no base file); from the root it is
  ignored. It also sets `MP_SMTP_AUTH_ACCEPT_ANY`/`ALLOW_INSECURE`, which the root Mailpit service
  does not use.
* **Why it matters:** dead configuration that suggests a service topology that does not exist.
* **Correction:** delete the file, or move mail configuration into the root compose file. Deletion
  of committed configuration needs owner confirmation; documented as debt.

#### I-04 — CI does not run the quality gates the brief requires · **P2** · *confirmed by workflow*

* **File:** `.github/workflows/ci.yml`
* **Problem:** CI runs frontend lint/build/test and backend container/schema/PHPUnit. It does not
  run `composer validate`, PHPStan, PHP-CS-Fixer, or any dependency audit; there is no
  `concurrency`, no `timeout-minutes`, and no artifact/log upload.
* **Why it matters:** a regression in static analysis or formatting cannot fail the pipeline.
* **Correction:** add the gates and timeouts; keep PHP 8.3 (the declared minimum) as the matrix
  floor.

### 3.5 Documentation

| ID | Priority | File | Problem | Correction |
| --- | --- | --- | --- | --- |
| D-01 | P1 | `docs/ui-spec.md` | Says "No backend calls until the developer says so" and "typed mock data only"; the shipped app calls the API for boards, canvas, comments, presence, sharing and replay. An agent following the spec would try to remove working integration. | Add a status note that the mock-only phase was superseded by the implemented API bridge, keeping the visual requirements authoritative. |
| D-02 | P1 | `README.md`, `DEVELOPMENT_ROADMAP.md` | Record `pnpm run lint`/`build` root checks as PASS, but those commands fail (F-01). Record "phases 0–10 DONE" including a "real-time collaboration service" while the same text admits no CRDT; the status table and the phase names disagree. | Correct the commands, and label the phase outcome (`SSE last-write-wins`, not CRDT). |
| D-03 | P2 | `frontend/README.md` | File did not exist, although `README.md` and the brief expect it and it is the natural place for frontend-specific commands. | Restored in this pass with commands, structure, API configuration and limitations. |
| D-04 | P2 | `AGENTS.md` | States all eight screens implemented (true) but also lists `pnpm run lint` root commands (broken), describes SSE as the sync architecture while the brief requires Node/Yjs/Hocuspocus, and does not mention PHPStan/PHP-CS-Fixer. | Align status, commands and the realtime decision record. |
| D-05 | P3 | whole repo | No OpenAPI document, no `docs/architecture.md`, no ADR for the realtime choice, no `.env.example`. | Add architecture/ADR docs with the approved realtime design. |

### 3.6 Quality tooling

#### Q-01 — PHPStan is effectively unconfigured · **P2** · *confirmed by command*

* **Files:** `backend/composer.json` (dev dependency only), no `phpstan.neon*`, no script
* **Problem:** invoking PHPStan with no arguments exits with "At least one path must be specified".
  With explicit paths at the default level 0 it passes trivially; at level 5 it reports 17 errors:
  8 Doctrine-managed `$id` "never written" false positives, 2 genuinely unused constructor
  properties, 2 never-read write-only entity properties, 2 over-wide nullable return types, the
  B-02 comparison bug, and one redundant `method_exists` in `tests/bootstrap.php`.
* **Why it matters:** the repository advertises PHPStan in `require-dev` but has no gate, so the
  tool provides false assurance. Level 0 passing means almost nothing.
* **Correction:** add `phpstan.neon.dist` at level 5 with `src` + `tests` paths, fix the real
  errors, and suppress only the Doctrine-generated-id pattern with a comment explaining why
  (until `phpstan/phpstan-doctrine` is approved).
* **Verification:** `composer analyse` exits 0.

#### Q-02 — PHP-CS-Fixer fails and is not gated · **P2** · *confirmed by command*

* **Problem:** the project ships `.php-cs-fixer.dist.php` but no composer script and no CI step;
  a dry run reports 20 of 55 files non-compliant (import order, trailing newlines, brace style).
* **Correction:** add `cs:check`/`cs:fix` scripts, run the fixer once, add the dry-run gate to CI,
  and exclude `migrations/` because applied migrations are immutable and must not be reformatted.

#### Q-03 — Test coverage is contract-shaped, not behaviour-complete · **P2** · *confirmed by test files*

* **Problem:** 27 backend tests cover auth, board roles, public reads, invitations, comments,
  presence and timeline. They do not cover the invitation-email failure path, duplicate-name
  registration (B-04), invalid geometry (B-03), pagination, or CORS. Frontend has 10 tests; the
  settings screen, share dialog role changes, comments panel and command palette have no tests,
  and there is no end-to-end browser suite despite the "E2E" test name (it is a jsdom integration
  test with a mocked API).
* **Correction:** add tests for each repaired defect now; Playwright for real browser coverage is
  in the §6 proposal.

---

## 4. Architecture assessment

### 4.1 What is already sound

* Symfony 7.4 with a single `App\` namespace, attribute routing, autowired services and
  environment-specific config.
* A real domain model (10 entities) with constructor invariants, typed repositories, voters for
  authorization and dedicated serializers that separate public from authenticated payloads.
* Migrations are additive and versioned; the fixture loader refuses non-disposable targets.
* Frontend has a central token file, semantic Tailwind utilities, a theme provider reacting to
  `prefers-color-scheme`, typed mocks, reusable primitives and a router with guards.
* One request/response shape per screen, with explicit demo fallbacks and console warnings rather
  than silent failure.

### 4.2 What is structurally wrong

1. **Two realtime designs, one implemented.** The code implements authenticated SSE with a 500 ms
   reconnect loop and a 3 s poll, last-write-wins. The product decision recorded in the brief is a
   separate Node service with Yjs/Hocuspocus. Keeping both would create competing sources of
   truth. See §5.
2. **The client is a hand-written bridge to a contract with no source of truth.** Wire types are
   duplicated in `frontend/src/api/client.ts` and three PHP serializers. Any field change is a
   silent runtime break.
3. **Controllers own validation, persistence orchestration, serialization and error shaping.**
   There is no DTO/validator layer, so each action re-implements rules (and B-03 shows what is
   missed).
4. **Presentation components own demo fallbacks.** Screens mix "the API answered" and "show mock
   data" in the same state, which is why F-03/F-04 can claim actions that never happened.

### 4.3 Recommended target (not implemented in this pass)

* Keep the current frontend folder layout. It already maps to the requested concerns; renaming
  `screens/` to `app/` and introducing empty `features/` folders would add churn without a
  behavioural or dependency benefit. If the team wants the exact requested tree, do it as a
  mechanical move-only commit with no logic changes.
* Backend: add `DTO/`, `Validator/`, `Exception/` and a single exception listener; only introduce
  `State/Provider|Processor` **if** API Platform is approved.
* Realtime: one service only. Either keep documented SSE as the interim transport, or implement
  the Node + Yjs/Hocuspocus service; never both.

---

## 5. Realtime collaboration decision record

**Current implementation (verified):**
`GET /api/boards/{id}/stream` returns `text/event-stream`; each request emits `retry`, `ready`,
one `presence` frame and one `canvas` frame, then closes. The client re-reads the body with
`fetch` (token stays in the `Authorization` header), reconnects every 500 ms, and separately polls
every 3 s. Canvas updates are last-write-wins. There is no CRDT, no operation log, no offline
queue, no conflict resolution and no awareness protocol beyond cursor position/tool.

**Stated product decision:** a separate Node.js service using Yjs + Hocuspocus for document sync
and awareness, with Symfony owning authentication and persistence.

**Conflict:** the brief says this Node/Yjs service is "the existing architectural decision"; the
repository's own `AGENTS.md` says SSE is what exists and Yjs/Hocuspocus is incomplete. Both are
true about different things (decision vs implementation), but they must not both be built.

**Recommendation:** keep the SSE implementation as an explicitly labelled interim transport until
the Node service is approved and scoped. It is functional, authenticated and tested; it is not
described as CRDT. Do not add a second sync path inside Symfony controllers.

**Future integration design (for the Node/Yjs service, to be approved before implementation):**

| Concern | Design |
| --- | --- |
| Authentication | Browser obtains a short-lived signed sync ticket from `POST /api/boards/{id}/sync-ticket` (Symfony, authenticated, board-scoped, ≤60 s, single use). Node verifies the signature/claims locally; no session sharing, no token in query strings. |
| Authorization | The ticket carries `userId`, `boardId`, `workspaceId` and the resolved board role; Node re-checks that the user still holds the role before accepting a connection, and Symfony remains the authority for membership changes (broadcast revocations). |
| Document identity | One Y.Doc per board, named `board:{uuid}`; the document schema mirrors `CanvasElement` (id, type, geometry, content, color, metadata, authorId, createdAt). |
| Persistence | Node holds the live doc; Symfony stays the system of record. A debounced, idempotent snapshot endpoint (`PUT /api/boards/{id}/snapshot` with optimistic version) persists Yjs state, and timeline entries are written only from committed snapshots — never from every keystroke. |
| Awareness | Hocuspocus awareness for cursors/selection/tool only; ephemeral, never stored, never used for authorization. Separate the ephemeral cursor store from authoritative board data. |
| Connection lifecycle | Ticket → WebSocket handshake → `sync` → `awareness`; heartbeat/ping, exponential backoff with jitter, resume from last `stateVector`. |
| Conflict handling | Yjs CRDT merge; last-write-wins is removed from the document path. Comments/timeline remain server-authored and are not merged client-side. |
| Security | WSS only in non-local environments, origin allowlist, per-board room isolation, max message size, per-connection rate limits, no board content in logs, revoked tickets rejected on reconnect. |
| Boundaries | Symfony: auth, roles, persistence, history, invitations. Node: document merge + awareness. Neither writes the other's store directly. |

Until that is approved and tested with two clients, documentation must say **"SSE presence/canvas
invalidation, last-write-wins, no CRDT"** and nothing stronger.

---

## 6. Consolidated dependency proposal — approval required

`AGENTS.md` requires approval before adding a dependency or changing architecture. Nothing below
has been installed. The work in this pass is limited to what existing dependencies can support.

### 6.1 Recommended (needed for the requested backend foundation)

| Package | Why | Notes |
| --- | --- | --- |
| `api-platform/core` (4.x) + `api-platform/symfony` | Resource/operation metadata, OpenAPI generation, pagination, validation integration, Hydra/JSON-LD or JSON | Replaces hand-written controllers/serializers incrementally; State Providers/Processors only where behaviour is non-CRUD. **Requires an explicit architecture decision** because it changes the controller layer. |
| `symfony/validator` + `symfony/serializer` | Declarative request validation and consistent (de)serialization | Works with or without API Platform; removes per-controller validation drift (B-03). |
| `symfony/rate-limiter` | Throttle `/auth/login`, `/auth/register`, invitation creation | Direct mitigation for B-10; small, first-party. **Superseded for login/registration by Sprint 1's DB-backed throttle; still not installed.** |
| `phpstan/phpstan-doctrine` + `phpstan/phpstan-symfony` | Remove Doctrine-managed-property false positives and understand the container | Lets PHPStan run at level 6+ without hand-written ignores (Q-01). Extension installer optional. |
| `lexik/jwt-authentication-bundle` + `web-token/jwt-library` + `symfony/uid` | The requested JWT auth, replacing the single opaque token | Only if JWT replaces bearer tokens; otherwise skip and keep hashed tokens. Requires key management (`config/jwt/*.pem`, already gitignored). |
| `symfony/messenger` + `symfony/redis-messenger` (or `predis/predis`) | Async email/invitation delivery and future realtime events | Makes the existing Redis container purposeful. |
| `@playwright/test` (frontend, dev) | Real-browser E2E coverage; the current "E2E" test is jsdom | Also enables accessibility checks. |
| `@axe-core/playwright` (optional) | Automated WCAG AA checks | Recommended alongside Playwright. |

### 6.2 Deliberately not proposed

* A global state library (Redux/Zustand/Jotai): screen state is local and small; adding one now
  would be unjustified indirection.
* A UI framework migration (shadcn CLI, MUI, Chakra): the Radix-based primitives already match the
  design system.
* Deal/decimal libraries: prices are integer dinars in TND.
* `symfony/uid`: only worth adding if JWT/verification work lands; the 30-line UUID v4 generator is
  correct and tested by usage.

### 6.3 Platform decision required

* **Backend runtime:** the brief asks for PHP 8.2+ compatibility while the locked PHPUnit needs
  8.3. Recommendation: **PHP 8.3** as the floor (CI already uses it).
* **Realtime:** see §5 — one transport only.
* **API style:** API Platform vs. hand-written controllers + OpenAPI. Recommendation: API Platform
  for the resource/CRUD surface, keep bespoke controllers for auth, invitations and sync tickets.

---

## 7. Verification plan for the repairs in this pass

| Repair | Verification |
| --- | --- |
| Root scripts (F-01) | `pnpm run lint`, `pnpm run build`, `pnpm test` from the root |
| PHP constraint (B-01) | `composer validate --no-check-publish`, `composer check-platform-reqs` |
| PHPStan config + real fixes (Q-01, B-02) | `composer analyse` at level 5 |
| PHP-CS-Fixer (Q-02) | `composer cs:check` exits 0 on 53 files; `migrations/` excluded |
| Geometry validation (B-03) | new PHPUnit cases: non-numeric create/update → 422, no row; valid path unchanged |
| Workspace name uniqueness (B-04) | new PHPUnit case: two registrations, same name → 201, distinct workspaces; schema validate |
| Public board listing (B-09) | new PHPUnit case: outsider's board list excludes another workspace's public board |
| Editor title persistence (F-02) | frontend test asserting `PATCH /api/boards/{id}` and error handling |
| Comment composer (F-06) | frontend test creating a comment through the dialog |
| i18n strings (F-07) | existing i18n tests + label assertions in EN |
| CI gates (I-04) | workflow YAML review; commands run locally exactly as CI will |
| Docs (D-01…D-04) | manual read-back against commands actually executed |

Checks that **cannot** be run in this pass: anything requiring the §6 packages (RateLimiter,
Validator, API Platform, Playwright, Doctrine PHPStan extension), a GitHub-hosted CI run, and
two-client realtime verification.

---

## 8. Sprint 1 addendum — authentication and security hardening (2026-11-15)

This section supersedes the auth-related dispositions in §3.1 and the security summary in §3.3.
It does **not** claim production readiness. The full evidence and limitations are in
[`docs/sprints/sprint-1-report.md`](sprints/sprint-1-report.md).

### 8.1 What changed

| Area | Before | After |
| --- | --- | --- |
| Token lifetime | No expiry; stolen token valid indefinitely | `AUTH_TOKEN_TTL` (default 7 days); authenticator rejects expired/revoked tokens |
| Token rotation | New login overwrote the hash | Same single-session model, now explicit and tested; logout revokes |
| Password hashing | Direct `password_hash` / `password_verify` | Symfony configured `UserPasswordHasherInterface` |
| Password policy | `strlen >= 12` | 12–72 bytes, at least one letter and one digit, shared backend service and signup UI check |
| Login throttle | None | 5 failures / 15 min per email+IP and 20 / 15 min per IP (test: 3) |
| Registration throttle | None | 10 / hour per IP (test: 5) |
| Throttle response | — | `429` + `Retry-After`; success clears login failures |
| Auth response headers | Defaults | `nosniff`, `no-referrer`, `DENY`, `Cache-Control: no-store` on `/api/auth` |
| Browser 401 | Stayed in the signed-in shell | Clear `sessionStorage`, redirect to `/sign-in?expired=1`, translated notice |
| Logout offline | Error propagated after local clear | Local clear is guaranteed; the server token still expires |

The throttle is a small Doctrine entity/repository (`AuthAttempt`, `auth_attempts`), not
`symfony/rate-limiter` or Redis. Keys are SHA-256(email + IP) and SHA-256(IP); only hashes are
stored. Rows are pruned on write. This avoids a dependency and works across PHP processes sharing
the database, but it is not a distributed limiter and adds a write per attempt.

### 8.2 Current security summary

| Control | Status |
| --- | --- |
| Token storage at rest | **Good** — random 48-byte value, SHA-256 hash only |
| Token lifetime | **Good** — expiry enforced in the authenticator; default 7 days |
| Token revocation | **Good for the single session** — logout and login rotation |
| Password storage | **Good** — Symfony hasher (bcrypt cost 4 in test, configured default elsewhere) |
| Password policy | **Moderate** — length + letter/digit; no breach list or history |
| Brute-force resistance | **Moderate** — per-key/IP DB throttle; no distributed limiter; proxy caveat |
| Account verification | **Missing** — no email ownership proof |
| Password recovery | **Missing** — demo link only |
| Browser token exposure | **Weak** — `sessionStorage`, no CSP |
| Response caching | **Good for auth** — `no-store`; other API routes rely on defaults |
| CORS | **Development only** — loopback Vite allowlist; no production policy |
| Authorization | **Strong** — server-side board/workspace voters; Sprint 0 filtered summaries |

### 8.3 Explicitly not implemented / blocked

- Email verification: no verified-email field, token, route, template or mail flow. Needs an
  approved product rule and a token design.
- Password reset and password change: no routes; the UI "forgot" action is not a reset flow.
  Any future sensitive account mutation must re-authenticate the current password.
- Multi-device sessions: still one active token per user.
- CSP, HttpOnly-cookie sessions and CSRF protection: unchanged.
- Breach-list password checks, password history and account lockout beyond the sliding throttle.
- Distributed/Redis throttling and trusted-proxy configuration.
- Real-browser verification of the expired-session redirect; a GitHub Actions re-run.

### 8.4 Verification (2026-11-15)

| Check | Result |
| --- | --- |
| Backend PHPUnit | **PASS** — 51 tests / 325 assertions (10 new security tests / 62 assertions) |
| PHPStan level 5 | **PASS** — 0 errors |
| PHP-CS-Fixer | **PASS** — 0 of 60 files |
| `composer validate` / `composer audit --locked` | **PASS** / no advisories |
| `lint:container` + dev `schema:validate` | **PASS** |
| Sprint 1 migration | **PASS** — applied additively to dev (4 boards preserved); fresh DB up/down/up; test DB migrated and validated |
| PHP 8.3 syntax | **PASS** — `src`, `tests`, `migrations` parsed by `php:8.3-cli` |
| Frontend lint/build | **PASS** — ESLint, `tsc -b`, Vite 2,207 modules |
| Frontend Vitest | **PASS** — 8 files / 17 tests (6 new session/validation tests) |
| GitHub Actions / real-browser expiry flow | **NOT VERIFIED / NOT RUN** |

### 8.5 Operational notes

- Existing tokens have a null expiry after the migration and are rejected. Users must sign in once.
- Production must set `APP_ENV=prod`, `APP_DEBUG=0`, a strong `APP_SECRET`, `AUTH_TOKEN_TTL` and the
  `AUTH_*` limits; behind a reverse proxy set `SYMFONY_TRUSTED_PROXIES` or per-IP limits are ineffective.
- The development CORS allowlist is loopback-only. Production should serve the SPA and API from the
  same origin or configure an explicit policy; no wildcard credential CORS was added.

## 9. Sprint 2 addendum — workspace administration (2026-11-15)

Sprint 2 extended the existing single-workspace membership embedded in `User` instead of adding a
redundant membership table. `membership_status` and `membership_updated_at` are additive; active
membership is checked by the authenticator and both voters. Removal revokes the token and removes
all board memberships in one transaction.

The permission matrix is centralized in `WorkspaceMembershipPolicy`: one Owner; Owner can manage
Admins and Members; Admin can manage Member roles and invite Member roles; no self role change,
no Owner role grant/removal, no owner/self removal. Workspace invitations are 32-byte tokens stored
as SHA-256, email-bound, seven-day and revocable; acceptance is transactional during registration
for new accounts only. `workspace_invitations` and the membership columns were added by
`Version20261115180000`.

**Verified:** 69 tests / 437 assertions at Sprint 2 completion, 9 frontend files / 24 tests,
PHPStan and CS-Fixer clean, schema validation pass. The full report and deferred decisions (owner
transfer, self-leave, removed-account re-invitation, resend/decline, durable audit, multi-workspace)
are in [`sprints/sprint-2-report.md`](sprints/sprint-2-report.md).

## 10. Sprint 3 addendum — board lifecycle (2026-11-15)

Boards now use one `lifecycle_status` (`active`/`archived`/`trashed`) plus nullable
`archived_at`/`trashed_at`; the inactive timestamp is cleared on every transition. Active and
archive/trash listings require the exact status. `BOARD_MANAGE` is separate from content access:
board Owner/Admin or workspace administrator can archive/trash/restore; archived boards are
view-only; trashed content and anonymous public reads are denied. The pre-existing workspace-admin
board bypass remains explicit.

**Permanent deletion is PLANNED.** No approved retention period, deletion authorization or
dependent-record policy exists. Trashed boards are retained indefinitely; no automatic purge or
delete endpoint was added. Lifecycle actions use the application logger; a durable audit table is
PLANNED. Concurrent transitions are server-side checked but not row-locked.

`Version20261115190000` adds the lifecycle columns and `(workspace_id, lifecycle_status)` index.
Verified on the preserved dev database and on a fresh disposable database (eight migrations,
latest down/up, schema validation). Final gates: 75 backend tests / 532 assertions; 10 frontend
files / 28 tests; PHPStan clean; CS-Fixer 0/65; lint and production build pass. Full evidence is in
[`sprints/sprint-3-report.md`](sprints/sprint-3-report.md).

### 10.1 Remaining lifecycle risks

- No permanent-delete/retention workflow; do not infer a 30-day policy from old UI copy.
- No durable audit or row locking for administrative actions.
- Workspace Admin access to unjoined private boards remains a broad, explicitly documented policy.
- No real-browser pass of archive/trash; no green GitHub Actions run (no push).
