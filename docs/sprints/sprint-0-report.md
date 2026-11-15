# Sprint 0 — Stabilization report

**Date:** 2026-11-15
**Branch:** `main` at `5b993fa` + uncommitted Sprint 0 changes
**Scope:** CI repair, workspace authorization, serializer/authorization review, database and Docker
verification, frontend regression checks, documentation correction.
**Not performed:** no commit, push, deploy, database reset or destructive migration.

---

## A. Executive summary

**Initial state.** The repository was a working React 19 / Symfony 7.4 whiteboard on PostgreSQL,
with 34 backend tests / 202 assertions and 7 frontend files / 11 jsdom tests passing locally. Ten
uncommitted files from the previous stabilization pass were preserved. The only GitHub Actions run
on the current commit (`43715665395`) passed the frontend and failed the backend at
`composer install`.

**Main problems found.**

1. **Release-blocking CI defect:** the lock file was resolved on PHP 8.5 without a platform pin,
   so it required PHP 8.4 (`doctrine/collections 3.1.0`, `doctrine/instantiator 2.1.0`) while
   `composer.json` and CI declare PHP 8.3. No backend check had ever run on GitHub.
2. **Workspace board information disclosure:** `GET /api/workspaces/{id}` checked only workspace
   membership and then serialized every board in the workspace, including private boards the user
   had not joined. `GET /api/workspaces` also leaked an unfiltered `boardCount`.
3. **Stale/over-claiming documentation:** the roadmap reported 10/27 tests, the repair report said
   CI had never run, and `NEXT-AGENT-PROMPT.md` still instructed an agent to mark all phases DONE
   and claimed conflict-free collaboration.

**Fixes delivered.**

- Pinned Composer resolution to the declared floor (`config.platform.php=8.3.0`), downgraded only
  the two incompatible libraries (`collections 2.6.0`, `instantiator 2.0.0`), and added
  `composer check-platform-reqs`, the fresh migration sequence and full schema validation to CI.
- Reused the existing accessible-board query for workspace responses and counts; added seven
  authorization regression tests (61 assertions).
- Corrected the README, roadmap, AGENTS, audit, repair report and next-agent handoff, and added
  this report plus the baseline.
- No serializer field was removed; the public-board serializer was reviewed and already sanitized.

**Overall result.** Locally the application is stable and all gates pass (41 tests / 263 assertions,
PHPStan clean, CS 0/54, frontend lint/build/11 tests, fresh-database schema in sync). The one
acceptance item that cannot be completed without a push — a green GitHub Actions run — is
**NOT VERIFIED**, not claimed as fixed.

---

## B. CI investigation

**Failing run:** `43715665395` (push of `5b993fa`), backend job, 22 s.

**Exact failing command:**

```bash
composer install --no-interaction --prefer-dist --no-progress
```

**Actual error (GitHub annotation):**

```text
Your lock file does not contain a compatible set of packages. Please run composer update.
doctrine/collections 3.1.0 requires php ^8.4 -> your php version (8.3.36) does not satisfy it.
doctrine/instantiator 2.1.0 requires php ^8.4 -> your php version (8.3.36) does not satisfy it.
doctrine/orm 3.7.6 requires doctrine/collections ^2.2 || ^3.1 -> satisfiable by collections 3.1.0.
```

**Root cause.** `backend/composer.lock` was generated on the developer's PHP 8.5.12 with no
`config.platform.php`. The solver legitimately picked the newest Doctrine releases, whose minimum
is PHP 8.4. `composer.json` requires `php >=8.3` and CI installs 8.3, so the lock is not
installable on the declared floor. This is a resolution/configuration defect, not a missing PHP
extension or a Symfony/PHPUnit incompatibility.

**Two additional failures were found while replicating the repaired pipeline.**

1. Doctrine's `when@test` config appends `_test` to the configured database name
   (`config/packages/doctrine.yaml`). CI configured `mosaiq_test`, so after the lock fix every test
   command would have connected to nonexistent `mosaiq_test_test`. CI now uses base `mosaiq`
   (matching `backend/.env.test`) and runs `php bin/console doctrine:database:create
   --if-not-exists`, which creates `mosaiq_test`; the Postgres service and healthcheck also use
   `mosaiq`.
2. `ApiFunctionalTestCase` called `SchemaTool::dropSchema()`, which silently ignores failures when
   migration-created foreign-key names differ from metadata-generated names. Once CI began running
   migrations before PHPUnit, the first test tried to recreate existing tables. The harness now
   verifies `current_database()` contains `_test` (also covering ParaTest's `_test1` tokens) and
   calls `dropDatabase()` before recreating the schema, so it works after migrations and refuses to
   reset a non-test database.

**Files changed.**

- `backend/composer.json` — added `config.platform.php = "8.3.0"`.
- `backend/composer.lock` — regenerated; only `doctrine/collections` (3.1.0 → 2.6.0) and
  `doctrine/instantiator` (2.1.0 → 2.0.0) changed version. `collections 2.6` requires
  `symfony/polyfill-php84` instead of `php86`; no package was added or removed.
- `.github/workflows/ci.yml` — base database `mosaiq`, `doctrine:database:create`, the
  five-migration run and full `doctrine:schema:validate` on the disposable test database, plus
  `composer check-platform-reqs`.
- `backend/tests/Controller/ApiFunctionalTestCase.php` — test-database guard and `dropDatabase()`
  reset so PHPUnit works after the CI migration step.

**Corrective action and verification.** No `--ignore-platform-reqs`, `--no-scripts`, disabled test
or suppressed error was used. The lock now reports `platform-overrides: {"php":"8.3.0"}`.
`composer why-not php 8.3.0` reports “no installed package depending on php in versions not
matching”, every backend file was parsed by **PHP 8.3.36** in Docker, and the application commands
in the workflow were run locally. `composer install` itself was not executed under a PHP 8.3
runtime here (only the local 8.5 runtime); its 8.3 compatibility is established by the platform
pin, `why-not`, and the PHP 8.3 parse. `composer check-platform-reqs` passes on the local 8.5
runtime.

**Final CI result:** **NOT VERIFIED.** The workflow has not been re-run because this sprint
forbids pushing. The previous run remains failed; a green run is the first thing to confirm after
an authorized push.

---

## C. Security findings

### C.1 Private board disclosure through workspace responses — FIXED

| Item | Detail |
| --- | --- |
| Endpoint | `GET /api/workspaces/{id}` (and the `boardCount` in `GET /api/workspaces`) |
| Weakness | Any member of a workspace received every board's id, title, member list (including e-mail addresses), note colors, element count and timestamps, even for private boards they had not joined |
| Root cause | The controller checked only `WORKSPACE_VIEW` and serialized `Workspace::getBoards()`, bypassing `BoardVoter` and the already-correct `BoardRepository::queryAccessible()` used by `/api/boards` |
| Fix | Inject `BoardRepository`; serialize `findAccessibleInWorkspace($user, $workspace)` for `show()`; compute `boardCount` from the same list in `index()` and `show()`. Workspace administrators still see every board in their own workspace because that is the existing `BoardVoter` rule |
| Regression test | `backend/tests/Controller/WorkspaceApiControllerTest.php` — 7 tests / 61 assertions |
| Verification | Full suite 41 tests / 263 assertions; response bodies asserted for absence of hidden ids, titles and owner e-mail; every listed board opens through its direct endpoint, every hidden board returns 403 |

The tests cover exactly the required cases:

1. Workspace owner receives authorized summaries and the matching count.
2. Workspace administrator receives all boards in their workspace and is denied another workspace.
3. An ordinary workspace member (not a board member) receives `boards: []`, `boardCount: 0`, and no
   hidden title, id or owner e-mail.
4. A user outside the workspace gets 403, and cannot read the other workspace by swapping ids.
5. Identifier swapping (workspace id, board id, `workspaceId` query) cannot bypass authorization;
   an unknown workspace id returns 404.
6. Hidden board metadata does not appear in `/api/workspaces` or `/api/boards?workspaceId=…`.
7. The workspace board list is consistent with direct `GET /api/boards/{id}` results.

### C.2 Serialization review — no unintended secret fields

Reviewed `BoardSerializer`, `CanvasSerializer`, auth, workspace, board, comment, presence and
invitation responses.

- No serializer reads `User::$passwordHash` or `User::$apiTokenHash`; `/api/auth/me` returns only
  the user's own profile and the token is returned only by login/register.
- Invitation tokens are stored as SHA-256 hashes; the raw token is returned once to the inviter and
  never serialized from the entity.
- Public board payloads (`BoardSerializer::publicDetail`, `CanvasSerializer::publicElement`) omit
  members, comments, timeline and author e-mails, and anonymous writes remain 401/403.
- Board summaries intentionally include member e-mails for **accessible** boards; the share dialog
  displays them (`ShareDialog`). This is the previously recorded B-12 exposure and is left unchanged
  because removing it would change the sharing contract. It is now bounded by the fixed board
  filtering.

### C.3 Authorization consistency (server-side checks)

| Endpoint group | Access rule verified |
| --- | --- |
| `POST /api/auth/login`, `/register` | Public; registration cannot attach to a client-selected workspace |
| `GET /api/auth/me`, `POST /logout` | Authenticated |
| `GET /api/workspaces`, `/{id}` | Authenticated; own workspace only; boards filtered by board access, count filtered |
| `GET /api/boards`, `?workspaceId=` | Authenticated; `queryAccessible` (membership or workspace admin); the query workspace must be the user's own |
| `GET /api/boards/{id}` | `BOARD_VIEW`, or sanitized public payload when `publicAccess` is on |
| `PATCH /api/boards/{id}`, restore | `BOARD_EDIT` |
| `POST /{id}/star` | `BOARD_VIEW` (see remaining issue F-4: board-wide flag) |
| Canvas element CRUD | `BOARD_VIEW` for reads, `BOARD_EDIT` for writes |
| Comments list/reply/resolve | `BOARD_VIEW` / `BOARD_COMMENT` on the thread's board |
| Members, roles, invitations, public access | `BOARD_SHARE`; target user must be in the board workspace |
| Invitation acceptance | Authenticated, unexpired, e-mail-bound and same-workspace |
| Presence/SSE | `BOARD_VIEW` |
| Public board/canvas/timeline reads | Sanitized reads only; writes stay authenticated |

Changing a board id, workspace id, thread id or user id cannot bypass these checks because every
handler loads the resource and runs the voter on the loaded parent; the new tests exercise the
workspace/board variants.

---

## D. Code changes

### Created

| File | Why |
| --- | --- |
| `backend/tests/Controller/WorkspaceApiControllerTest.php` | Seven workspace board-authorization regression tests |
| `docs/sprints/sprint-0-baseline.md` | Measured baseline: commit, tree, architecture, tests, CI failure, defects, non-regression list |
| `docs/sprints/sprint-0-report.md` | This report |

### Modified (Sprint 0)

| File | Change |
| --- | --- |
| `backend/composer.json` | Pin `config.platform.php` to 8.3.0 |
| `backend/composer.lock` | Resolve Doctrine collections 2.6.0 / instantiator 2.0.0 for PHP 8.3 |
| `backend/src/Controller/WorkspaceApiController.php` | Filter workspace boards with `findAccessibleInWorkspace`; filtered `boardCount`; 401 guard |
| `backend/tests/Controller/ApiFunctionalTestCase.php` | Refuse non-`_test` databases; drop all tables so migrated schemas reset cleanly |
| `.github/workflows/ci.yml` | Base `mosaiq` DB, `doctrine:database:create`, platform check, migration sequence, full schema validation |
| `README.md` | Status labels, CI failure/fix, counts, workspace security note, sprint docs |
| `DEVELOPMENT_ROADMAP.md` | Sprint 0 section, accurate counts, phase 7/10 labels, CI run status |
| `AGENTS.md` | PHP 8.3 platform pin, current counts, real CI status |
| `NEXT-AGENT-PROMPT.md` | Replaced the stale completion prompt with an accurate post-Sprint-0 handoff |
| `docs/technical-audit.md` | Sprint 0 addendum: CI root cause, workspace disclosure, current gates |
| `docs/repair-report.md` | Sprint 0 addendum; GitHub run status corrected |

### Pre-existing uncommitted changes (preserved, not authored in Sprint 0)

`backend/.php-cs-fixer.dist.php`, `backend/migrations/Version20261114120000.php`,
`backend/phpstan.neon.dist`, `backend/src/DataFixtures/SyntheticFixtureLoader.php`,
`backend/tests/DataFixtures/SyntheticFixtureLoaderIntegrationTest.php`, plus the matching
documentation edits. They remain part of the working tree and were re-verified by the gates.

### Deleted

**None.**

---

## E. Verification table

| Check | Command | Executed | Result |
| --- | --- | --- | --- |
| Composer validation | `cd backend && composer validate --no-check-publish` | Yes | **PASS** — `./composer.json is valid` |
| Composer audit | `cd backend && composer audit --locked` | Yes | **PASS** — no security advisories |
| PHPUnit | `cd backend && composer test` | Yes | **PASS** — 41 tests, 263 assertions |
| PHPStan | `cd backend && composer analyse` | Yes | **PASS** — `[OK] No errors` (level 5) |
| PHP-CS-Fixer | `cd backend && composer cs:check` | Yes | **PASS** — 0 of 54 files fixable (`migrations/` excluded) |
| TypeScript | `cd frontend && pnpm run build` (`tsc -b`) | Yes | **PASS** |
| ESLint | `cd frontend && pnpm run lint` | Yes | **PASS** |
| Vitest | `cd frontend && pnpm test` | Yes | **PASS** — 7 files, 11 tests |
| Frontend build | `cd frontend && pnpm run build` | Yes | **PASS** — 2207 modules, no chunk warnings |
| Docker config | `docker compose config --quiet` | Yes | **PASS** (exit 0) |
| Docker services | `docker compose ps` / `docker inspect` | Yes | **PASS** — db healthy, mailpit healthy, redis running; Mailpit HTTP 200 |
| Lock compatibility | `cd backend && composer why-not php 8.3.0` | Yes | **PASS** — no package blocks PHP 8.3 |
| Platform requirements | `cd backend && composer check-platform-reqs` | Yes | **PASS** on the local PHP 8.5 runtime (lock's 8.3 compatibility verified separately) |
| PHP 8.3 syntax | `docker run … php:8.3-cli php -l` over `src`/`tests` | Yes | **PASS** — 0 failures on PHP 8.3.36 |
| Fresh migrations | `doctrine:migrations:migrate` on `mosaiq_sprint0_check` | Yes | **PASS** — 5 migrations / 55 SQL statements |
| Fresh schema | `doctrine:schema:validate` on the fresh database | Yes | **PASS** — mapping correct, schema in sync |
| CI-equivalent sequence | `doctrine:database:create` + migrate + schema validate + `phpunit` with base `mosaiq` | Yes | **PASS** — created `mosaiq_test`, 5 migrations, schema in sync, 41 tests / 263 assertions |
| Test database isolation | Query `mosaiq` vs `mosaiq_test` | Yes | **PASS** — Doctrine's `_test` suffix targets `mosaiq_test`; dev fixture data (4 boards) untouched |
| GitHub frontend CI | run `43715665395` | Yes (observed) | **PASS** (32 s) |
| GitHub backend CI | run `43715665395` / re-run | Observed / No re-run | **FAILED before fix** — `composer install`; **NOT VERIFIED after fix** (no push) |
| Browser sign-in | Preview `http://localhost:5179/sign-in` | Yes | **PASS** — French UI rendered, demo account filled the form, no console errors |
| Browser dashboard/editor | Requires running API session | No | **NOT VERIFIED** in this pass |

---

## F. Remaining problems

| # | Problem | Severity | Status / why |
| --- | --- | --- | --- |
| F-1 | No green GitHub Actions run after the repair | **HIGH** | **BLOCKED** — needs an authorized push; every step was run locally and the PHP 8.3 lock is verified, but the runner result is unknown |
| F-2 | No rate limiting on login, registration or invitation endpoints | **HIGH** | Deferred dependency decision (`symfony/rate-limiter`, audit §6); brute-force risk remains |
| F-3 | No real-browser E2E; `E2E.test.tsx` is jsdom with a mocked API | **MEDIUM** | Playwright is not installed; browser sign-in was checked manually, dashboard/editor flows were not |
| F-4 | `Board::$starred` is board-wide but `POST /{id}/star` requires only `BOARD_VIEW` | **MEDIUM** | Product/authorization decision needed: per-user stars require schema/API work; restricting to editors would change Viewer behaviour |
| F-5 | No e-mail verification and no password reset | **MEDIUM** | Out of Sprint 0 scope; needs product/dependency approval |
| F-6 | Bearer token in `sessionStorage`, no CSP | **MEDIUM** | Deferred architecture item B-11; XSS would expose the session |
| F-7 | CRDT/offline collaboration is absent; SSE is last-write-wins | **MEDIUM** | **PLANNED** Node/Yjs service; must not be described as conflict-free |
| F-8 | Workspace roles, dashboard trash and five canvas tools are demo/local | **MEDIUM** | Product features for later sprints; documented as partial |
| F-9 | Board summaries expose member e-mails to board viewers | **LOW** | Used by the share dialog; bounded to accessible boards after C.1 |
| F-10 | Mailpit UI and invite-link visual flow not re-checked | **LOW** | Mailer assertions and API tests pass; browser check not repeated |
| F-11 | GitHub warns that `actions/checkout@v4`/`setup-node@v4`/`pnpm-action@v4` target Node 20 | **LOW** | Runner still executes them; action upgrades are maintenance, not a Sprint 0 defect |

No unresolved **CRITICAL** issue is known.

---

## G. Sprint 1 handoff

**Stable and safe to build on**

- Authentication, workspace/board voters, board CRUD, canvas element validation, comments,
  sharing/invitations, timeline restore, public read-only boards and presence.
- Workspace responses now expose only boards the caller can open, including counts.
- Fresh migrations and schema validation pass; Docker services are healthy; tests are isolated in
  `mosaiq_test` by Doctrine's `_test` suffix, and the harness refuses to reset a non-test database.
- Frontend lint, strict TypeScript, build and 11 jsdom tests pass; FR/EN/AR and themes render.

**Security limitations still open**

- No rate limiting (F-2), no e-mail verification (F-5), token in `sessionStorage` without CSP (F-6),
  board-wide star writable by Viewers (F-4), member e-mails visible to board viewers (F-9).
- Do not claim CRDT, offline merge or browser E2E; the transport remains SSE/last-write-wins.

**Decisions requiring the owner**

- Dependency approval for rate limiting / Validator / OpenAPI (audit §6).
- Per-user vs board-wide stars, dashboard trash semantics, workspace member administration,
  e-mail verification, and the Yjs/Hocuspocus service.
- Authorized push so the repaired CI can be verified; do not mark F-1 complete before a green run.

**Files Sprint 1 should inspect first**

- `backend/src/Controller/WorkspaceApiController.php`, `backend/src/Repository/BoardRepository.php`,
  `backend/src/Security/BoardVoter.php`, `WorkspaceVoter.php`, `BoardSerializer.php`.
- `backend/composer.json`, `backend/composer.lock`, `.github/workflows/ci.yml`.
- `backend/tests/Controller/WorkspaceApiControllerTest.php` and
  `docs/sprints/sprint-0-baseline.md`.
- `frontend/src/api/client.ts` and the screens before changing wire contracts.

**Do not reintroduce**

- Unfiltered `Workspace::getBoards()` in any workspace response or count.
- Public boards in another user's board list; they remain direct-link only.
- A PHP 8.4 lock or dependency resolution without the 8.3 platform pin.
- Database resets, destructive migrations or tests against the dev database.
- Documentation claiming CI is green, CRDT works, workspace roles persist, trash is real, or all
  canvas tools create content.

---

## Acceptance checklist

- [x] Latest repository state inspected and baseline recorded.
- [x] Working tree and pre-existing user changes preserved; no destructive Git operation.
- [x] Actual Composer failure retrieved, root cause documented, minimal fix implemented.
- [ ] Frontend CI passes on GitHub — passed in run `43715665395`; no new run needed for the
      frontend change set (frontend code untouched), but the run itself is pre-fix.
- [ ] Backend CI passes on GitHub — **NOT VERIFIED**; local equivalent and PHP 8.3 parse pass.
- [x] Workspace board disclosure investigated and fixed; nested/count leakage covered.
- [x] Serializer fields reviewed; no secret/token/password field exposed.
- [x] Authorization consistency reviewed across endpoint groups.
- [x] Regression and identifier-manipulation tests added.
- [x] Doctrine schema validation passes; migration status inspected.
- [x] Fresh-database migration sequence verified on a disposable database.
- [x] Docker services and configuration verified; persistent data preserved.
- [x] TypeScript, ESLint, Vitest, production build pass.
- [x] README, roadmap, next-agent prompt, audit and repair report corrected.
- [x] Sprint 0 baseline and report created.
