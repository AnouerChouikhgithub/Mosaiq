# Sprint 5 — persistence reliability and transaction completion

**Result:** COMPLETE for the five review findings (canvas idempotency, atomic board create/rename,
rollback coverage for every canvas mutation, empty JSON object semantics, migration review).

**Date:** 2026-11-17
**Branch:** `fix/sprint-5-persistence-reliability`
**Base commit:** `2a6c3ae` (Sprint 4 merge, `main` = `origin/main`)

## 1. Scope and method

Each defect was reproduced before being changed. The idempotency guarantee and the rollback
guarantees are asserted against real database state, not against mocked transaction calls: the
suite's test database refuses to reset anything that is not a `*_test` database, so every test
starts from a schema built out of the current entity metadata.

No dependency was added. The API stays backward compatible: `mutationId` is optional, and a canvas
create without it behaves exactly as before.

## 2. Task 1 — genuine idempotency for canvas creation

**Original problem.** Sprint 4 kept a temporary local note id and reused it on retry, but the id was
never sent to the server. A note whose create request committed while its response was lost was
therefore created twice by the retry.

**Contract.** `POST /api/boards/{boardId}/elements` accepts an optional `mutationId` — a canonical
UUID that identifies one logical create operation:

| Case | Response |
| --- | --- |
| New key | `201`, the created element |
| Same key, same payload | `200`, the element the first attempt stored |
| Same key, materially different payload | `409` `This mutation id was already used with a different payload.` |
| Key invalid (not a UUID) | `422` `"mutationId" must be a canonical UUID.` |
| Unique index still rejects the insert | `409` `This mutation is already being processed. Retry the request.` |

**Storage.** `canvas_elements.mutation_id` and `canvas_elements.request_hash`, with the unique index
`uniq_canvas_elements_mutation (board_id, author_id, mutation_id)`. The key is scoped to the board and
the authenticated author, so another board or another user can never observe or hijack it. Postgres
treats NULLs as distinct, so the 19 pre-existing elements (all `mutation_id IS NULL`) remain valid.

`request_hash` is a SHA-256 of the element the payload would persist (type, geometry, trimmed content,
color, key-sorted metadata), which is what lets a retry be told apart from a reused key with other
content. Numeric formatting and member order do not affect it.

**Concurrency.** The create transaction takes a pessimistic write lock on the board row
(`EntityManager::lock($board, LockMode::PESSIMISTIC_WRITE)`), re-checks the key, then inserts. A second
simultaneous retry blocks on that lock and, once the first commits, reads its element and replays it
instead of inserting. The unique index remains the final guarantee; a violation is caught deliberately
and answered with `409` rather than swallowed or retried blindly.

**Lost response.** The client treats a failed request as "unknown", not as "not persisted": the note
stays visible as *Not saved* with its content and key, and the retry reuses that key. The server then
replays the stored element, and the editor replaces the temporary note with the server element.

**Frontend.** `frontend/src/lib/mutationId.ts` mints the key with `crypto.randomUUID()` (with an RFC
4122 v4 fallback). `createNoteAt` derives the local note id from the key, `persistNote` sends it on
every attempt, and `StickyNote.mutationId` carries it through saving, failure and retry.
`mergeSyncedNotes` now also drops a local-only note once a poll or stream frame reports an element with
the same mutation id, so a committed-but-unacknowledged note is not displayed twice.

**Regression tests.** `backend/tests/Controller/CanvasIdempotencyTest.php` (14 tests, 69 assertions):
first create, replay with the same id, unchanged element count, replay after a keyed-up identity map
and a freshly booted kernel, different payload rejection, per-board scoping, per-user scoping, 403 for
viewer/outsider, invalid keys, creates without a key, and a direct duplicate insert that must violate
the unique index. Frontend: `frontend/src/tests/NotePersistence.test.tsx` covers same-key retries, a
simulated lost response, poll reconciliation and distinct keys per note.

**Limitation.** The functional suite is single-process, so true parallel HTTP requests are not
executed. The two guarantees that make concurrency safe — the board row lock and the unique index — are
each exercised directly; see §9.

## 3. Task 2 — board creation and timeline are atomic

`BoardApiController::create()` now creates the board, its owner membership and its opening timeline
entry inside one `wrapInTransaction`. `BoardService::createBoard()` still flushes, but inside that
transaction, so a failed timeline write rolls the board back. Authorization (`WORKSPACE_VIEW`,
`WORKSPACE_ADMINISTER`) runs before the transaction, and the `201` payload is unchanged.

## 4. Task 3 — board renaming and timeline are atomic

`BoardApiController::update()` wraps the whole patch in one transaction: title validation, the
`Board::rename()` mutation, the `rename` timeline entry and the optional `starred` flag. An invalid or
non-string title now returns `422` and rolls back. Renaming to the same title keeps its previous
contract and still records a rename entry; that behaviour is pinned by a test rather than changed
silently.

## 5. Task 4 — rollback coverage for every canvas mutation

`backend/tests/Controller/CanvasTimelineConsistencyTest.php` now injects a failure at the persistence
boundary (`onFlush` when a `TimelineEntry` insert is scheduled) and asserts real rows afterwards:

| Mutation | Rollback assertion |
| --- | --- |
| create | `canvas_elements` and `timeline_entries` both stay empty |
| update | stored `content` and `x` are the pre-update values |
| delete | the deleted row is still present |

The success cases for all three already asserted both rows; they remain. `TimelineService::restore()`
is untouched.

## 6. Task 5 — empty JSON objects

`JsonPayloadTrait` now keeps an empty JSON object as a `stdClass` marker while non-empty objects still
normalize to associative arrays. Without that marker `{}` became `[]` and `array_is_list([])` reported
an empty object as a list. `BoardService::normalizeMetadata()` accepts `null`, an associative array and
the empty-object marker, and rejects any JSON array — empty or not. `CanvasSerializer` emits `{}` for
empty metadata so the wire shape of `{"metadata":{}}` survives the round trip, and nested empty objects
(`{"layout":{}}`) are preserved too.

Explicit behaviour, covered by `backend/tests/Controller/JsonPayloadNormalizationTest.php` (14 tests,
36 assertions): empty top-level object → `422` missing field (i.e. it *is* an object); `{}` metadata →
`201`; `[]` metadata → `422`; non-empty object → `201`; non-empty array → `422`; top-level array,
string, number, boolean and `null` → `422`; malformed JSON → `400`.

## 7. Task 6 — migration review

`Version20261117120000` adds two nullable columns and one unique index. It is additive: no column is
rewritten and no row is touched. `down()` drops the index and the two columns. Historical migrations
were not modified.

## 8. Verification (2026-11-17)

| Check | Command | Result |
| --- | --- | --- |
| PHPUnit | `cd backend && php vendor/bin/phpunit` | PASS — 131 tests, 795 assertions |
| PHPStan | `cd backend && composer analyse` | PASS — 0 errors |
| PHP-CS-Fixer | `cd backend && composer cs:check` | PASS — 0 of 70 files |
| Composer validation | `cd backend && composer validate --no-check-publish` | PASS — valid |
| Composer audit | `cd backend && composer audit --locked` | PASS — no advisories |
| Container lint | `cd backend && php bin/console lint:container` | PASS |
| Migrations (existing DB) | `cd backend && php bin/console doctrine:migrations:migrate --no-interaction` | PASS — `Version20261117120000`, 3 statements |
| Migrations (fresh DB) | disposable database + `doctrine:migrations:migrate` | PASS — all migrations, then `prev` and up again |
| Schema validation | `cd backend && php bin/console doctrine:schema:validate` | PASS — mapping and database in sync |
| Frontend tests | `cd frontend && npx vitest run` | PASS — 11 files, 41 tests |
| ESLint | `cd frontend && npx eslint .` | PASS |
| Type checking | `cd frontend && npx tsc -b` | PASS |
| Production build | root `pnpm run build` | PASS |
| GitHub Actions | — | NOT RUN — the workflow only runs on `main` pushes and PRs to `main` |

## 9. Remaining issues and limitations

- **No true parallel-request test.** The single-process functional suite cannot interleave two HTTP
  requests. The board row lock and the unique index are each tested directly, and the controller's
  re-check path is the same code the fast path uses.
- **Postgres is assumed.** The row lock relies on `SELECT … FOR UPDATE`, and the unique index relies on
  NULLs being distinct. Both hold on the documented Postgres 16 target.
- **A `409` remains possible** if the unique index is hit despite the lock; the client must retry, and
  the retry then replays the stored element.
- **Reconciliation drops unpersisted local edits** in the narrow window where a failed create is
  actually committed and the user typed further into the local copy before the next poll. The server
  copy wins, consistent with the existing reconciliation rule.
- Permanent board deletion and trash retention remain **PLANNED**. CRDT sync, email verification and
  password reset remain unimplemented and out of scope.
