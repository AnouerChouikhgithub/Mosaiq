# Sprint 4 — correctness and security stabilization

**Result:** COMPLETE for the four confirmed defects and the privacy review. One related consistency
gap (board create/rename) is documented as a limitation instead of being silently changed.

**Date:** 2026-11-17
**Branch:** `fix/sprint-4-correctness-security`
**Base commit:** `11a2ae0` (Sprint 3, `main` = `origin/main`)

## 1. Scope and method

The review findings were re-checked against the current tree before any change. Each confirmed
defect was reproduced with a regression test at the level the defect lives at (HTTP endpoint for the
backend, real database state for the transaction, component + reconciliation logic for the editor).

The working tree was clean and based on the latest `origin/main` when the branch was created; no
pre-existing uncommitted work was touched, and no history was rewritten.

## 2. Task 1 — public canvas access after archive/trash

**Original problem.** `CanvasApiController::index()` authorized the request with `BOARD_VIEW` and, on
denial, allowed the request when the board merely had public access enabled.

**Root cause.** The anonymous fallback was written before Sprint 3 introduced the board lifecycle.
`BoardVoter` denies `BOARD_VIEW` for archived and trashed boards, so every such request fell into the
`AccessDeniedException` branch, where only `hasPublicAccess()` was consulted. `BoardApiController::show()`
and `timeline()` already combined the public flag with `isActive()`; the canvas endpoint did not.

**Fix.** `backend/src/Controller/CanvasApiController.php`:

```php
if (!$board->hasPublicAccess() || !$board->isActive()) {
    return $this->json(['error' => 'Forbidden.'], 403);
}
```

No lifecycle logic is duplicated: `isActive()` is the entity's own predicate, and the authenticated
path is untouched, so `BoardVoter` still owns membership authorization.

**Regression tests** (`backend/tests/Controller/CanvasApiControllerTest.php`, 8 tests / 48 assertions;
five new tests cover `GET /api/boards/{id}/elements`):

- anonymous access to an active public board returns `200` with its elements;
- anonymous access to an archived public board returns `403`;
- anonymous access to a trashed public board returns `403`;
- anonymous access to a private board returns `403`;
- an authenticated board viewer still receives the elements of an active board.

## 3. Task 2 — JSON object validation

**Original problem.** `JsonPayloadTrait::decodePayload()` called
`json_decode($content, true, 512, JSON_THROW_ON_ERROR)` and rejected only non-arrays.

**Root cause.** With associative decoding, JSON objects and JSON arrays both become PHP arrays, so
`[]`, `["unexpected"]`, `"text"`, `123` and `null` either satisfied the array check or produced a
value the controllers silently treated as an empty payload.

**Fix.** `backend/src/Controller/JsonPayloadTrait.php` decodes once without associative mode, requires
the top-level value to be a `stdClass`, and then normalises it recursively to the associative
structure every existing caller expects (`normalizeJsonValue()`). Nested objects stay associative and
nested lists stay lists, so per-controller validation (`422` for invalid fields) is unchanged, and
`JsonException` still maps to the existing `400 Malformed JSON payload` response.

**Regression tests** (`backend/tests/Controller/BoardApiControllerTest.php`, 19 tests / 91 assertions):

| Payload | Result |
| --- | --- |
| `{"name":"test"}` | `200`, no-op for unknown keys |
| `{"title":"test"}` | `200`, title updated |
| `{}` | `200`, no-op |
| `[]` | `422` |
| `["unexpected"]` | `422` |
| `"text"` | `422` |
| `123` | `422` |
| `null` | `422` |
| `{"title":` (malformed) | `400` |

The trait was exercised through a real endpoint; `ApiFunctionalTestCase::requestWithRawBody()` was
added so a test can send an exact body instead of a PHP array.

## 4. Task 3 — canvas mutation and timeline atomicity

**Original problem.** Element create/update/delete flushed the canvas change and then recorded the
timeline snapshot. A failing timeline write left the canvas mutation committed.

**Root cause.** No shared transaction wrapped the two writes; `TimelineService::restore()` was the only
path using one.

**Fix.** `backend/src/Controller/CanvasApiController.php` wraps create, update and delete plus their
`TimelineService::record()` call in `EntityManager::wrapInTransaction()`, the pattern already used by
`TimelineService::restore()`.

Because `wrapInTransaction()` calls `close()` on the entity manager when the closure throws, all
validation must happen before the transaction starts. `BoardService::validateElementInput()` was
extracted from `addElement()` and is now called by the controller before the transaction and by
`addElement()` itself, so both paths validate identically. In the update action the metadata check is
hoisted; only geometry/content/style application and the flush run inside the closure. Delete builds
its post-deletion snapshot, removes the element, touches the board, flushes and records the timeline
inside one transaction.

Response formats, status codes and timeline semantics are unchanged.

**Regression tests** (`backend/tests/Controller/CanvasTimelineConsistencyTest.php`, 4 tests /
23 assertions):

- create persists exactly one element and one matching timeline snapshot;
- update persists the new geometry/content and one snapshot carrying it;
- delete removes the element and records one snapshot without it;
- a Doctrine `onFlush` listener that throws on `TimelineEntry` insertion makes the request fail and
  leaves `SELECT COUNT(*) FROM canvas_elements` and `SELECT COUNT(*) FROM timeline_entries` at `0`.
  This asserts real database state, not that a method was called.

Existing timeline restore behavior is unchanged and still covered by the board lifecycle suite.

**Documented limitation.** `BoardApiController` board create/rename keep their two-flush pattern
(flush, then record the timeline entry). They share the same shape of defect but are not canvas
mutations, so they were left out of this sprint and are recorded in §11 rather than changed silently.

## 5. Task 4 — failed local note persistence

**Original problem.** `createNoteAt` inserted a local note, `POST`ed it, swapped the local id for the
server id on success and only logged a warning on failure. The note stayed visible and looked saved.

**Fix (frontend):**

- `frontend/src/mocks/types.ts` adds `NoteSaveState = 'saving' | 'saved' | 'failed'` and an optional
  `saveState?` on `StickyNote`. Notes created without an API (demo/offline boards) keep it undefined,
  so existing demo behavior is untouched.
- `frontend/src/screens/BoardEditorScreen.tsx` marks a new note `saving`, sets `saved` after the
  server id is adopted, and sets `failed` on error while preserving the user's content. `retryNote()`
  re-sends the same local note and reuses its local id until the server confirms, so a retry cannot
  create a duplicate.
- `frontend/src/components/board/BoardCanvas.tsx` renders the three states: a pulsing dot while
  saving, a check when saved, and a retry control (`bg-danger-soft`/`text-danger-text`, screen-reader
  labels) when failed. No failed note is presented as persisted.
- `frontend/src/lib/noteSync.ts` (`mergeSyncedNotes()`) is the single reconciliation used by both the
  3-second poll and the SSE `canvas` event: server elements replace only matching persisted notes,
  pending and failed local notes are preserved, and a note whose server id already exists is never
  duplicated.
- `frontend/src/i18n/dictionary.ts` adds FR/EN/AR keys for saving/saved/failed/retry.

**Regression tests** (`frontend/src/tests/NotePersistence.test.tsx`, 7 tests): successful creation
marks the note saved and adopts the server id; a failed creation marks it failed and keeps the
content; retry after failure sends one additional request and creates no duplicate; polling while a
note is pending keeps it; polling after success reconciles without duplication; plus direct
reconciliation tests for preserved pending/failed notes and for server replacements.

**Backend contract.** The note payload shapes were not changed; no API change was required for this
fix.

## 6. Task 5 — user data exposure review

**Endpoints inspected**

| Endpoint | Audience | User payload before | Decision |
| --- | --- | --- | --- |
| `GET /api/boards/{id}/presence` | board members + workspace admins | full profile (email, city, status) | collaborator profile |
| `GET /api/boards/{id}/stream` (presence events) | same | full profile | collaborator profile |
| `GET /api/boards/{id}/elements` | members, and anonymously when public + active | full profile (author) | collaborator; anonymous gets name only |
| `GET /api/boards/{id}` (public payload) | anonymous when public + active | `author.email` empty string | author name only (`id` empty, role `Viewer`) |
| `GET /api/boards/{id}/timeline`, board detail timeline | members | full profile (author) | collaborator |
| comment threads/messages | members | full profile (author) | collaborator |
| board member list (board detail) | members | full profile | **unchanged** |
| workspace member list / settings | workspace members | full profile | **unchanged** |
| `GET /api/auth/me` | the user themselves | full profile | **unchanged** |

**Change.** `CanvasSerializer::collaborator()` returns `id`, `name`, `role`, `accent`, and is now used
for presence, element authors, comment authors, timeline authors and the anonymous public payload.
`UserProfileExposureTest` (4 tests / 36 assertions) asserts the presence payload, canvas element
author, comment author, timeline author and public payload contain no `email`, `city` or membership
`status`, while collaborator data still carries id/name/role/accent for live cursors and avatars.

**Retained exposure (deliberate).** The board member list and the workspace member directory keep
`email`, `city` and membership `status`. Both are already visible to exactly that audience in the
product: the board share dialog renders each member's email, and workspace settings list every
member's email and city (with email additionally used to invite colleagues). Removing the fields would
break shipped UI without reducing exposure to anyone who could not already see it.

**Remaining product question.** Whether every board member should see every other board member's
email and city is a product policy decision, not a code defect. It is unchanged here and listed in
§11 for product-owner confirmation.

**Frontend.** `frontend/src/api/client.ts` gains `ApiCanvasAuthor`/`ApiBoardMember` types matching the
narrowed payloads; `ShareDialog` keeps the member type with email, and the canvas test fixture no
longer contains author email/city.

## 7. Files changed

Backend:

- `src/Controller/CanvasApiController.php` — active-only public fallback; transactional create/update/delete.
- `src/Controller/JsonPayloadTrait.php` — object-only decoding with recursive normalisation.
- `src/Controller/PresenceApiController.php` — collaborator payloads for presence and stream.
- `src/Service/BoardService.php` — extracted `validateElementInput()`.
- `src/Service/CanvasSerializer.php` — `collaborator()`; board-safe authors; name-only public author.
- `src/Service/BoardSerializer.php` — timeline authors use `collaborator()`.
- `tests/Controller/ApiFunctionalTestCase.php` — `requestWithRawBody()`.
- `tests/Controller/CanvasApiControllerTest.php`, `tests/Controller/BoardApiControllerTest.php`,
  `tests/Controller/CanvasTimelineConsistencyTest.php` (new), `tests/Controller/UserProfileExposureTest.php` (new).

Frontend:

- `src/mocks/types.ts`, `src/api/client.ts` — save state and narrowed author/member types.
- `src/screens/BoardEditorScreen.tsx`, `src/components/board/BoardCanvas.tsx` — save states, retry, merge.
- `src/lib/noteSync.ts` (new) — shared reconciliation.
- `src/components/share/ShareDialog.tsx` — member type.
- `src/i18n/dictionary.ts` — FR/EN/AR keys.
- `src/tests/Canvas.test.ts`, `src/tests/NotePersistence.test.tsx` (new).

## 8. Test results

| Area | Exact command | Result | Details |
| --- | --- | --- | --- |
| Backend tests (full) | `cd backend && php bin/phpunit` | **PASS** | `OK (95 tests, 639 assertions)`, 4m04s on PHP 8.5.12 |
| Backend tests (touched) | `cd backend && php bin/phpunit tests/Controller/CanvasApiControllerTest.php` and the other three files | **PASS** | 8/48, 19/91, 4/23 and 4/36 |
| PHPStan | `cd backend && composer analyse` | **PASS** | `[OK] No errors` (level 5, src + tests) |
| PHP-CS-Fixer | `cd backend && composer cs:check` | **PASS** | 0 of 67 files need fixing |
| Composer metadata | `cd backend && composer validate --no-check-publish` | **PASS** | `./composer.json is valid` |
| Dependency audit | `cd backend && composer audit --locked` | **PASS** | No security vulnerability advisories |
| Platform requirements | `cd backend && composer check-platform-reqs` | **PASS** | All extensions present on PHP 8.5.12 |
| Container lint | `cd backend && php bin/console lint:container` | **PASS** | All services injected with compatible types |
| Migrations (fresh DB) | `DATABASE_URL=<disposable db> php bin/console doctrine:migrations:migrate --no-interaction --env=dev` | **PASS** | 8 migrations / 75 SQL queries on a disposable PostgreSQL 16 database, which was dropped afterwards |
| Schema validation | `DATABASE_URL=<disposable db> php bin/console doctrine:schema:validate --env=dev` | **PASS** | Mapping correct; schema in sync with the mapping |
| Frontend tests | `cd frontend && npx vitest run --no-file-parallelism` | **PASS** | 11 files, 35 tests |
| Frontend tests (parallel) | `cd frontend && npx vitest run` | **PASS** | 11 files, 35 tests; see the flake note in §9 |
| ESLint | `cd frontend && pnpm run lint` | **PASS** | `eslint .` exits 0 |
| Type check + build | `cd frontend && pnpm run build` | **PASS** | `tsc -b` then Vite production build, `built in 11.10s` |
| GitHub Actions | `.github/workflows/ci.yml` | **NOT VERIFIED locally** | The base commit's `main` run `43735221770` is green; the branch run is reported with the push |

The full PHPUnit run logs one `[critical] Uncaught PHP Exception RuntimeException: "Simulated timeline
storage failure."` and one `NotFoundHttpException: "Workspace not found."`; both are produced by
negative-path tests and are expected.

## 9. Test-suite stability

The serial frontend suite is stable. Earlier parallel runs on this machine failed inside
timing-sensitive suites that this sprint did not touch (`Session`, `DashboardLifecycle`,
`E2E`) with a different set each run while Docker and other local services were starting; the final
parallel run passed 11/11 files and 35/35 tests. This is recorded as a local scheduling flake, not as a
passing check that was skipped.

## 10. Git

- Branch `fix/sprint-4-correctness-security` created from `main`/`origin/main` at `11a2ae0`.
- Commits follow the sprint split: security lifecycle, JSON contract, canvas atomicity, privacy,
  frontend note persistence, documentation.
- `main` was not modified, nothing was merged, and no history was rewritten.

## 11. Known limitations and open items

- **Board create/rename are not transactional.** `BoardApiController` still flushes the board and then
  records the timeline entry in a separate step. Same defect class as Task 3, outside the canvas scope;
  it needs its own sprint item and the same hoist-then-`wrapInTransaction` treatment.
- **Member directory exposure awaits a product decision.** Email, city and membership status remain in
  the board and workspace member payloads because the shipped UI renders them for the same audience.
- **No real-browser pass** of the invite/failure/retry note flow; jsdom covers the API contract,
  the retry request count and the reconciliation logic.
- **Concurrency:** canvas transactions give atomicity, not serializability; two clients editing the
  same element still follow last-write-wins. Optimistic locking/CRDT remains out of scope.
- **No SSE/polling deduplication for elements created by other clients during a failed local save**
  beyond the documented reconciliation rule; a note is matched by server id, not by content.
- **CI for this branch** can only be reported after the push; the workflow itself was not modified.
