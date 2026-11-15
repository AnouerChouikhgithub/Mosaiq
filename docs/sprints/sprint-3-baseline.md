# Sprint 3 baseline — board lifecycle

**Date:** 2026-11-15

**Branch:** `main` at `5b993fa`, with the uncommitted Sprint 0–2 worktree preserved.

## Sprint 2 gate

Sprint 2 was verified against the current tree, not only its report. The workspace model is embedded
in `User`; active membership is checked by the authenticator and voters; member removal revokes the
token and board memberships; workspace invitations are hashed, email-bound and expiring; the
settings screen uses the real API. The Sprint 2 report records the full permission matrix and the
deferred product decisions. There is no unresolved Sprint 2 security issue that blocks board
lifecycle work. Sprint 2's report was missing from the repository at the start of this sprint and has
now been written from the inspected implementation and command results.

## Existing board model

`Board` has a workspace FK, title, star flag, template flag, note colors, board memberships, canvas
elements, comments, timeline entries, public-link flag and created/updated timestamps. There was no
archive or trash state.

`BoardVoter` grants `BOARD_VIEW`, `BOARD_EDIT`, `BOARD_COMMENT` and `BOARD_SHARE` using board
membership roles, with the existing workspace-owner/admin bypass. `BoardRepository` lists only
boards where the user is a board member, plus every board in the user's workspace for workspace
administrators. Public boards are reachable by direct link only and are not listed across
workspaces.

## Existing API

- `GET /api/boards` (optional `workspaceId`) — active/accessible summaries.
- `POST /api/boards` — workspace administrators create a board and become its board Owner.
- `GET /api/boards/{id}` and `/timeline` — board detail/history; anonymous access only when
  `publicAccess` is enabled.
- `PATCH /api/boards/{id}` — title and star only, with `BOARD_EDIT`.
- `POST /api/boards/{id}/star` — board-wide star toggle.
- `/elements`, `/comments`, `/presence`, `/stream`, `/members`, `/invitations`, `/access`,
  `/restore/{timelineEntryId}` — existing content and sharing APIs.
- No delete, archive or trash endpoint existed. The dashboard's trash action only removed a card
  from local React state; the trash view was an unconditional empty state.

## Confirmed gaps and risks

1. No persisted lifecycle state or timestamps; dashboard reload restored a locally “trashed” board.
2. Trash and archive were not separate concepts and had no authorization endpoints.
3. Active board queries could not distinguish lifecycle states.
4. Board content writes were possible on any board the voter allowed, with no archived/trashed
   guard.
5. Public links could expose a board regardless of a future lifecycle state.
6. No approved retention policy or permanent-deletion workflow exists. The existing copy claimed a
   “30 days” retention that was never implemented; it must not be presented as real.
7. Related records (elements, comments, timeline, invitations, memberships) have cascading ORM
   relationships but there is no approved permanent-delete policy to exercise them.

## Selected lifecycle model

A single `lifecycle_status` value (`active`, `archived`, `trashed`) plus nullable `archived_at` and
`trashed_at` timestamps. A board has exactly one state; the inactive timestamp is cleared on every
transition, so it cannot hold both archive and trash metadata.

Allowed transitions:

- `active → archived` (archive)
- `active → trashed` or `archived → trashed` (trash)
- `archived → active` (restore)
- `trashed → active` (restore)
- restore of an already active board is an idempotent `200`
- archive of a non-active board and trash of an already trashed board are `409`

Permanent deletion is **PLANNED**, not implemented: no retention duration or deletion policy is
approved. Trashed boards are retained indefinitely. No automatic purge job is introduced.

## Permission plan

Lifecycle management uses a dedicated `BOARD_MANAGE` voter attribute, separate from content access:
board Owner/Admin or a workspace administrator. Archive, trash and restore require this management
permission. Ordinary board members may discover their own trashed board metadata but cannot restore
it. Workspace membership alone never grants access to an unjoined private board.

Content rules:

- Active boards: existing view/edit/comment/share rules.
- Archived boards: authorized board members and workspace administrators may view; edit, comment
  and share are denied until restored.
- Trashed boards: normal board view/content endpoints are denied; only lifecycle management and
  metadata listing are available.
- Public anonymous view and timeline reads require an active, publicly shared board.

## Listing and frontend plan

`GET /api/boards?status=active|archived|trashed` returns only accessible boards in that exact state.
The dashboard keeps one authoritative list for the current view; archive and trash use the same
API and card component, with restore instead of content navigation for trash. Archive/trash actions
show confirmation, call the backend first and only then refresh the list. Search/pagination are not
added because the existing dashboard has neither and the bounded board collection does not justify
new controls.

## Database plan

Add an additive migration after `Version20261115180000`:

- `boards.lifecycle_status` default `active` (existing rows become active)
- nullable `boards.archived_at` and `boards.trashed_at`
- `(workspace_id, lifecycle_status)` index for the lifecycle queries

No existing migration is edited. Test on the preserved dev database and on a disposable fresh
database with up/down/up, then validate the schema. No volume reset.

## Audit plan

No durable audit table exists. Lifecycle actions use the application logger with actor, workspace,
board and resulting state, matching Sprint 2's administrative logging. A durable audit trail is
**PLANNED**; no event-sourcing system is introduced.

## Verification plan

- Backend lifecycle tests for transitions, repeated/invalid transitions, authorization, cross-
  workspace isolation, removed members, archived/trashed content denial, public-link isolation and
  preserved elements/timeline.
- Frontend jsdom tests for archive/trash actions, restore, archive/trash loading, empty/error states
  and API failure without optimistic removal.
- Existing backend, frontend, PHPStan, CS-Fixer, lint, production build and migration gates.
