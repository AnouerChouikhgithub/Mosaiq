# Next agent prompt — Mosaiq after Sprint 3

**Baseline date:** 2026-11-15

**Branch:** `main` at `5b993fa`, plus the uncommitted Sprint 0–3 changes recorded in
[`docs/sprints/sprint-0-report.md`](docs/sprints/sprint-0-report.md),
[`docs/sprints/sprint-1-report.md`](docs/sprints/sprint-1-report.md),
[`docs/sprints/sprint-2-report.md`](docs/sprints/sprint-2-report.md) and
[`docs/sprints/sprint-3-report.md`](docs/sprints/sprint-3-report.md).

**Mission:** continue from the verified baseline. Do not rebuild, rename or redesign working
features, and do not treat a report's “DONE” claim as current evidence.

> Status labels: IMPLEMENTED, PARTIALLY IMPLEMENTED, PREPARED, PLANNED, BLOCKED, NOT VERIFIED.

## Read first

1. [`AGENTS.md`](AGENTS.md) — rules, stack and conventions.
2. [`README.md`](README.md) — setup, commands, security notes and current status.
3. [`docs/sprints/sprint-2-report.md`](docs/sprints/sprint-2-report.md) — workspace membership,
   roles, invitations, settings, removal and exact evidence.
4. [`docs/sprints/sprint-3-baseline.md`](docs/sprints/sprint-3-baseline.md) and
   [`docs/sprints/sprint-3-report.md`](docs/sprints/sprint-3-report.md) — board lifecycle state,
   permissions, API, migration and evidence.
5. [`docs/technical-audit.md`](docs/technical-audit.md) §9–10 and
   [`docs/repair-report.md`](docs/repair-report.md) before changing dependencies or deferred areas.
6. [`docs/ui-spec.md`](docs/ui-spec.md) before changing screens.

## Current status

| Area | Status | Evidence / limit |
| --- | --- | --- |
| Eight screens, FR/EN/AR + RTL, light/dark/system | **IMPLEMENTED** | Lint, production build and 10 frontend files / 28 jsdom tests |
| Auth, boards, canvas CRUD, comments, sharing, timeline/restore | **IMPLEMENTED** | PHPUnit 75 tests / 532 assertions; voters enforce access |
| Workspace members, roles, invitations, settings | **IMPLEMENTED** | Persisted member lifecycle, owner protection, hashed seven-day invitations, real settings API |
| Board active/archive/trash lifecycle | **IMPLEMENTED (reversible)** | `BOARD_MANAGE`, status-filtered listings, restore, archive/trash dashboard views |
| Permanent board deletion and retention | **PLANNED** | No approved retention, authorization or dependent-record policy; trash is indefinite |
| Durable audit trail | **PLANNED** | Workspace/board admin actions use the application logger only |
| Token lifecycle, password policy, throttling | **IMPLEMENTED** | Hashed expiring tokens, Symfony hasher, PostgreSQL `auth_attempts` |
| Email verification / password reset / password change | **BLOCKED / PLANNED** | No approved workflow; forgot-password remains demo-only |
| Multi-device sessions, CSP, HttpOnly cookies/CSRF | **PLANNED** | One active token; `sessionStorage` remains XSS-exposed |
| Presence / SSE canvas invalidation | **PARTIALLY IMPLEMENTED** | Last-write-wins; no CRDT, no offline merge, one-client browser check |
| Yjs/Hocuspocus, API Platform/OpenAPI, JWT, Messenger, Redis limiter | **PLANNED / BLOCKED** | Need owner approval; audit §6 |
| Real-browser Playwright E2E | **PLANNED** | jsdom tests with mocked API are not a browser E2E |
| GitHub Actions | **NOT VERIFIED** | Run `43715665395` failed at `composer install`; PHP 8.3 lock is fixed, no push/re-run |

## What Sprint 2 and 3 changed

- Workspace membership is embedded in `User` as `membership_status`/`membership_updated_at`.
  Removal revokes the token and all board memberships; one Owner remains; owner removal/role change,
  self-role change and admins granting Admin/Owner are denied by `WorkspaceMembershipPolicy`.
- Workspace invitations are 32-byte tokens stored as SHA-256, email-bound, seven-day and revocable;
  acceptance is registration-only for new accounts. Resend/decline, ownership transfer, self-leave,
  removed-account re-invitation and durable audit are **PLANNED/BLOCKED**.
- Boards have `lifecycle_status` (`active`/`archived`/`trashed`) plus `archived_at`/`trashed_at`.
  Allowed: active→archived, active/archived→trashed, archived/trashed→active. Repeated archive/trash
  are `409`; restoring an active board is idempotent `200`.
- `BOARD_MANAGE` controls lifecycle actions (board Owner/Admin or workspace administrator). Archived
  boards are view-only; trashed content, public reads and normal content endpoints are denied.
  Workspace membership never grants an unjoined private board; the existing workspace Admin board
  bypass is explicit and retained.
- Dashboard archive/trash views load from `GET /api/boards?status=...`, confirm archive/trash,
  restore from cards and show loading/empty/error states. Failed actions never remove cards
  optimistically.
- Migrations `Version20261115180000` and `Version20261115190000` are additive and applied to dev;
  eight migrations were verified on a fresh disposable database with up/down/up of the latest.
- Frontend test counts rose from 8/17 to 10/28; backend from 51/325 to 75/532.

## Hard constraints

- Do not commit, push, deploy, reset a database or delete the `mosaiq_mosaiq_db_data` volume.
- Do not add dependencies, change architecture or install API Platform/Yjs/RateLimiter without
  explicit owner approval. The consolidated proposal is `docs/technical-audit.md` §6.
- Do not weaken `BoardVoter`/`WorkspaceVoter`; hidden UI is never the authorization boundary.
- Do not describe SSE last-write-wins as CRDT, or PLANNED permanent deletion/retention as implemented.
- Do not claim email verification, password reset/change, multi-device sessions, distributed
  throttling, CSP or HttpOnly-cookie security. They are not implemented.
- Preserve `frontend/src/mocks/`, three locales, RTL, themes and the offline fallback.
- Add or update a test with every behaviour change; report exact commands and real results.

## Useful commands

```bash
# Frontend
cd frontend && pnpm run lint && pnpm run build && pnpm test

# Backend gates
cd backend && composer validate --no-check-publish && composer audit --locked
cd backend && composer analyse && composer cs:check && composer test
cd backend && composer check-platform-reqs
cd backend && php bin/console lint:container && php bin/console doctrine:schema:validate

# Fresh disposable schema (never the dev database)
docker exec mosaiq-db psql -U app -d postgres -c "CREATE DATABASE mosaiq_check;"
DATABASE_URL="postgresql://app:app@127.0.0.1:5432/mosaiq_check?serverVersion=16&charset=utf8" \
  php bin/console doctrine:migrations:migrate --no-interaction
```

Tests use `mosaiq_test`; `.env.test` sets lower throttle limits. The dev database keeps its fixture
data.

## Known issues not to reintroduce

- Workspace responses must never include boards the user cannot open, including counts/nested data.
- Public boards are link-only, active-only and must not appear in another user's board list.
- Invitation tokens stay hashed at rest and returned once to the authorized inviter; e-mails are
  bound to the token.
- `Board::$starred` is board-wide, so a Viewer can currently toggle it for everyone (product decision
  needed for per-user stars).
- Board summaries expose member e-mails to board viewers; the share dialog uses them.
- Registration returns `409` for an existing email, so it can enumerate accounts; login stays
  generic. Do not describe this as fixed.
- Tokens live in `sessionStorage` and there is no CSP. Expiry reduces the theft window only.
- The throttle uses PostgreSQL, not Redis; registration is IP-only and trusted proxies are unset.
- Permanent deletion and trash retention are not implemented. Do not add a purge job or a 30-day
  claim without product approval.
- Concurrent lifecycle requests use server-side state checks but no row locking; one may receive
  `409` rather than a guaranteed single-winner transition.
- CI has not produced a green GitHub run; verify it after an authorised push.
- Existing tokens are invalid after the Sprint 1 migration (null expiry); users sign in once.

## Suggested next steps

1. Obtain product approval for permanent deletion/retention and define authorization and
   dependent-record behavior; then add a controlled trash-only delete endpoint with tests.
2. Decide whether workspace Admin board access should remain the broad bypass; if not, tighten
   `BoardVoter` with regression tests.
3. Add a durable audit table for workspace/board administration actions if required.
4. Verify GitHub Actions on PHP 8.3 after an authorised push, including migrations and 75/532.
5. Decide on email verification/password reset, multi-device sessions and a CSP or cookie session.
6. Add real-browser coverage for archive/trash, settings and two-client collaboration.
