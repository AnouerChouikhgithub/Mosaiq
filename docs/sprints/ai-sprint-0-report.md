# AI Sprint 0 — foundation, architecture contract and release hardening

**Date:** 2026-11-18
**Branch:** `chore/ai-foundation-contract`
**Starting commit:** `01aed5e72f6799c5364bc0f1cf90096b15e7049c` (`origin/main`, PR #3 merge)
**Scope:** inspect the real repository, correct the previous P3 concurrency-test weakness, and publish
the versioned contract and ADR for the two AI workstreams.
**Explicitly not done:** no AI provider integration, no AI endpoint, no frontend generation screen, no
change to existing board behaviour other than the concurrency test.

---

## 1. Repository inspection

| Check | Result |
| --- | --- |
| Local branch before work | `fix/final-release-correctness` at `9658781` ("show and hide password button"), one commit not on `main` |
| Working tree before work | dirty: `backend/src/Repository/BoardPresenceRepository.php` and `backend/tests/Controller/PresenceApiControllerTest.php` modified, `docs/screenshots/` untracked. **Preserved untouched.** |
| `git fetch --all --prune` | `main 69f78b3..01aed5e` |
| Latest `main` | `01aed5e` Merge PR #3 (`fix/final-release-correctness`), 2026-11-17 18:49 +0100 |
| Recent merged PRs | #2 `fix/sprint-5-persistence-reliability` (`69f78b3`), #3 `fix/final-release-correctness` (`01aed5e`) |
| Previous reviewed commit `01aed5e72f6799c5364bc0f1cf90096b15e7049c` | **Still an ancestor of `main`** (`git merge-base --is-ancestor` → true); it is in fact the current `main` tip |
| Branch creation | `git switch -c chore/ai-foundation-contract origin/main` — created safely; the two modified files are byte-identical between the old HEAD and `origin/main`, so the user's edits carried over without conflict |

The user's uncommitted edits and the untracked screenshots are still present and are deliberately
left out of this branch's commits.

Reviewed in the inspection: `BoardService`, `BoardApiController`, `CanvasApiController`,
`PresenceApiController`, `Board`/`CanvasElement`/`TimelineEntry` entities, `BoardVoter`,
`BoardRepository`, `CanvasElementRepository`, `CanvasSerializer`, `TimelineService`,
`security.yaml`, `composer.json`, `.github/workflows/ci.yml`, the functional test harness and
`frontend/src/api/client.ts`, `BoardEditorScreen`, `mocks/types.ts`, plus `docs/technical-audit.md`
and the Sprint 5 report.

---

## 2. Concurrency-test correction (previous P3)

### Problem

`CanvasConcurrencyTest::waitForDispatchedRequests()` polled for `dispatched-*` files and **returned
silently** when the 30 s deadline expired. If a worker died while booting the kernel, the loop
returned with fewer markers than expected, the board row lock was released, and every later
assertion ("both requests were in flight when the lock was released", "both waited on the lock")
could pass on two **sequential** requests. The test would then report success while no longer
proving concurrency.

### Change

- `assertAllWorkersDispatched()` now runs immediately after both workers start and **fails with a
  deterministic message** when any expected marker is absent: `Only 1 of 2 concurrent request workers
  dispatched before the deadline: missing b. Expected markers: dispatched-a, dispatched-b. The board
  row lock is still held, so no concurrency assertion was evaluated. Worker details: worker "b":
  <worker output>`.
- The wait is bounded (`DISPATCH_TIMEOUT_SECONDS = 20.0`) and factored into
  `awaitDispatchedWorkers()`, which returns the missing ids instead of swallowing them.
- `WORKER_IDS` is the single source of the expected worker set, so the dispatch assertion and the
  result collection cannot drift.
- **Worker cleanup:** every started worker is tracked in `$this->workers`; `tearDown()` calls
  `stopWorkers()`, which `proc_terminate()`s a still-running process and then `proc_close()`s it. A
  worker that produces no result within `WORKER_TIMEOUT_SECONDS` is terminated *before* its log is
  read and before `fail()`, so a hung request no longer outlives the test.
- **Filesystem cleanup:** `removeTemporaryDirectory()` now removes files, the barrier directory and
  any nested path recursively, and resets the directory field; the barrier directory is created by
  `createBarrierDirectory()` instead of an inline `mkdir`.
- Existing concurrency assertions are unchanged: both requests must finish after the lock release,
  each must have waited > 0.4 s, the two statuses must be `[200, 201]`, both must return the same
  element id, and `canvas_elements` / `timeline_entries` must each contain exactly one row.
- **New timeout-scenario test** `testDispatchWaitReportsEveryWorkerThatNeverArrives()`: it creates a
  barrier with only `dispatched-a` present, waits 0.3 s for `a` and `b`, and asserts the missing list
  is `['b']`, that the wait stayed within its timeout, and that the failure message names the worker
  (`1 of 2`, `missing b`, `worker "b"`). It starts no process, so it is deterministic and adds
  roughly 0.3 s; it exercises the exact path that previously failed silently.

### Verification (actually executed)

```
php bin/phpunit tests/Controller/CanvasConcurrencyTest.php
OK (2 tests, 15 assertions)            # runs 1–5: 22.5 s cold, then 5.6 / 5.3 / 5.4 / 5.4 s
```

Five consecutive executions passed with identical assertion counts and no flakes.

---

## 3. Architecture audit findings

Full detail is in the contract §2; this is the summary that shaped it.

| Area | Finding |
| --- | --- |
| Board creation | `POST /api/boards` requires `WORKSPACE_VIEW` **and** `WORKSPACE_ADMINISTER`; board, owner membership and opening timeline entry commit in one transaction. Title is a trimmed string, `noteColors` ⊆ five tokens. **A non-administrator cannot create a board**, so "apply a proposal to a new board" is admin-only unless a product decision changes that. |
| Element creation | `POST /api/boards/{id}/elements` requires `BOARD_EDIT`, validates geometry as finite numbers before opening a transaction, locks the board row for keyed creates, and answers `201` (new) / `200` (replay) / `409` (key reused with different payload, or unique-index race) / `422` (invalid input). |
| Idempotency | `mutationId` is a canonical UUID, unique per `(board, author)`; `request_hash` distinguishes a retry from a reused key. The unique index plus the row lock are the guarantee, not frontend state. |
| Note representation | A note is `CanvasElement` with `type = 'note'`, float geometry, `content`, `color ∈ {amber, rose, sky, mint, lilac}`, free-form JSON-object `metadata`. The frontend note carries `stroke`, `textSize`, `align` in metadata. |
| Coordinates | Floats, top-left origin, negative dimensions rejected, no server-side normalisation or clamping. Layout is entirely a client decision — hence the deterministic formula in the contract. |
| Element types | Server: `note, shape, connector, frame`. Editor: `BoardEditorScreen` maps **only** `type === 'note'` into canvas state; server-provided shapes/connectors/frames are not rendered. |
| Serialization | `CanvasSerializer::element()` returns `id, type, x, y, width, height, rotation, content, color, metadata, mutationId, author{id,name,role,accent}, createdAt`; empty metadata is emitted as `{}`. The public variant drops the author id and `mutationId`. |
| Authorization | `BoardVoter` resolves `VIEW/COMMENT/EDIT/SHARE/MANAGE` from workspace membership + board role; workspace admins bypass content roles; archived boards are view-only; trashed boards deny content. Tokens are hashed, expiring and revocable. |
| Timeline | `TimelineService::record()` inserts one entry per mutation **inside** the mutation's transaction, with a full-canvas snapshot. Kinds are `board, note, connector, frame, rename`; there is no AI kind and none is needed. |
| Collaboration | Authenticated SSE emits presence + a canvas **invalidation** frame (`revision`, `elementCount`) and closes; clients reconnect every 500 ms and poll every 3 s. Canvas writes are last-write-wins, not CRDT. |
| Transactions | Board create/rename, canvas create/update/delete and timeline restore use `wrapInTransaction`; a timeline write failure rolls the board/element change back. `TimelineService::record()` flushes per entry (known write amplification, B-08). |
| Frontend state | Notes live in `BoardEditorScreen` component state; each local note mints one `mutationId` (`frontend/src/lib/mutationId.ts`) reused across retries and kept with a `saveState` of `saving`, `saved` or `failed`. API failures keep the note and show a translated failure state. |
| Client conventions | `request()` wraps every call in a **5 s** timeout, parses `{error}` on failure, and clears the session on authenticated `401`. API functions live only in `frontend/src/api/client.ts`. |
| Error conventions | Ad-hoc `{ "error": "<message>" }` with per-controller status mapping; no central listener, no `code` field. |

### Safest conversion path (no new persistence)

1. `POST /api/boards` with the proposal title (existing atomic board create).
2. One `POST /api/boards/{id}/elements` per approved note, `type: 'note'`, one `mutationId` minted per
   note and reused on retry, `color` from the note-type mapping, `metadata.aiNoteType` additive.
3. Deterministic layout (`x = 80 + sectionIndex*268`, `y = 160 + noteIndex*164`, 220×140 notes).
4. Normal SSE invalidation + 3 s poll makes them visible to collaborators; each note writes its own
   timeline entry, so replay needs no change.

Rejected: a bulk element endpoint, a "generated" flag, direct DB writes, a second client write path,
persisting section headings (no text element type) and relationships/connectors (no validated
endpoint contract, not rendered from server data).

---

## 4. Contract and ADR

- [`docs/architecture/AI_BRAINSTORMING_CONTRACT.md`](../architecture/AI_BRAINSTORMING_CONTRACT.md) —
  input/output schema v1.0, authoritative limits, HTTP contract with examples, persistence mapping,
  security/privacy, provider-independent architecture, test obligations for both teams, change
  process and open decisions.
- [`docs/architecture/adr/ADR-001-ai-generation-isolated-from-persistence.md`](../architecture/adr/ADR-001-ai-generation-isolated-from-persistence.md)
  — the six decisions with consequences and rejected alternatives.

### Exact limits (authoritative, contract §5)

| Limit | Value |
| --- | --- |
| `topic` | 3–280 chars |
| `objective` | 5–500 chars |
| `context` | 0–2000 chars |
| Languages | `en`, `fr`, `ar` |
| Detail levels | `concise`, `balanced`, `detailed` |
| Sections | 1–5 (max **5**) |
| Section title | 3–80 chars |
| Notes per section | 1–10 |
| Total notes | 3–25 (max **25**) |
| Note content | 3–280 chars |
| Proposal title | 3–120 chars |
| Proposal objective | 5–300 chars |
| Note types | `idea`, `question`, `challenge`, `opportunity`, `action` |
| Section categories | `goals`, `audience`, `ideas`, `risks`, `actions`, `questions` |
| Note colors | `amber`, `rose`, `sky`, `mint`, `lilac` |
| Relationships | none |
| Request body | 8 KiB |
| Proposal JSON | 64 KiB |
| Schema version | `1.0` |
| Timeouts | 35 s client / 25 s server / 20 s per upstream attempt, one transport retry |
| Rate limits (initial) | 5 / 10 min per user, 20 / hour per user, 60 / min per deployment |

---

## 5. Files changed

| File | Change |
| --- | --- |
| `backend/tests/Controller/CanvasConcurrencyTest.php` | Dispatch assertion with failure message, bounded wait, timeout-scenario test, worker and temp-directory cleanup |
| `docs/architecture/AI_BRAINSTORMING_CONTRACT.md` | New — v1.0 contract |
| `docs/architecture/adr/ADR-001-ai-generation-isolated-from-persistence.md` | New — ADR |
| `docs/sprints/ai-sprint-0-report.md` | New — this report |

No application source, entity, migration, configuration, frontend file, workflow or dependency was
changed. The user's uncommitted backend edits were not staged.

---

## 6. Verification (actually executed)

```
cd backend && composer analyse          → 0 errors (PHPStan level 5)
cd backend && composer cs:check         → 0 of 73 files need fixing
cd backend && php bin/phpunit           → OK (152 tests, 930 assertions)
cd backend && php bin/phpunit tests/Controller/CanvasConcurrencyTest.php
                                        → OK (2 tests, 15 assertions) × 5 consecutive runs
```

The first gate run reported two self-inflicted issues in the new test (one docblock alignment and
one `nullCoalesce.offset` PHPStan finding); both were fixed, and the gates were re-run — the results
above are from the post-fix run. PHPUnit earlier reported `OK (152 tests, 930 assertions)` on the
pre-style-fix test file; the only subsequent edits were docblock spacing and replacing
`$status['running'] ?? false` with `$status['running']` (PHPStan proves the key always exists).

**Not run / not verified:** no GitHub Actions run for this branch (the workflow only triggers on
`main` and pull requests), no frontend checks (no frontend file changed), no AI provider call by
design, and no browser verification.

---

## 7. Known limitations and open decisions

1. **`symfony/http-client` is not installed.** Sprint 1A needs it and `AGENTS.md` requires owner
   approval before a dependency. Recommended: approve it; validation stays hand-written.
2. **Provider and model are not chosen.** v1 targets one OpenAI-compatible provider through
   `AI_BASE_URL`/`AI_MODEL`; the interface keeps it replaceable.
3. **Non-administrators cannot create boards.** `POST /api/boards` requires
   `WORKSPACE_ADMINISTER`. v1 recommendation: generate for everyone, apply into an existing board
   the user can edit, and keep "create a new board" admin-only with a translated explanation.
4. **Only notes round-trip.** The editor renders server-provided notes only; section headings are
   preview-only and relationships are excluded (contract §4.5, §7.3).
5. **No partial rollback on apply.** A failure mid-way leaves earlier notes stored; the client keeps
   per-note retry state. Permanent board deletion and trash retention remain **PLANNED**.
6. **No generation history or audit**, because nothing is stored server-side. Revisit with a
   retention product decision.
7. **Rate limiting is DB-backed, not distributed**, matching the existing auth throttle; it does not
   coordinate across deployments.
8. **Rate-limit numbers are placeholders** sized for a demo.
9. **Timeline labels stay English** ("… added a note"), consistent with the existing server-authored
   labels; not changed here.

---

## 8. PR description (ready to paste)

> **chore(ai): publish the brainstorming contract and harden the concurrency test**
>
> Sprint 0 for the AI track. No feature code: this PR fixes one test weakness and adds the shared
> contract two parallel workstreams will implement against.
>
> - `CanvasConcurrencyTest` now asserts that **both** workers dispatched before the board row lock is
>   released and fails with a message naming the missing worker and its output. The wait is bounded,
>   workers are terminated and reaped on both success and failure, and temporary barrier files are
>   removed recursively. A new deterministic test pins the missing-worker/timeout path.
> - Adds `docs/architecture/AI_BRAINSTORMING_CONTRACT.md` (schema v1.0): input/output shape,
>   authoritative limits (max 5 sections, 25 notes, 280-char notes, 8 KiB request, 64 KiB output),
>   `POST /api/ai/brainstorming/generate` with auth, validation, provider-failure, timeout and
>   rate-limit responses, plus the approved proposal → existing board-elements mapping.
> - Adds `ADR-001`: generation isolated from persistence, mandatory preview/approval, one provider
>   behind a replaceable interface, generated notes through existing idempotent create and SSE
>   invalidation, brainstorming-only scope.
> - `docs/sprints/ai-sprint-0-report.md` records the audit, commands and results.
>
> Tests: `composer analyse` 0 errors, `composer cs:check` 0/73, `php bin/phpunit` 152 tests / 930
> assertions, concurrency test 5× green.
>
> Deliberately excluded: provider integration, endpoint implementation, frontend screens, any change
> to board behaviour. Open decisions (HTTP client approval, provider choice, admin-only new-board
> apply) are listed in contract §13.
