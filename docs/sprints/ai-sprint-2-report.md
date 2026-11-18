# AI Sprint 2 — brainstorming proposal to real Mosaiq board

**Date:** 2026-11-18
**Branch:** `feat/ai-brainstorming-board-integration`
**Base:** `origin/feat/ai-brainstorming-backend` (`aead87d`, includes the Sprint 0 contract and Sprint 1A)
**Integration:** merge of `origin/feat/ai-brainstorming-frontend` (`51b17bf`, Sprint 1B), merge commit `d9dbd34`
**Scope:** connect Sprint 1B's mocked generation to Sprint 1A's real endpoint and add the explicit approval →
board import path, reusing Mosaiq's existing board, element, timeline, authorization and collaboration code.

---

## 1. Preparation findings

| Check | Result |
| --- | --- |
| `git fetch --all --prune` | `origin/main` at `01aed5e`; no AI work merged into `main` |
| Sprint 1A / 1B PRs | **Not merged**: `origin/feat/ai-brainstorming-backend` is 4 commits ahead of `main`, `origin/feat/ai-brainstorming-frontend` 3. There is no PR merge to start from, so the integration branch was cut from 1A and 1B was merged into it (clean, no conflicts). |
| Contract | Both branches carry the same `AI_BRAINSTORMING_CONTRACT.md`; the merge produced no contract conflict. |
| Wire contract agreement | Request/response shape, `schemaVersion 1.0`, limits, note types, categories and colour mapping are identical on both sides. **No wire mismatch.** |
| Endpoint and tests | `POST /api/ai/brainstorming/generate` is implemented as specified; `AiBrainstormingApiControllerTest` (26 cases) passes. |
| Frontend generation/preview | 1B defaults to a mock transport selected by `VITE_AI_BRAINSTORMING_MOCK`; the real client already existed beside it. |
| Board persistence | Board create is atomic with its opening timeline entry; element create is idempotent per `(board, author, mutationId)` with a board-row lock; each element writes its timeline entry in the same transaction. |
| **Contract discrepancy 1** | Contract §7.3's fixed layout (`x = 80 + s*268`, `y = 160 + n*164`) puts the 10th note of a section at `y ≈ 1636` on the real 1400×880 canvas (`CANVAS_WIDTH/HEIGHT` in `BoardCanvas.tsx`), i.e. outside the usable area, contradicting the sprint's "no elements outside the canvas". Resolved by replacing §7.3 with a fitting deterministic layout (§3 below); the contract and the implementation changed in this branch. |
| **Contract discrepancy 2** | The contract requires "all-or-nothing" imports but `main` has no cross-request transaction or permanent board deletion. Resolved with a compensating rollback (§4) and a rewritten §7.6. |
| Environment finding | The Sprint 1A migration `Version20261118120000` (creates `ai_generation_usage`) had never been applied to the local dev database, so the first real generation returned `500 ai.internal_error`. Applying the migration fixed it; this is a deployment step, not a code defect. |

---

## 2. Real API integration

- `frontend/src/features/ai/mockResponse.ts` is deleted and `VITE_AI_BRAINSTORMING_MOCK` is gone:
  `generateBrainstormingProposal()` always calls `POST /api/ai/brainstorming/generate`.
- The dedicated client keeps the contract's 35 s budget (the shared `request()` helper is bounded at 5 s),
  sends `Authorization: Bearer <session token>` from the existing session store, rejects an 8 KiB body
  before sending, and validates the response shape (`isAiBrainstormingResponse`) plus `schemaVersion`.
- Error mapping (`frontend/src/features/ai/errors.ts`, unchanged) covers `422` (field details), `429`
  (`retryAfter` clamped 1–3600 s), `502`, `503`, `504`, `401`, network loss and caller cancellation.
- Retries never generate twice: a second click while a request is in flight is ignored
  (`runningRef`), and "Retry" re-sends the stored validated request.
- Board import retries do not duplicate boards or notes: the board is created once, each note is retried
  **once with the same `mutationId`** for transport errors/`5xx`/`409`, and the server replays a stored
  element for a repeated key.

## 3. Proposal → board mapping and layout

Each approved note becomes one ordinary `CanvasElement` of type `note` created through
`POST /api/boards/{id}/elements`:

| Preview | Persisted element |
| --- | --- |
| `draft.title` | board title (`POST /api/boards`, all five palette tokens sent) |
| `draft.objective` | not persisted as an element (no text/objective element type); kept in the UI |
| section | `metadata.aiSectionId` + `metadata.aiSectionTitle` (additive), and the note's band in the layout |
| note `type` | `metadata.aiNoteType` + colour: `idea→amber`, `question→sky`, `challenge→rose`, `opportunity→mint`, `action→lilac` |
| note `content` | `content`, trimmed and whitespace-collapsed |
| position | `x`, `y`, `width`, `height` from `frontend/src/features/ai/layout.ts`, `rotation: 0` |

Layout (`layoutProposalSections`) is a pure function of the section note counts, so review, tests and the
board cannot drift:

```
safe area  x 80‥1320, y 160‥840   (inset of the 1400×880 canvas)
columns    = clamp(ceil(sqrt(totalNotes)), 1, 5)
each section starts a new band; notes wrap after `columns`
gap        column 20, row 16, section 32
note size  min(220, available width), min(140, available height) — centred block
```

Every contract-valid shape (≤ 5 sections, ≤ 25 notes) stays inside the canvas with no overlap and notes of
at least 140×72; ordinary proposals keep the editor's 220×140 note. The editor opens at its existing
default zoom (0.8) with the top-left origin, which shows the whole board.

## 4. Transactions and rollback

- Board creation is unchanged (`BoardService` + opening timeline entry in one `wrapInTransaction`); a
  failure there persists nothing and keeps the proposal editable.
- Each note create is unchanged: one transaction containing the element and its timeline entry, with the
  idempotency guarantee described above.
- If a note still fails after its retry, the frontend compensates with existing endpoints: delete the notes
  this import created (newest first), then trash the board. The user sees an import failure, not a success,
  and no partial active board is visible to collaborators.
- Success is reported (toast + navigation) only after the last note is confirmed by the backend.
- Per-note creates are not one transaction: a compensating rollback is best-effort. If the deletes or the
  trash call fail, a trashed board may keep some notes; permanent board deletion is still **PLANNED**.

## 5. Files changed

| File | Change |
| --- | --- |
| `frontend/src/api/aiBrainstorming.ts` | Removed the mock branch; the real endpoint is the only transport. |
| `frontend/src/api/client.ts` | `createBoard(title, workspaceId?, noteColors?)` sends the palette. |
| `frontend/src/features/ai/config.ts` | Dropped the mock flag (kept the feature flag and consent). |
| `frontend/src/features/ai/mockResponse.ts` | **Deleted** (Sprint 1B mock). |
| `frontend/src/features/ai/limits.ts` | Removed the out-of-bounds layout; added the five-token palette list. |
| `frontend/src/features/ai/layout.ts` | **New** deterministic fitting layout + overlap helper. |
| `frontend/src/features/ai/importProposal.ts` | **New** plan builder, idempotent note creates, error mapping, compensating rollback. |
| `frontend/src/features/ai/BrainstormingPreview.tsx` | Approval action, admin gate, in-progress state, limits gate. |
| `frontend/src/features/ai/BrainstormingDialog.tsx` | Import lifecycle, duplicate-approval guard, success toast + navigation, import errors. |
| `frontend/src/i18n/dictionary.ts` | FR/EN/AR copy for approval, progress, success and the five import failures. |
| `frontend/src/tests/AiBrainstorming.test.tsx` | Fixture no longer depends on the deleted mock; preview expectations updated. |
| `frontend/src/tests/AiProposalLayout.test.ts` | **New** layout unit tests. |
| `frontend/src/tests/AiProposalImport.test.tsx` | **New** approval/import integration tests. |
| `backend/tests/Controller/AiProposalImportTest.php` | **New** HTTP-level import integration tests. |
| `docs/architecture/AI_BRAINSTORMING_CONTRACT.md` | Revision log; §7.2 metadata, §7.3 layout, §7.6 rollback. |
| `AGENTS.md` | Status line for the integrated AI feature. |

## 6. Quality gates (actual output)

| Command | Result |
| --- | --- |
| `php bin/phpunit` | `OK (206 tests, 1505 assertions)` |
| `composer analyse` (PHPStan level 5) | `[OK] No errors` |
| `composer cs:check` | `Found 0 of 104 files that can be fixed` |
| `composer validate --no-check-publish` | `./composer.json is valid` |
| `composer audit --locked` | `No security vulnerability advisories found` |
| `php bin/console lint:container` | `[OK] The container was linted successfully` |
| `php bin/console doctrine:migrations:migrate` | applied `Version20261118120000` (1 migration) |
| `php bin/console doctrine:schema:validate` | `[OK] The database schema is in sync` |
| `npm run lint` | pass (eslint, no findings) |
| `npm run build` | pass (`tsc -b` + `vite build`, built in 8.98 s) |
| `npm test` | `Test Files 14 passed (14)`, `Tests 82 passed (82)` |

New automated coverage maps to the sprint list: successful prompt→proposal; editing before import;
successful import; section/note mapping; coordinates; no overlap; nothing persisted before approval;
invalid proposal rejection; rollback; unauthorized/permission-change import; duplicate approval; timeline
consistency (1 board + N note entries, reload, restore-able history); reopening/reloading; collaborator
sees and edits generated notes; existing board creation regression (untouched `BoardApiControllerTest`
still passes). The AI provider is faked at the HTTP boundary in jsdom and by the scripted fake provider in
PHPUnit, so CI never needs an external provider.

## 7. Manual verification

Environment: local Postgres from `docker-compose.yml`, Symfony dev server on the port the frontend config
expects (`127.0.0.1:8001`), Vite on `http://localhost:5173`, and a temporary **local OpenAI-compatible
stub** in `backend/var/` (git-ignored, deleted afterwards) because no external provider key is available.
`.env.local` was backed up and restored; no secret was committed.

| Step | Result |
| --- | --- |
| Dashboard entry point | "Brainstormer avec l'IA" in the header and the "Brainstorming assisté" card both open the dialog. |
| Generation dialog | Contract limits/hints render; consent required; request reached the real endpoint (verified in the network log). |
| Provider response | Local stub returned a 3-section/9-note proposal; the editable preview rendered it as "Aperçu local — non enregistré" (`docs/screenshots/ai-preview-sprint2.png`). |
| Preview editing | Board title changed to "Atelier vélos — pilote Tunis" and the first note content edited; nothing was written before approval. |
| Approval | "Créer le tableau" created the board and navigated to `/board/78edf6ad-bd3d-4ae3-b8f3-2d0b96048c72`. |
| Board creation | 9 notes on a 3×3 band grid, 220×140 at 80 % zoom, colours by note type (`docs/screenshots/ai-import-board-sprint2.png`). |
| Reopening / refresh | Reload kept all 9 notes; the dashboard listed "Atelier vélos — pilote Tunis". |
| Timeline | Replay showed "étape 1 sur 10" (1 board entry + 9 note entries). |
| Editing a generated note | Double-click edit committed through the normal PATCH path and the new text is in `canvas_elements`. |

Not verified manually: a second collaborator in a second browser session (covered by
`testCollaboratorSeesAndEditsGeneratedNotes`), the rollback UI (jsdom test), and a **real external**
provider call (no key available; the local stub is not a provider quality test).

## 8. Known limitations

- No server-side transaction spans the board and its notes; rollback is compensating, and repeated
  approvals after a lost response create a new board (there is no board-level idempotency key).
- Creating a board needs workspace administration; non-administrators can generate and edit a proposal but
  the approval action is disabled with a translated explanation.
- Section headings are metadata only (Mosaiq has no text element and the editor does not render
  server-provided frames/connectors).
- Notes shrink below 220×140 for dense proposals (minimum 140×72 at the 25-note/5-section extreme).
- The local dev database needed the Sprint 1A migration before generation worked; a deployment must run
  migrations before enabling `AI_ENABLED`.
- During manual verification, generated notes showed the author label "Invité" because the canvas maps
  author names through `src/mocks` (pre-existing display behaviour, unrelated to this sprint).
- No CI run on the remote branch yet; the workflows were exercised locally.
