# Sprint 3 — board lifecycle, archive, trash and dashboard consistency

**Result:** COMPLETE for the approved reversible lifecycle scope. Permanent deletion and any
retention/purge policy are **PLANNED** because no product decision authorizes them.

**Date:** 2026-11-15

## 1. Sprint 2 gate

Verified against the current tree:

- workspace membership is persisted as active/removed status in `User`;
- owner/admin/member authorization and owner protection are enforced server-side;
- workspace invitations are hashed, email-bound, expiring and revocable;
- member removal revokes the token and removes board memberships;
- private-board authorization remains separate from workspace membership.

The Sprint 2 report was missing from the repository and has been added at
`docs/sprints/sprint-2-report.md` from the inspected implementation and command results. No Sprint 2
security blocker prevents Sprint 3.

## 2. Initial findings

The board model had no lifecycle state. `BoardRepository` always returned every accessible board,
the dashboard's “trash” action only removed a local React card, and the trash view was an
unconditional empty state. Archived boards did not exist. Public links had no lifecycle guard. The
copy claimed a 30-day retention that was never implemented.

## 3. Lifecycle model

`Board` now has one `lifecycle_status` value plus nullable `archived_at` and `trashed_at`.

| From | Action | To | Result |
| --- | --- | --- | --- |
| active | archive | archived | `archivedAt` set, `trashedAt` cleared |
| active or archived | trash | trashed | `trashedAt` set, `archivedAt` cleared |
| archived | restore | active | both timestamps cleared |
| trashed | restore | active | both timestamps cleared |
| active | restore | active | idempotent `200` |
| archived/trashed | archive | — | `409` |
| trashed | trash | — | `409` |

A board cannot hold both archive and trash timestamps. Trashed boards are retained indefinitely;
there is no automatic purge. Content, comments, timeline entries, invitations, memberships and
elements remain in place during reversible transitions.

## 4. Permission matrix

| Operation | Board Owner/Admin | Board Editor/Commenter/Viewer | Workspace Admin | Non-member |
| --- | --- | --- | --- | --- |
| View active board | Yes | Yes (if board member) | Yes (existing explicit policy) | No |
| View archived board | Yes | Yes (read-only) | Yes | No |
| View trashed content | No | No | No | No |
| Archive/trash/restore | Yes | No | Yes | No |
| Discover archived/trashed metadata | Yes | Yes, if board member | Yes | No |
| Permanent delete | **PLANNED** | No | **PLANNED** | No |

Workspace membership alone does not grant access to an unjoined private board. The existing
workspace-owner/admin board-management bypass is retained as the documented pre-existing policy,
not introduced by this sprint. `BOARD_MANAGE` is a separate lifecycle attribute, so a manager can
restore a board without being able to read its trashed content.

## 5. Backend changes

- `backend/src/Entity/Board.php` — lifecycle constants, status/timestamps and validated transitions.
- `backend/src/Repository/BoardRepository.php` — accessible queries now require the requested
  lifecycle status; existing callers default to active.
- `backend/src/Security/BoardVoter.php` — `BOARD_MANAGE`; archived boards are view-only; trashed
  content and public access are denied; edit/comment/share require active state.
- `backend/src/Controller/BoardApiController.php` — status-filtered listing, archive, trash,
  restore, public active-only guard and lifecycle logging.
- `backend/src/Service/BoardSerializer.php` — serializes `status`, `archivedAt` and `trashedAt`.
- `backend/src/Controller/AuthApiController.php` / `BoardApiController.php` — invitation acceptance
  is rejected once a board is no longer active.
- `backend/tests/Controller/BoardLifecycleTest.php` — 6 tests / 95 assertions covering transitions,
  content preservation, permissions, cross-workspace isolation, public isolation and invalid
  transitions.

## 6. API changes

| Method | Path | Authorization | Result |
| --- | --- | --- | --- |
| GET | `/api/boards?status=active\|archived\|trashed` | Active session | Accessible boards in exactly that state; invalid status `422` |
| POST | `/api/boards/{id}/archive` | `BOARD_MANAGE` | Archived summary; `409` if not active |
| POST | `/api/boards/{id}/trash` | `BOARD_MANAGE` | Trashed summary; `409` if already trashed |
| POST | `/api/boards/{id}/restore` | `BOARD_MANAGE` | Active summary; idempotent when already active |

Existing `GET /api/boards/{id}`, elements, comments, timeline and sharing endpoints now inherit the
lifecycle voter rules. There is no permanent-delete endpoint.

## 7. Database changes

`backend/migrations/Version20261115190000.php` adds:

- `boards.lifecycle_status VARCHAR(16) NOT NULL DEFAULT 'active'`;
- nullable `boards.archived_at` and `boards.trashed_at`;
- `idx_boards_workspace_lifecycle (workspace_id, lifecycle_status)`.

All existing boards become active. The migration is additive and does not edit any applied
migration. Verification:

- applied to the preserved dev database (one migration, four SQL statements);
- full eight-migration sequence on a fresh disposable PostgreSQL database;
- latest migration down/up on that database;
- `doctrine:schema:validate` passes on the fresh database and dev database.

## 8. Frontend changes

- `frontend/src/api/client.ts` — lifecycle types, status-filtered `getBoards`, archive/trash/restore
  calls and a `canManage` UI hint derived from the authenticated user/board roles.
- `frontend/src/screens/DashboardScreen.tsx` — active/archive/trash views load from the API; one
  authoritative list per view; archive/trash confirmation dialogs; restore actions; loading
  skeletons; empty and error/retry states; no optimistic removal on failure.
- `frontend/src/components/layout/AppShell.tsx` — Archive navigation.
- `frontend/src/i18n/dictionary.ts` — FR/EN/AR keys for archive/trash views, confirmations, errors
  and actions; the inaccurate 30-day retention copy was removed.
- `frontend/src/tests/DashboardLifecycle.test.tsx` — 4 tests for archive/restore, trash/restore,
  load errors and rejected actions.

Trashed cards are metadata-only and do not navigate to content. Archived cards may open the
read-only/approved view; the backend still denies edits until restore.

## 9. Authorization and data integrity

- Cross-workspace archive/trash operations return `403`; invalid IDs return `404`.
- Ordinary board editors/viewers cannot archive or trash a board; workspace admins can under the
  existing explicit policy.
- Archived and trashed public boards cannot be read anonymously.
- Restoring a board preserves elements and timeline; the lifecycle test asserts both.
- Removed members remain unable to reach board endpoints through the Sprint 2 authenticator/voter
  rules.
- No related records are deleted in Sprint 3. Permanent deletion and dependent-record policy remain
  **PLANNED**.

## 10. Test results

| Area | Exact command | Result | Notes |
| --- | --- | --- | --- |
| Backend tests | `cd backend && composer test` | **PASS** | 75 tests, 532 assertions |
| Frontend tests | `pnpm test` | **PASS** | 10 files, 28 tests |
| Static analysis | `cd backend && composer analyse` | **PASS** | No errors |
| Lint | `pnpm run lint` | **PASS** | ESLint exits 0 |
| Production build | `pnpm run build` | **PASS** | TypeScript + Vite production build |
| CS | `cd backend && composer cs:check` | **PASS** | 0 of 65 files need fixing |
| Database migrations | `doctrine:migrations:migrate` + fresh up/down/up | **PASS** | `Version20261115190000`; schema validate passes |

One existing Session test was made robust against parallel-suite scheduling by allowing a 5-second
query timeout; it still exercises the real 401/redirect/expired-message path. It was not skipped or
weakened in its assertions.

## 11. Known limitations and deferred decisions

- **Permanent deletion: PLANNED.** No approved retention period, deletion authorization or
  dependent-record policy exists. The trash is indefinite and there is no empty-trash endpoint.
- **Durable audit trail: PLANNED.** Lifecycle actions are written to the application logger only;
  no audit table is created.
- **Search/pagination: NOT IMPLEMENTED** because the existing dashboard has neither and the bounded
  board collection does not justify new controls.
- **Concurrent lifecycle requests:** state checks are server-side, but there is no row-locking
  protocol; simultaneous archive/trash requests can return a conflict rather than a guaranteed
  single winner.
- **Workspace-admin board bypass** remains the existing explicit policy.
- **GitHub Actions: NOT VERIFIED** because no push was authorized.
- **No real-browser pass** of archive/trash was performed; jsdom covers the API contract and
  interactions.

## 12. Sprint 4 handoff

The active/archive/trash lifecycle, status-filtered listing, `BOARD_MANAGE` authorization and
reversible UI are available. A future sprint can implement permanent deletion only after product
approval of retention, authorization and dependent-record rules; it should reuse the lifecycle
service boundary rather than adding another status field. Durable audit logging and concurrent
transition locking are also ready to be scheduled. The existing board editor, comments, presence,
invitations and timeline APIs continue to work for active boards.
