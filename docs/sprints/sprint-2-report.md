# Sprint 2 — workspace administration and member management

**Result:** COMPLETE for the approved Sprint 2 scope. Ownership transfer, self-leave,
removed-account re-invitation, invitation resend/decline and a durable audit table remain
deliberately deferred and are recorded as **PLANNED/BLOCKED**, not silently invented.

**Date:** 2026-11-15

## 1. Sprint 1 gate

The current tree was inspected rather than trusting the Sprint 1 report. The Sprint 1 gate passed:
expiring hashed bearer tokens, single-token rotation/revocation, Symfony password hashing and the
shared password policy, database-backed login/registration throttling, security headers and
GET-only no-store auth responses were present. The pre-Sprint-2 baseline was **51 backend tests /
325 assertions** and **8 frontend files / 17 tests**. The gate's remaining limitations (email
verification, password reset, multi-device sessions, CSP/cookie sessions, distributed throttling)
do not block workspace administration.

## 2. Initial architecture

Mosaiq had a single active workspace per account. Membership was embedded in `User`
(`workspace` FK plus `role`), so there was no `WorkspaceMembership` table to duplicate. Existing
roles were `Owner`, `Admin`, `Editor`, `Commenter`, `Viewer`; board and workspace voters already
enforced access. The workspace settings screen used local mock members and local role selections.

## 3. Membership model changes

- `User` gained `membership_status` (`active`/`removed`) and `membership_updated_at`, plus
  `hasActiveWorkspaceMembership()`, `isWorkspaceOwner()`, `removeFromWorkspace()`,
  `canAdministerWorkspace()` and status-aware `changeRole()`.
- Membership identity remains the user id. No duplicate membership entity was introduced; a
  dedicated multi-workspace membership model is **PLANNED**.
- Removed members are filtered from active queries. Removal revokes the API token and removes all
  board memberships in the workspace. Login and authenticated endpoints reject inactive membership
  with a generic `401`.
- One Owner per workspace is enforced. Owner removal, self-removal and owner-role changes are
  rejected. Ownership transfer is **PLANNED** and has no endpoint.

## 4. Permission matrix

| Operation | Owner | Admin | Member (Editor/Commenter/Viewer) |
| --- | --- | --- | --- |
| View workspace/member list | Yes | Yes | Yes |
| Change workspace name | Yes | Yes | No |
| Invite Admin | Yes | No | No |
| Invite Editor/Commenter/Viewer | Yes | Yes | No |
| Change Admin ↔ Member roles | Yes | No | No |
| Change Member roles | Yes | Yes | No |
| Remove Admin | Yes | No | No |
| Remove Member | Yes | Yes | No |
| Remove/change Owner | No | No | No |
| Remove self | No (no self-leave workflow) | No | No |
| View/restore workspace invitations | Yes | Yes | No |

Board permissions remain separate. Workspace membership does not grant access to unjoined private
boards. The pre-existing workspace Owner/Admin board-management bypass is retained as an explicit
policy and documented here rather than treated as a membership-derived permission.

## 5. Backend changes

- `backend/src/Entity/User.php` — membership lifecycle, active-membership checks and role policy.
- `backend/src/Entity/Workspace.php` — active member queries, name validation and owner lookup.
- `backend/src/Entity/WorkspaceInvitation.php` — hashed invitation lifecycle.
- `backend/src/Repository/WorkspaceInvitationRepository.php` — token/pending/list queries.
- `backend/src/Repository/UserRepository.php` — active member list/count queries.
- `backend/src/Security/WorkspaceMembershipPolicy.php` — single source of truth for the matrix.
- `backend/src/Controller/WorkspaceApiController.php` — settings, members, roles, removal,
  invitations and logging.
- `backend/src/Controller/AuthApiController.php` — workspace invitation registration and inactive
  membership login denial.
- `backend/src/Service/BoardInvitationMailer.php` — workspace invitation email using the existing
  mailer.
- `backend/src/Security/ApiTokenAuthenticator.php`, `WorkspaceVoter.php`, `BoardVoter.php`,
  `BoardRepository.php` — status-aware access and listing.
- `backend/migrations/Version20261115180000.php` — additive membership columns/index and
  `workspace_invitations` table.
- `backend/tests/Controller/WorkspaceAdministrationTest.php` — **18 tests / 112 assertions**.

## 6. API endpoints

| Method | Path | Authorization | Result |
| --- | --- | --- | --- |
| GET | `/api/workspaces` | Active session | Workspace summary |
| GET | `/api/workspaces/{id}` | Active member | Summary + accessible boards |
| PATCH | `/api/workspaces/{id}` | Owner/Admin | Rename only; 422 for other fields |
| GET | `/api/workspaces/{id}/members` | Active member | Active members without secrets |
| PATCH | `/api/workspaces/{id}/members/{userId}` | Owner/Admin + policy | Updated member; 422/403/404 |
| DELETE | `/api/workspaces/{id}/members/{userId}` | Owner/Admin + policy | `204`; token/board access revoked |
| GET | `/api/workspaces/{id}/invitations` | Owner/Admin | Pending/historical invitations |
| POST | `/api/workspaces/{id}/invitations` | Owner/Admin + role policy | `201`, hashed invitation, `emailSent` |
| DELETE | `/api/workspaces/{id}/invitations/{id}` | Owner/Admin | `204`; revoke pending invitation |
| POST | `/api/auth/register` | Public | Optional `workspaceInvitationToken`; transactional account + membership |

Workspace invitation acceptance is registration-only for new accounts. Existing accounts and resend
are **PLANNED**. Invitation role `Owner` is rejected. Invitation tokens are 32 random bytes, stored
only as SHA-256, expire after seven days and are bound to the invited email.

## 7. Workspace settings

The settings screen now loads `/api/workspaces`, members and pending invitations from the backend.
The workspace name is persisted through `PATCH /api/workspaces/{id}`. Role changes and removals are
sent to the backend and only reflected after success. The invite dialog validates email/role,
shows pending invitations, supports revocation and reports mail failure instead of claiming success.
Session workspace state is persisted and broadcast so the shell/dashboard name and member count
update without a reload. FR/EN/AR and RTL are preserved.

## 8. Security decisions

- Backend authorization is authoritative; frontend role checks only control visibility.
- Cross-workspace member/invitation IDs return `404` or `403` without leaking resource details.
- Owner removal/change, self-role changes and administrator privilege escalation are denied.
- Removed accounts cannot log in or use an old token; board memberships are removed in the same
  transaction.
- No password hashes, API tokens or invitation secrets are returned by member/list serializers.
- An application logger records administrative actions without secrets; a durable audit table is
  **PLANNED**.

## 9. Database migration

`Version20261115180000` adds `users.membership_status` (default `active`), nullable
`membership_updated_at` backfilled from `created_at`, an `(workspace_id, membership_status)` index,
and the `workspace_invitations` table with FK constraints and indexes. It was applied additively to
the existing dev database; the eight-migration sequence also applied cleanly to a fresh disposable
database and `doctrine:schema:validate` passed. The dev database and Docker volume were not reset.

## 10. Verification

| Area | Exact command | Result |
| --- | --- | --- |
| Backend tests | `cd backend && composer test` | **PASS** — 69 tests, 437 assertions at Sprint 2 completion |
| Frontend tests | `pnpm test` | **PASS** — 9 files, 24 tests |
| Static analysis | `cd backend && composer analyse` | **PASS** — no errors |
| Lint | `pnpm run lint` | **PASS** |
| Production build | `pnpm run build` | **PASS** |
| CS | `cd backend && composer cs:check` | **PASS** — 0 of 64 files |
| Migration | `php bin/console doctrine:migrations:migrate --no-interaction` | **PASS** — `Version20261115180000` applied additively |
| Schema | `php bin/console doctrine:schema:validate` | **PASS** |

## 11. Known limitations and deferred decisions

- Ownership transfer and member self-leave are **PLANNED** (no approved workflow).
- Re-inviting a removed account and invitation resend/decline are **PLANNED/BLOCKED** pending
  account-recovery/product decisions.
- Audit logging is application logs only; a durable audit trail is **PLANNED**.
- Multi-workspace membership is **PLANNED**; membership id equals user id.
- Workspace Owner/Admin board bypass remains the existing explicit policy, not a new grant.
- The GitHub Actions run is still **NOT VERIFIED** because no push was authorized.
- No real-browser pass of the settings flows was performed; jsdom covers the API contract.

## 12. Sprint 3 handoff

Board lifecycle work can build on the active-membership checks, workspace voter, member removal
transaction and persisted workspace/session model. The board model is unchanged by Sprint 2, so
Sprint 3 must add lifecycle state without changing the existing board permission split. Removed
members are already denied by the authenticator and voters; board lifecycle tests should keep that
regression covered.
