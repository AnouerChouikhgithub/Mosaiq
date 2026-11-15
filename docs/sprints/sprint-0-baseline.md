# Sprint 0 — Baseline

Captured: 2026-11-15, before any Sprint 0 code change (local time ~14:06).
All values below were measured on this workstation; nothing is copied from an earlier report.

## 1. Repository state

| Item | Value |
| --- | --- |
| Branch | `main`, tracking `origin/main` |
| HEAD | `5b993fa629b1c5a756fcf6be4c6ccc9fe9e5a563` — `last changes` |
| Parent | `4f3689c` — `feat: finalize Mosaiq collaboration and release checks` |
| Remote | `https://github.com/AnouerChouikhgithub/Mosaiq.git` |
| Local PHP | 8.5.12 (ZTS, Windows) |
| Composer | 2.10.0 |
| pnpm / Node | pnpm 12.14.0 declared; frontend dependencies installed |

## 2. Working tree

Ten modified files, no staged changes, no untracked files. They are the previous
stabilization pass (fixture-loader metadata repair, migrations excluded from PHP-CS-Fixer,
PHPStan config simplification, documentation updates) and were preserved untouched.

```
 M AGENTS.md
 M DEVELOPMENT_ROADMAP.md
 M README.md
 M backend/.php-cs-fixer.dist.php
 M backend/migrations/Version20261114120000.php
 M backend/src/DataFixtures/SyntheticFixtureLoader.php
 M backend/tests/DataFixtures/SyntheticFixtureLoaderIntegrationTest.php
 M backend/phpstan.neon.dist
 M docs/repair-report.md
 M docs/technical-audit.md
```

`backend/migrations/Version20261114120000.php` differs from `5b993fa` only by the removal
of a formatter-added trailing newline; the file is identical to its `4f3689c` content.

## 3. Application architecture

- **Frontend** — React 19 + Vite 8 + TypeScript 5.9 (strict), Tailwind 4, Radix/shadcn-style
  primitives, React Router, FR/EN/AR with RTL, light/dark/system themes. Screens: dashboard,
  board editor, public board, replay, workspace settings, sign-in. `src/api/client.ts` is the
  only HTTP bridge and falls back to `src/mocks/` when the API is unreachable.
- **Backend** — Symfony 7.4 JSON API, Doctrine ORM 3 / DBAL 4, PostgreSQL 16, hashed bearer
  tokens (`ApiTokenAuthenticator`), `WorkspaceVoter` + `BoardVoter`, controllers for auth,
  workspaces, boards, canvas, comments, presence/SSE, timeline restore, invitations and mail.
- **Persistence** — 5 migrations, latest `Version20261115090000`; entities `Workspace`, `User`,
  `Board`, `BoardMembership`, `CanvasElement`, `CommentThread`, `CommentMessage`, `TimelineEntry`,
  `BoardPresence`, `BoardInvitation`. Schema validation reported in sync.
- **Local infrastructure** — `mosaiq-db` (PostgreSQL 16, healthy), `mosaiq-redis` (running, not
  used by the application), `mosaiq-mailpit` (healthy, ports 1025/8025). Persistent volume
  `mosaiq_mosaiq_db_data` was not touched.
- **Sync** — authenticated SSE presence/canvas frames with reconnect and a 3 s REST poll
  fallback; last-write-wins, not CRDT.

## 4. Test coverage (measured)

| Suite | Command | Result |
| --- | --- | --- |
| Frontend lint | `cd frontend && pnpm run lint` | PASS (ESLint, exit 0) |
| Frontend build | `cd frontend && pnpm run build` | PASS — `tsc -b` + Vite, 2207 modules, no chunk warnings |
| Frontend tests | `cd frontend && pnpm test` | PASS — 7 files, 11 tests (jsdom, mocked API) |
| Backend tests | `cd backend && composer test` | PASS — 34 tests, 202 assertions |
| Schema | `php bin/console doctrine:schema:validate` | PASS (mapping correct, database in sync) |
| Migrations | `php bin/console doctrine:migrations:status` | 5 executed, 5 available, 0 new |

Coverage gaps: there is no real-browser test (the `E2E.test.tsx` scenario is jsdom with a mocked
API), no security test for workspace responses, and no CI execution of the migration sequence on a
fresh database.

## 5. CI state (actual GitHub run)

Run `43715665395` on commit `5b993fa` (`CI`, push to `main`, 2026-11-15T12:47:54Z):

- **Frontend:** PASS in 32 s.
- **Backend:** FAIL in 22 s at `composer install --no-interaction --prefer-dist --no-progress`.
- Exact annotation:

  ```
  Your lock file does not contain a compatible set of packages. Please run composer update.
  doctrine/collections 3.1.0 requires php ^8.4 -> your php version (8.3.36) does not satisfy it.
  doctrine/instantiator 2.1.0 requires php ^8.4 -> your php version (8.3.36) does not satisfy it.
  doctrine/orm 3.7.6 requires doctrine/collections ^2.2 || ^3.1 -> satisfiable by 3.1.0.
  ```

Root cause: the lock file was resolved on PHP 8.5 without a `config.platform.php`, so it selected
packages whose minimum is PHP 8.4, while `composer.json` declares `>=8.3` and CI installs 8.3.
Earlier run `43619433683` on `4f3689c` also failed and was not investigated in this baseline.

## 6. Confirmed defects

1. **CI backend cannot install the lock on PHP 8.3** (evidence above). Release-blocking because
   no backend check has ever run on GitHub.
2. **Workspace board information disclosure** — `GET /api/workspaces/{id}` checks only
   `WORKSPACE_VIEW` and then returns `BoardSerializer::summary()` for *every* board in the
   workspace. `BoardVoter` restricts ordinary members to boards they belong to, so a workspace
   member who does not belong to a private board receives its id, title, member list, e-mails and
   element count. `GET /api/workspaces` also returns `boardCount` for all boards, leaking an
   aggregate about inaccessible boards.
3. **Documentation is stale and over-claims** — `NEXT-AGENT-PROMPT.md` still asks an agent to
   mark every phase DONE and claims conflict-free sync; `DEVELOPMENT_ROADMAP.md` reports 10 tests
   / 27 tests instead of the current 11 / 34; `docs/repair-report.md` says CI was never run on
   GitHub.

## 7. Potential defects requiring investigation

- Board summaries expose member e-mail addresses to every board viewer (previously deferred as
  B-12). They are used by the share dialog for accessible boards; no change without a product
  decision.
- No rate limiting on login/registration/invitation endpoints.
- Bearer token stored in `sessionStorage`; no CSP (deferred, audit B-11).
- Public boards remain reachable by direct link only; the board list excludes them by design.
- Workspace settings roles, dashboard trash and five canvas tools are demo-only controls.
- Invitation creation returns the raw token to the sharer by design; the database stores only its
  SHA-256 hash.

## 8. Functionality that must not regress

Authentication (login/register/me/logout), workspace and board voters, board list/create/rename/
star, canvas element CRUD and validation, comments/replies/resolve, board sharing/invitations,
public read-only link, timeline/replay/restore, presence writes and SSE framing, workspace
settings UI, FR/EN/AR translations and RTL, light/dark themes, mock fallback when the API is down,
and the existing backend/frontend test contracts.
