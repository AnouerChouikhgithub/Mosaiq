# Mosaiq repair report

**Date:** 2026-11-15
**Branch / HEAD:** `main` at `4f3689c`, tracking `origin/main`
**Working tree:** intentionally uncommitted; no commit, push, reset or discard was performed.
`NEXT-AGENT-PROMPT.md` was left untouched. The persistent Compose database volume was not reset.
**Change scope:** 41 tracked files modified, 7 created, 0 removed; no new package was installed.

---

## 1. Executive summary

The repository was in better shape than its brief assumed: the frontend is a complete, typed
eight-screen application with a design system, not a Vite starter, and the backend has a real
domain model, migrations, voters and functional tests. The audit found three categories of
problems:

1. **Setup and quality gates that did not work as documented.** The root `pnpm run lint` and
   `pnpm run build` failed because the wrapper called `npm --prefix frontend lint` instead of
   `npm run lint`; PHPStan had no configuration or paths; PHP-CS-Fixer failed on 20 files and was
   not gated; `composer.json` advertised PHP 8.2 while the locked PHPUnit requires 8.3.
2. **Real defects in normal use.** Renaming a board was never persisted although the UI said
   "saved"; the fixture loader wrote every connector endpoint to `toId`; invalid canvas geometry
   was silently stored at `0,0`; a duplicate display name made registration return 500; public
   boards leaked into other workspaces' board lists; comment creation used `window.prompt`; and
   accessible labels were hard-coded in French in an FR/EN/AR app.
3. **Unfixed architecture debt that needs approval.** API Platform/OpenAPI, JWT, Messenger/Redis,
   rate limiting, email verification, Playwright, and the Node + Yjs/Hocuspocus realtime service
   are not installed. None of them can be added under the repository's dependency gate without
   an explicit decision, and the current SSE transport must not be described as CRDT.

All defects that could be fixed with installed dependencies were fixed and verified. The backend
suite grew from 27 to 33 tests; the frontend from 10 to 11. Ten Symfony security advisories were
cleared by a patch-level update. Everything else is recorded below with its blocker.

---

## 2. Original problems identified

Full evidence is in [`technical-audit.md`](technical-audit.md). Summary:

| ID | Priority | Problem | Status after this pass |
| --- | --- | --- | --- |
| B-01 | P1 | `php >=8.2` cannot satisfy locked PHPUnit 12 (`>=8.3`) | **Fixed** |
| B-02 | P1 | Fixture loader maps both connector endpoints to `toId` | **Fixed and verified on existing data** |
| B-03 | P2 | Non-numeric canvas geometry silently stored as `0.0` | **Fixed** |
| B-04 | P2 | Duplicate workspace name returns 500 on registration | **Fixed** |
| B-05 | P2 | No Validator/Serializer/OpenAPI/pagination | Blocked by approval |
| B-06 | P2 | Ad-hoc error format, no central exception mapping | Deferred |
| B-07 | P3 | One opaque token per user, no expiry/rotation | Deferred |
| B-08 | P3 | One flush per timeline record; large snapshots | Deferred |
| B-09 | P2 | Public boards listed across workspaces | **Fixed** |
| B-10 | P1 | No auth rate limiting, email verification or password policy | Blocked by approval |
| B-11 | P2 | Bearer token in `sessionStorage`, no CSP | Deferred (architecture) |
| B-12 | P3 | Board payloads expose member emails | Deferred |
| F-01 | P1 | Root npm wrapper scripts broken | **Fixed** |
| F-02 | P1 | Board title edits never persisted | **Fixed** |
| F-03 | P2 | "Trash" only hides a card locally | Deferred (product decision) |
| F-04 | P2 | Workspace roles change locally only | Deferred (new endpoints) |
| F-05 | P2 | Five of eight canvas tools do nothing | Documented, not implemented |
| F-06 | P2 | Comment creation uses `window.prompt` | **Fixed** |
| F-07 | P2 | Hard-coded French names in shared components | **Fixed** |
| F-08 | P2 | API error bodies discarded, raw transport text shown | **Fixed** |
| F-09 | P3 | No error boundary / not-found screen | Deferred |
| F-10 | P2 | Mock/API identities matched by display name | Deferred |
| I-01 | P3 | Redis/Mailpit had no readiness or restart policy | **Fixed in config** (applies on next `up -d`) |
| I-03 | P3 | `backend/compose.override.yaml` is unreferenced | Deferred (needs owner confirmation) |
| I-04 | P2 | CI did not run PHPStan, CS-Fixer, validate or audit | **Fixed in workflow** (not run on GitHub) |
| D-01…D-05 | P1–P3 | Documentation contradicted the implementation | **Mostly fixed**; OpenAPI/architecture docs remain |
| Q-01 | P2 | PHPStan unconfigured (17 errors at level 5) | **Fixed** (level 5, 0 errors) |
| Q-02 | P2 | PHP-CS-Fixer failed and was ungated | **Fixed** (0/58, gated) |
| Q-03 | P2 | Tests do not cover every screen/edge path | Partially improved |

---

## 3. Problems corrected

Status key: **FIXED** = changed and verified by a command; **FIXED (not fully verified)** = changed,
compiles/tests pass, but the full user flow was not exercised in a browser.

### 3.1 Root commands — FIXED

* **File:** `package.json`
* **Problem:** `"lint": "npm --prefix frontend lint"` and the same pattern for `build`, `dev` and
  `test`. Only `npm start`/`npm test` are valid shorthands; the others require `npm run`.
* **Change:** all forwarded scripts now use `npm --prefix frontend run <script>`.
* **Why:** the documented first-line quality gate from the repository root now works. Verified by
  running the exact root commands (lint, build, test) successfully.

### 3.2 PHP version alignment — FIXED

* **Files:** `backend/composer.json`, `README.md`, `AGENTS.md`
* **Change:** `"php": ">=8.3"` (the locked PHPUnit 12 floor and the CI version). `composer.lock`
  was refreshed with `composer update --lock` so its content hash matches.
* **Why:** PHP 8.2 installs previously failed dependency resolution. `composer validate` now
  reports a valid manifest *and* lock file.

### 3.3 Connector fixture mapping — FIXED

* **File:** `backend/src/DataFixtures/SyntheticFixtureLoader.php`
* **Problem:** the key selector compared `'fromId' === $relation` inside a loop over
  `['fromKey', 'toKey']`, which is always false. Both endpoints were written to `toId`, so seeded
  connectors had no `fromId` and did not render.
* **Change:** map `fromKey → fromId` and `toKey → toId`. Resolution now happens before the
  create/exists branch, and an existing element with divergent metadata is repaired in place via
  `updateStyle()`, so already-seeded databases heal on the next fixture load without deletion.
* **Verified:** new integration test (21 assertions) plus a real reload of the local dev database;
  all 3 connector rows now contain distinct `fromId`/`toId`.

### 3.4 Canvas geometry validation — FIXED

* **Files:** `backend/src/Service/BoardService.php`, `backend/src/Controller/CanvasApiController.php`
* **Problem:** `(float) "left"` is `0.0`, so malformed create/update payloads stored elements at the
  origin with no error. Metadata lists were accepted as "objects", and non-string content/color
  could trigger PHP conversion warnings.
* **Change:** every supplied `x`, `y`, `width`, `height`, `rotation` must be numeric and finite;
  `type` must be a string; `metadata` must be a JSON object (not a list); `content`/`color` must be
  string or null. Violations return `422` with a field-specific message.
* **Verified:** `CanvasApiControllerTest` asserts 422 and that no row was created / the stored
  width is unchanged.

### 3.5 Duplicate workspace name — FIXED

* **Files:** `backend/src/Entity/Workspace.php`, `backend/migrations/Version20261115090000.php`
* **Problem:** registration derives `"{name} Workspace"` and the schema enforced uniqueness on
  `workspaces.name`; the second account with the same display name raised an uncaught database
  exception (500).
* **Change:** workspace names are labels, not identifiers. A new additive migration replaces
  `uniq_workspaces_name` with the non-unique `idx_workspaces_name`; the entity metadata matches.
* **Verified:** new test registers two accounts with the same display name and expects `201` with
  distinct workspace ids. Migration applied to the local DB (1 migration, 2 SQL statements) and
  `doctrine:schema:validate` reports the schema in sync.

### 3.6 Cross-workspace public board listing — FIXED

* **File:** `backend/src/Repository/BoardRepository.php`
* **Problem:** the accessible-board DQL included `board.publicAccess = true` without a workspace
  constraint, so any authenticated user's dashboard listed every public board in the database.
* **Change:** listing is now membership plus workspace-administrator scope only. Public boards
  remain readable through their direct link, which is the intended sharing model.
* **Verified:** new test confirms an outsider can open the public board by id but does not see it
  in `GET /api/boards`.

### 3.7 PHPStan configuration and real findings — FIXED

* **Files:** `backend/phpstan.neon.dist` (new), `backend/src/Security/ApiTokenAuthenticator.php`,
  `backend/src/Controller/AuthApiController.php`, `backend/src/Controller/CommentApiController.php`,
  `backend/tests/bootstrap.php`
* **Problem:** no paths, no level, no script, no CI gate. At level 5 it reported 17 issues, most of
  them real or actionable.
* **Change:** committed config at **level 5** over `src` and `tests`; removed two unused
  constructor dependencies; narrowed two return types; removed a redundant `method_exists`; fixed
  the fixture comparison. Doctrine-managed entity ids and two intentionally write-only invitation
  fields are excluded by *path-scoped, commented* rules, not blanket suppression.
* **Verified:** `composer analyse` → `[OK] No errors`.

### 3.8 PHP-CS-Fixer — FIXED

* **Files:** `backend/composer.json`, 20 reformatted files
* **Change:** added `cs:check` / `cs:fix` scripts and ran the project's own fixer. Changes are
  formatting only (import order, trailing newlines, brace/line layout). No logic was altered;
  the full suite passes afterwards.
* **Verified:** `composer cs:check` → 0 of 58 files fixable.

### 3.9 Editor title persistence — FIXED

* **Files:** `frontend/src/api/client.ts`, `frontend/src/screens/BoardEditorScreen.tsx`
* **Problem:** the title input changed local state only, while the header displayed a check icon
  and "Enregistré".
* **Change:** added `updateBoard(id, patch)` (PATCH `/api/boards/{id}`); the editor commits on blur
  or Enter, rolls back and reports failure, and the saved indicator now distinguishes
  "saving", "saved" and "changed — not saved". Demo boards stay local without claiming a save.
* **Verified:** `LiveBadge.test.tsx` renames the title and asserts
  `updateBoard(boardId, { title })` was called and the saved value is displayed.

### 3.10 Accessible comment composer — FIXED

* **File:** `frontend/src/screens/BoardEditorScreen.tsx`
* **Problem:** new comments used `window.prompt`, which cannot be styled, localised or validated
  and behaves inconsistently with assistive technology.
* **Change:** clicking with the comment tool opens the existing Radix dialog with a labelled
  textarea, Cancel and Send; failures keep the dialog usable and show a toast.
* **Verified:** lint, typecheck, build and the existing editor interaction test pass. The new
  dialog flow was not clicked through in a real browser.

### 3.11 Hard-coded French accessible names — FIXED

* **Files:** `frontend/src/components/board/BoardCanvas.tsx`,
  `frontend/src/components/ui/dialog.tsx`, `frontend/src/i18n/dictionary.ts`
* **Change:** added `canvas.aria`, `canvas.previewAria`, `canvas.selectNote`, `canvas.noteText`,
  `common.close`, `editor.space` and the new title/comment strings for FR, EN and AR; components
  now read them through `useI18n()`.
* **Verified:** i18n test still passes for all three locales and RTL; lint/build pass.

### 3.12 API error messages — FIXED

* **File:** `frontend/src/api/client.ts`
* **Problem:** `{ "error": "…" }` was discarded and users saw `API 422 … for /auth/register`.
* **Change:** the client parses the API error body when present, attaches `status`, and falls back
  to transport text only for non-JSON responses.
* **Verified:** covered indirectly by existing client tests; no dedicated assertion was added, so
  this is **implemented but not specifically unit-tested**.

### 3.13 CI and development infrastructure — FIXED IN CONFIG

* **Files:** `.github/workflows/ci.yml`, `docker-compose.yml`
* **Change:** CI now runs `composer validate`, `composer audit --locked`, PHPStan, PHP-CS-Fixer and
  PHPUnit in addition to container/schema checks, with job timeouts. Compose gained restart
  policies and a Redis readiness check; the database probe gained a start period.
* **Verified:** `docker compose config --quiet` passes; `mosaiq-db` and `mosaiq-mailpit` are
  healthy. The new Redis healthcheck applies when the existing container is next recreated; it was
  not restarted during the audit. **The GitHub workflow has not run on GitHub.**

### 3.14 Security patch update — FIXED

* **Files:** `backend/composer.json`, `backend/composer.lock`
* **Problem:** `composer audit --locked` reported **10 advisories affecting 7 packages** in the
  locked Symfony 7.4.22 set (XXE in Config, path traversal in Filesystem, HEAD/access-control
  bypass and host-spoofing issues in HttpFoundation, proxy/URL issues in FrameworkBundle and
  HttpKernel, a routing/access-control bypass in Routing).
* **Change:** patch-level update within `7.4.*` to **7.4.23** for the affected components
  (`framework-bundle`, `http-foundation`, `http-kernel`, `routing`, `config`, `filesystem`,
  `security-bundle`, `console`, `yaml`, `cache`, BrowserKit/DomCrawler, plus supporting
  polyfills). No major or minor version changed; `symfony/flex` was bumped to `^2.12` by
  Composer's `bump-after-update` setting.
* **Verified:** `composer audit --locked` → **"No security vulnerability advisories found."**;
  PHPStan, CS-Fixer and all 33 tests pass afterwards.

---

## 4. Problems not corrected and why

### 4.1 Blocked by the dependency-approval gate

| Item | Why blocked | What approval must cover |
| --- | --- | --- |
| API Platform, Symfony Validator/Serializer, OpenAPI | Changes the controller layer and installs packages | Resource/operation model; whether to migrate existing endpoints |
| JWT (Lexik + web-token) | Replaces the working opaque-token authenticator | Token/session model, key management, multi-device support |
| Messenger + Redis transport | Installs packages and gives Redis a real role | Which messages are async (invitations, timeline) and retry policy |
| Rate limiting, email verification | New first-party packages + product policy | Limits, lockout policy, verification UX |
| PHPStan Doctrine/Symfony extensions, Playwright, axe | Dev dependencies | Static-analysis target level and E2E scope |

The consolidated proposal is in [`technical-audit.md`](technical-audit.md) §6. No package was
installed in this pass; only existing Symfony components were patched for security.

### 4.2 Deferred intentionally

* **Dashboard trash (F-03).** Implementing it requires a product decision between hard delete and
  soft delete with retention. Adding a destructive endpoint silently would be worse than the
  current demo control. The UI remains local-only and is now documented as such.
* **Workspace settings roles (F-04).** Needs workspace membership endpoints plus server
  authorization and tests; the screen still shows mock data and local role changes.
* **Shape/text/pen/image tools (F-05).** A feature build, not a repair. Only select, note and
  comment are wired; connectors and frames render from data.
* **Realtime CRDT (B/A-09).** The brief and `AGENTS.md` describe different strategies. The SSE
  implementation is functional and tested; no second sync path was added. §5 of the audit records
  the integration design and the single-transport rule.
* **Token model, CSP, email minimisation (B-07, B-11, B-12).** Architecture changes deferred until
  the auth/API decision.
* **Exception listener and error contract (B-06).** Partially mitigated by explicit messages and
  the client parsing `{ error }`; the full RFC 7807 layer belongs with the API foundation.
* **Timeline flush/snapshot efficiency (B-08), identity bridge (F-10), error boundary (F-09).**
  No current correctness impact; listed as debt.
* **`backend/compose.override.yaml` (I-03).** Committed configuration was not deleted without
  confirmation. It is unreferenced and duplicates the root Mailpit service.

---

## 5. Architecture changes

**None to the application structure.** The requested `app/` + `features/` reorganisation was
evaluated and deliberately not applied: the repository already separates theme, i18n, mocks,
reusable components, domain components, screens, API and backend layers, and moving working files
would create a large diff with no behavioural or dependency benefit. This follows the brief's
"do not introduce unnecessary abstractions" rule.

What *did* change architecturally:

1. **Public listing no longer doubles as discovery.** Accessible boards are membership/workspace
   scoped; public access is a direct-link capability only.
2. **Workspace name is no longer a unique key.** It is a label with a lookup index; identity stays
   the UUID. This removes a registration failure mode.
3. **Fixture loading repairs, not just creates.** Divergent connector metadata is corrected in
   place, keeping the loader idempotent for both fresh and previously seeded databases.
4. **Canvas input is validated at the service boundary** instead of relying on PHP casts.
5. **Quality gates are part of the build definition**, not just installed binaries: PHPStan level 5
   over `src` + `tests`, CS-Fixer dry run, Composer validation and advisory audit.

The proposed target architecture (DTO/Validator/Exception layers; API Platform State
providers/processors only if approved) is documented but not implemented.

---

## 6. Dependency changes

| Change | Before | After | Reason |
| --- | --- | --- | --- |
| PHP platform requirement | `>=8.2` | `>=8.3` | Locked PHPUnit 12 requires 8.3; CI already used 8.3 |
| `symfony/framework-bundle` | v7.4.21 | v7.4.23 | Security advisories |
| `symfony/http-foundation` | v7.4.21 | v7.4.23 | 4 advisories (HEAD bypass, host spoofing, path rules) |
| `symfony/http-kernel` | v7.4.21 | v7.4.23 | Forwarded-header host injection |
| `symfony/routing` | v7.4.21 | v7.4.23 | Access-control bypass |
| `symfony/config` | v7.4.17 | v7.4.23 | XXE/local file read |
| `symfony/filesystem` | v7.4.21 | v7.4.23 | Base-directory escape |
| `symfony/security-bundle` | v7.4.22 | v7.4.23 | Coordinated patch set |
| `symfony/console`, `yaml`, `cache`, BrowserKit, DomCrawler, polyfills | earlier 7.4.x / 1.x | 7.4.23 / current patch | Patch alignment |
| `symfony/flex` | `^2` | `^2.12` | Composer `bump-after-update` |
| New packages | — | **none** | Approval gate respected |

No package was added, removed or moved between `require` and `require-dev`.

---

## 7. Security improvements

1. **Cleared all known advisories** in the locked dependency set (10 → 0), including an XXE/local
   file read and an access-control bypass in the exact Symfony components the API uses.
2. **Removed cross-workspace public board enumeration** from `/api/boards`. Public sharing remains
   available by direct link, and anonymous payloads stay sanitized (no members, comments,
   timeline or emails).
3. **Rejected invalid canvas geometry and payload shapes** before persistence, preventing silent
   data corruption and conversion-warning leakage.
4. **Eliminated a 500-producing registration path** (duplicate workspace name), reducing an
   availability/abuse vector and making the error surface predictable.
5. **No secret was read, printed or committed.** `.env` contents were only inspected for variable
   names and `APP_SECRET` presence/length. The production secret remains environment-supplied and
   the tracked default is empty.
6. **Existing protections confirmed:** server-side voters for every board/workspace action, hashed
   tokens at rest, single-use hashed invitations with expiry and email/workspace matching,
   loopback-only development CORS, and no anonymous writes.
7. **Client error handling** now surfaces API messages rather than raw transport strings; no
   sensitive server detail is added to responses.

Remaining security work is explicitly not done: rate limiting, email verification, token
expiry/rotation, CSP, and reducing member email exposure. They are blocked or deferred above.

---

## 8. Testing performed

* **Backend:** full PHPUnit suite after every change that touched PHP, plus targeted runs:
  * new canvas validation cases (invalid `x`, invalid `width`, list metadata, non-string type);
  * duplicate display-name registration;
  * public board not listed for another workspace;
  * fixture integration (connector endpoints distinct and resolvable; second load creates 0 rows).
* **Frontend:** root lint, production build and Vitest after the client/editor/i18n changes;
  new test for board-title persistence.
* **Static analysis:** PHPStan level 5 over `src` and `tests`; PHP-CS-Fixer dry run over 58 files.
* **Composer:** `validate`, `check-platform-reqs`, `audit --locked`, lock refresh.
* **Database:** migration applied to the local dev database; `doctrine:schema:validate`; fixture
  reload; direct SQL check of connector metadata.
* **Infrastructure:** `docker compose config --quiet`; `docker compose ps`.
* **Documentation:** every command in the README check table was executed in this pass, except the
  GitHub Actions run, which cannot be executed from this machine.

Not performed: real-browser click-through of the new comment dialog or title save, two-client
collaboration, invite-link signup, Playwright/axe, Lighthouse, PHP 8.3 runtime test (local runtime
is PHP 8.5.12; CI targets 8.3), and the GitHub-hosted CI run.

---

## 9. Exact test results

| Command | Result |
| --- | --- |
| `pnpm run lint` (root) | **PASS** — ESLint, exit 0 |
| `pnpm run build` (root) | **PASS** — `tsc -b` + Vite 8.3.4, 2207 modules |
| `pnpm test` (root) | **PASS** — 7 files, **11 tests**, ~25 s |
| `composer validate --no-check-publish` | **PASS** — `./composer.json is valid` |
| `composer check-platform-reqs` | **PASS** — no missing platform requirement on PHP 8.5.12 |
| `composer audit --locked` | **PASS** — no advisories |
| `composer analyse` (PHPStan 2.3.1, level 5) | **PASS** — `[OK] No errors` |
| `composer cs:check` (PHP-CS-Fixer 3.95.27) | **PASS** — 0 of 58 files fixable |
| `composer test` / `php bin/phpunit` | **PASS** — **33 tests, 194 assertions**, ~68–82 s |
| `php bin/console lint:container` | **PASS** |
| `php bin/console doctrine:schema:validate` | **PASS** — mapping correct, database in sync |
| `doctrine:migrations:migrate --no-interaction` | **PASS** — `Version20261115090000`, 1 migration, 2 SQL queries |
| Fixture connector check (`psql`) | **PASS** — 3 connectors, 3 with both endpoints, 0 identical |
| `php bin/console app:fixtures:load` | **PASS** — 0 created on reload (idempotent) |
| `docker compose config --quiet` | **PASS** |
| `docker compose ps` | `mosaiq-db` healthy, `mosaiq-mailpit` healthy, `mosaiq-redis` running |
| GitHub Actions | **NOT RUN** — workflow updated only |

---

## 10. Build results

```
dist/index.html                          2.17 kB │ gzip:  0.96 kB
dist/assets/index-*.css                 37.57 kB │ gzip:  8.07 kB
dist/assets/rolldown-runtime-*.js        0.59 kB │ gzip:  0.36 kB
dist/assets/index-*.js                 142.17 kB │ gzip: 36.69 kB
dist/assets/ui-*.js                    162.99 kB │ gzip: 53.56 kB
dist/assets/vendor-*.js                247.30 kB │ gzip: 78.85 kB
```

No chunk-size warnings. The application chunk grew by ~4 kB because of the dialog-based comment
composer and title-save logic; vendor/UI chunks are unchanged.

---

## 11. Files created

| File | Purpose |
| --- | --- |
| `docs/technical-audit.md` | Phase 0 audit: baseline, findings, priorities, verification plan, dependency proposal, realtime design |
| `docs/repair-report.md` | This report |
| `backend/phpstan.neon.dist` | PHPStan level 5 configuration over `src` + `tests`, with two documented, path-scoped Doctrine ignores |
| `backend/migrations/Version20261115090000.php` | Replace the unique workspace-name constraint with a lookup index |
| `backend/tests/Controller/CanvasApiControllerTest.php` | Geometry/payload validation cases |
| `backend/tests/DataFixtures/SyntheticFixtureLoaderIntegrationTest.php` | Connector endpoint resolution + idempotent reload |
| `frontend/README.md` | Frontend commands, structure, API configuration and limitations |

---

## 12. Files modified

Functional changes (formatting-only files from the CS-Fixer run are listed separately):

| File | Change |
| --- | --- |
| `package.json` | Correct `npm run` forwarding for all root scripts |
| `backend/composer.json` | PHP >= 8.3; `test`/`analyse`/`cs:check`/`cs:fix`/`check` scripts; Flex constraint |
| `backend/composer.lock` | Lock hash + Symfony 7.4.23 security patch set |
| `backend/src/Controller/AuthApiController.php` | Removed unused `WorkspaceRepository` dependency |
| `backend/src/Controller/CanvasApiController.php` | Numeric/finite geometry, content/color/metadata validation |
| `backend/src/Controller/CommentApiController.php` | Removed unused `UserRepository` dependency |
| `backend/src/Service/BoardService.php` | Element input validation (type, geometry, metadata object) |
| `backend/src/Repository/BoardRepository.php` | Accessible-board scoping no longer includes all public boards |
| `backend/src/DataFixtures/SyntheticFixtureLoader.php` | `fromKey`/`toKey` mapping fix + in-place metadata repair |
| `backend/src/Entity/Workspace.php` | Unique constraint → non-unique index |
| `backend/src/Security/ApiTokenAuthenticator.php` | Narrowed return types surfaced by PHPStan |
| `backend/tests/bootstrap.php` | Removed redundant `method_exists` guard |
| `backend/tests/Controller/AuthApiControllerTest.php` | Duplicate display-name registration case |
| `backend/tests/Controller/BoardApiControllerTest.php` | Public board listing isolation case (+ import cleanup) |
| `backend/tests/DataFixtures/SyntheticFixtureLoaderTest.php` | Replaced internal `addToAssertionCount` usage |
| `.github/workflows/ci.yml` | Validate, audit, PHPStan, CS-Fixer, timeouts |
| `docker-compose.yml` | Restart policies, Redis healthcheck, DB start period |
| `frontend/src/api/client.ts` | API error parsing + `updateBoard` |
| `frontend/src/screens/BoardEditorScreen.tsx` | Persisted title, comment dialog, i18n labels, saved state |
| `frontend/src/components/board/BoardCanvas.tsx` | Localised accessible names |
| `frontend/src/components/ui/dialog.tsx` | Localised close label |
| `frontend/src/i18n/dictionary.ts` | FR/EN/AR keys for canvas, title state and comment composer |
| `frontend/src/tests/LiveBadge.test.tsx` | Title persistence test + `updateBoard` mock |
| `frontend/src/tests/E2E.test.tsx` | `updateBoard` added to the module mock |
| `README.md` | Reality-aligned commands, status labels, results, security notes |
| `AGENTS.md` | PHP floor, gates, command forms, audit references |
| `DEVELOPMENT_ROADMAP.md` | Audit addendum; Phase 7 labelled interim SSE |
| `docs/ui-spec.md` | Status note resolving the mock-only/API-integration conflict |

Formatting-only (PHP-CS-Fixer), no logic change:
`BoardApiController.php`, `PresenceApiController.php`, `WorkspaceApiController.php`,
`BoardVoter.php`, `BoardInvitationMailer.php`, `TimelineService.php`, `Entity/Board.php`,
`Entity/BoardInvitation.php`, `Entity/BoardPresence.php`, `Repository/BoardPresenceRepository.php`,
`migrations/Version20261114120000.php`, `tests/Controller/ApiFunctionalTestCase.php`,
`tests/Controller/PresenceApiControllerTest.php`.

---

## 13. Files removed

**None.** No source, test, asset, skill or configuration file was deleted.

`backend/compose.override.yaml` was retained despite being unreferenced: it is committed
configuration and deleting it needs owner confirmation. It is recorded as debt (I-03) with a
recommended action.

---

## 14. Remaining technical debt

**Security / correctness**
1. No login/registration rate limiting, email verification or robust password policy (P1 before
   production).
2. Single opaque token per user, no expiry/rotation; token in `sessionStorage`; no CSP.
3. Board payloads include member emails; split user summary/self serializers.
4. Ad-hoc error shape; no central exception listener (some uncaught failures can escape).
5. `backend/compose.override.yaml` is dead configuration; Redis has no application role yet.

**Product gaps presented as implemented**
6. Dashboard trash and workspace role controls are local-only demo paths; the UI exposes them.
7. Five canvas tools are inert; shapes/text are not part of the element model.
8. No board deletion, undo/redo, multi-select, or offline queue.

**Architecture / scale**
9. Hand-written wire types vs three PHP serializers — no OpenAPI source of truth.
10. No pagination on boards/comments/timeline; timeline snapshots store full element arrays and
    flush per record.
11. Mock/API identities are matched by display name; must become server-id based.
12. No error boundary, not-found screen, or loading/error states on several lists.

**Verification**
13. CI has never run on GitHub; wide-screen/tablet and screen-reader passes are unrecorded.
14. No real-browser E2E, axe or performance checks; the "E2E" suite is jsdom with a mocked API.
15. Local runtime is PHP 8.5; PHP 8.3 (the declared floor) is only exercised in CI config.

---

## 15. Recommended next development steps

1. **Decide and approve the dependency plan** in `docs/technical-audit.md` §6. Without this,
   API Platform, JWT, Messenger, rate limiting and Playwright cannot proceed.
2. **Resolve the realtime strategy explicitly** — keep SSE as the documented interim transport, or
   commission the Node + Yjs/Hocuspocus service from the audit's integration table. One transport,
   not two. Test with two clients before claiming collaboration.
3. **Finish the auth hardening** first among security work: rate limiting, email verification,
   token expiry/multi-device, and a CSP. These are prerequisites for any public deployment.
4. **Make the demo controls honest**: implement soft-delete + trash retention, workspace member
   endpoints, or disable those controls with an explicit "demo" affordance.
5. **Introduce a contract source**: API Platform resources + OpenAPI, then generate or validate the
   TypeScript client. Split DTO/validator/exception layers as part of the same change.
6. **Add Playwright** with a real browser: sign-in, editor edit, replay, share dialog, comment
   composer, theme/locale, and signed-out public board; add axe checks to the same suite.
7. **Add pagination and query budgets** where collections grow (boards, comments, timeline) and
   compact timeline snapshots.
8. **Run the CI workflow on GitHub** and record the first real result; keep the reported status
   table synchronized with executed commands only.

---

### Final status categories

* **Confirmed fixed and verified:** root scripts; PHP floor; connector fixture mapping (including
  existing data); geometry validation; duplicate workspace name; public listing scope; PHPStan
  configuration; CS-Fixer; editor title persistence; French accessible labels; API error parsing;
  Symfony advisories; Compose/CI configuration (local config verified, GitHub run pending).
* **Implemented but not fully verified:** comment composer dialog; localised canvas labels in a
  real browser; Redis healthcheck on a recreated container; title-save visual flow.
* **Blocked by dependency approval:** API Platform/OpenAPI, JWT, Messenger/Redis, rate limiting,
  email verification, Playwright/axe, Doctrine/Symfony PHPStan extensions.
* **Deferred intentionally:** CRDT realtime, dashboard trash, workspace role persistence,
  shape/text/pen/image tools, token model/CSP/email minimisation, pagination, error boundary.
