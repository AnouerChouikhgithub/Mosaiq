# Sprint 2 baseline — workspace administration and member management

**Date:** 2026-11-15  
**Branch / HEAD:** `main` at `5b993fa` plus the uncommitted Sprint 0 and Sprint 1 work.  
**Repository state:** no commit, push, deployment, database reset or volume deletion was performed.

This baseline was produced by inspecting the current code, not by trusting earlier status reports.
The implementation plan at the end follows the existing architecture and records the product
decisions that are intentionally **PLANNED / BLOCKED**.

## 1. Sprint 1 gate

| Check | Result |
| --- | --- |
| Backend suite | **PASS** — 51 tests / 325 assertions on the current tree |
| Frontend suite | **PASS** — 8 files / 17 tests |
| Token lifecycle | **PASS** — opaque tokens, SHA-256 at rest, expiry, rotation, logout revocation |
| Sensitive endpoint protection | **PASS** — `/api` requires `ROLE_USER`; only login/register and public board reads are public |
| Session expiry handling | **PASS (jsdom)** — authenticated 401 clears the session and redirects to `/sign-in?expired=1` |
| GitHub Actions | **NOT VERIFIED** — no authorised push; run `43715665395` predates Sprint 0's lock fix |
| Known Sprint 1 limits | Email verification, password reset, multi-device sessions, CSP and distributed throttling remain unimplemented |

No Sprint 1 issue blocks workspace administration. The relevant protection is that every workspace
endpoint is behind the authenticated API firewall and the token authenticator rejects expired or
revoked credentials.

## 2. Current workspace architecture

### Entities

- `Workspace` has `id`, `name`, `plan`, `pricePerMember`, `currency`, `seats`, `renewsOn` and
  `createdAt`; it owns `users` and `boards` collections.
- `User` is both the account and the **single-workspace membership record**: it has one non-null
  `workspace` FK, a `role` (`Owner`, `Admin`, `Editor`, `Commenter`, `Viewer`), `createdAt`, the
  password hash and the single API token hash.
- `Board` has its own `BoardMembership` join (`board_members`) with board roles. Workspace
  membership and board membership are deliberately separate.
- `BoardInvitation` is a **board-level** invitation: board, inviter, email, board role, token hash,
  seven-day expiry and accepted timestamp. It is not a workspace invitation.

There is no `WorkspaceMembership` entity. In the current single-workspace product, adding one next
to `User.workspace`/`User.role` would duplicate the same relationship and create two competing
sources of truth. The Sprint 2 decision is therefore to **extend the existing membership record on
`User`** with lifecycle metadata, and to introduce a separate `WorkspaceInvitation` because no
workspace invitation concept exists. A future multi-workspace product will need a dedicated
membership table and an active-workspace selector; that is a redesign and is **PLANNED**, not done
here.

### Current API

| Method | Endpoint | Authorization |
| --- | --- | --- |
| GET | `/api/workspaces` | authenticated; returns the caller's workspace summary |
| GET | `/api/workspaces/{id}` | `WORKSPACE_VIEW`; returns filtered board summaries |
| POST | `/api/auth/login` | public |
| POST | `/api/auth/register` | public; creates a workspace or joins via a board invitation |
| GET | `/api/auth/me` | authenticated; returns the caller and home workspace |
| POST | `/api/boards/{id}/invitations` | `BOARD_SHARE`; board invitation |
| POST | `/api/boards/invitations/{token}/accept` | authenticated board invitation acceptance |

Missing: workspace member listing, member role changes, member removal, workspace settings updates,
workspace invitations/revocation, membership status, removal token invalidation and workspace
administration tests.

### Current authorization

- `WorkspaceVoter`: `WORKSPACE_VIEW` for the caller's workspace; `WORKSPACE_ADMINISTER` for Owner or
  Admin. It checks `User.workspace` and has no membership-status concept.
- `BoardVoter`: requires the caller's workspace to match the board's workspace; workspace
  Owner/Admin bypass board membership, otherwise the board role controls view/comment/edit/share.
- `WorkspaceApiController::show()` reuses the filtered board query, so hidden private boards are not
  leaked through the workspace payload. Sprint 0 regression tests cover this.
- `WorkspaceApiController` has no member or settings mutations.
- `BoardApiController` enforces board-level authorization and checks workspace equality for shares.

## 3. Current frontend

- `/settings` renders `SettingsScreen`, but it imports `workspace`, `teamMembers` and `users` from
  `frontend/src/mocks`. Role selects mutate local React state only and toast success immediately.
- The invite action copies a hard-coded demo URL. There is no invitation form, pending list,
  revocation or real mail integration.
- The appearance card is real (theme provider); the plan/billing card is display-only.
- `AppShell` also reads the mock workspace name and seat count.
- The sign-in screen already supports board-invitation tokens (`?invite=`) and demo accounts.

## 4. Confirmed issues

| ID | Issue | Risk |
| --- | --- | --- |
| W-01 | No workspace member API; settings member table is mock/local-only | **High** — administration does not persist |
| W-02 | No workspace settings update endpoint | **High** — name cannot be changed |
| W-03 | No workspace invitation entity or lifecycle | **High** — only board invites exist |
| W-04 | No membership status/removal; removing workspace access is impossible | **High** — cannot revoke a member safely |
| W-05 | No last-owner/admin/self-role protections because no role mutation exists | **High** once mutations are added |
| W-06 | `WorkspaceVoter`/`BoardVoter` do not check membership status | **High** — a stale workspace record would keep authorizing |
| W-07 | Role logic is split between `User::canAdministerWorkspace()`, `WorkspaceVoter` and controllers | Medium — easy to drift |
| W-08 | Workspace invite copy link is hard-coded and the member data is fake | **High** — UI misrepresents persistence |
| W-09 | Workspace admin board bypass is implicit in `BoardVoter` | Medium — must be documented as an explicit product policy |
| W-10 | No workspace audit trail | Medium — deferred pending retention/visibility decision |
| W-11 | Removed accounts cannot be re-invited in the current single-workspace login model | Medium — documented limitation, not silently invented |
| W-12 | No ownership transfer | Low — not approved; documented as **PLANNED** |

## 5. Product decisions and permission matrix

The existing five role names are retained so current data and board-role defaults do not change.
For workspace administration, `Editor`, `Commenter` and `Viewer` are **Member** roles.

| Capability | Owner | Admin | Member (Editor/Commenter/Viewer) |
| --- | --- | --- | --- |
| View workspace and member list | Yes | Yes | Yes |
| Update workspace name | Yes | Yes | No |
| List/revoke invitations | Yes | Yes | No |
| Invite Admin | Yes | No | No |
| Invite Editor/Commenter/Viewer | Yes | Yes | No |
| Change another member's role to Admin | Yes | No | No |
| Change another member's role among Member roles | Yes | Yes | No |
| Change an Admin's role | Yes | No | No |
| Change own role | No (last-owner protection) | No | No |
| Remove Admin | Yes | No | No |
| Remove Member | Yes | Yes | No |
| Remove Owner | No | No | No |
| Remove self / leave workspace | No in Sprint 2 (**PLANNED**) | No | No |
| Transfer ownership | No (**PLANNED**, requires approval) | No | No |
| Board access | BoardVoter; workspace Owner/Admin may access all boards in this workspace (**explicit existing policy**) | Same | Only boards where the user has a board membership |

Additional decisions:

- A workspace has exactly one Owner in Sprint 2. Owner grants and ownership transfer are not
  implemented; this protects the last owner without inventing a transfer workflow.
- A removed member is marked `removed`, has the API token revoked and all board memberships in the
  workspace deleted. Login returns the generic invalid-credentials response, and every workspace
  endpoint rejects the account. Re-inviting a removed existing account needs an account-recovery
  decision and is **BLOCKED / PLANNED**.
- Workspace invitations only grant workspace membership, never board access. Board invitations keep
  their existing separate lifecycle.
- Workspace invitation roles are Admin/Editor/Commenter/Viewer; Owner is never invitable.
- Member emails are visible to active workspace members, matching the existing board collaborator
  payloads. No password hash, token hash, invitation token or security metadata is returned.
- Workspace invitations are single-use, hashed at rest, expire after seven days and are revocable.
- Workspace names are 2–120 characters after trimming. Only `name` is client-writable; plan,
  seats, price, owner and internal fields are protected.
- Workspace Owner/Admin board bypass is retained as an explicit policy and covered by tests; it is
  not a new permission. Workspace membership alone still does not grant private-board access to
  ordinary members.
- Audit logging is **DEFERRED**: `TimelineEntry` is board-scoped and user-visible, and a workspace
  audit log needs a retention/visibility decision. Administration actions will be logged through
  the application logger without secrets; a durable audit table is **PLANNED**.

## 6. Implementation plan

1. Extend `User` with `membershipStatus`, `membershipUpdatedAt`, active-membership checks and
   status-aware administration helpers; add an additive migration and backfill existing members as
   active.
2. Centralize the matrix in a `WorkspaceMembershipPolicy` service and make the voters/repositories
   status-aware.
3. Add `WorkspaceInvitation` entity/repository, extend the existing mailer with a workspace invite
   method, and implement create/list/revoke/registration acceptance.
4. Complete `WorkspaceApiController`: update settings, list members, change roles, remove members,
   invitation endpoints and acceptance; keep board authorization untouched.
5. Extend `AuthApiController` registration for workspace invitation tokens and reject inactive
   memberships at login/authentication.
6. Replace the mock settings member table with real loading, member listing, role management,
   removal confirmation, invitation form/list/revocation and workspace name persistence.
7. Add backend authorization/invitation/settings/private-board tests and frontend settings tests;
   run backend/frontend/static/build/migration gates.
8. Write `docs/sprints/sprint-2-report.md` and update the roadmap, next-agent prompt and audit.
