# Mosaiq

Mosaiq is a collaborative whiteboard for teams: a shared canvas with sticky notes, shapes,
connectors and live cursors, plus a time-travel replay of how a board was built.

The repository contains the eight screens from [`docs/ui-spec.md`](docs/ui-spec.md) — sign in /
sign up, dashboard, board editor, time travel, share dialog, comments, command palette and
workspace settings — in **French, English and Arabic** (Arabic is RTL), with **light, dark and
system** themes.

Brand color: `#6D4AFF` (light) / `#8B73FF` (dark), used as the single primary color.

> **Status honesty:** collaboration is authenticated **SSE presence/canvas invalidation with a
> 3-second polling fallback and last-write-wins semantics**. It is not Yjs/Hocuspocus CRDT sync,
> and two-client sync has not been browser-verified. The current audit, confirmed defects and
> remaining limitations are recorded in [`docs/technical-audit.md`](docs/technical-audit.md) and
> [`docs/repair-report.md`](docs/repair-report.md).

## Implementation status

| Area | Status | Notes |
| --- | --- | --- |
| Eight UI screens + FR/EN/AR + light/dark/system | **IMPLEMENTED** | `frontend/src/screens`, `frontend/src/i18n`, `frontend/src/theme` |
| Design tokens and reusable primitives | **IMPLEMENTED** | `frontend/src/theme/tokens.css`, `frontend/src/components/ui` |
| Board create/list/rename/star, canvas notes, comments, sharing, invitations, replay/restore | **IMPLEMENTED** | Symfony JSON API + typed client; tests cover the contract |
| Workspace settings, members and invitations | **IMPLEMENTED** | Persisted roles, removal, hashed invitations, name settings and owner/admin policy (Sprint 2) |
| Board archive and trash | **IMPLEMENTED** | Persisted active/archived/trashed states, restore and status-filtered dashboard views (Sprint 3) |
| Browser navigation and history | **IMPLEMENTED** | React Router, route guards, `Ctrl K` palette |
| Presence and canvas updates | **PARTIALLY IMPLEMENTED** | Short authenticated SSE frames + 500 ms reconnect + 3 s poll are verified; CRDT/offline merge is **PLANNED** and semantics are last-write-wins |
| Shape / connector / text / pen / image tools | **PARTIALLY IMPLEMENTED** | Connectors and frames render from stored data; creating with the five tools is **PLANNED** |
| Yjs/Hocuspocus document sync | **PLANNED** | Design recorded in the audit; needs approval and a Node service |
| API Platform / OpenAPI-generated client | **PLANNED** | Hand-written controllers and wire types today |
| Auth hardening: token expiry, password policy, credential throttling | **IMPLEMENTED** | Hashed expiring tokens, Symfony hasher, DB-backed login/registration limits (Sprint 1) |
| Email verification and password reset | **BLOCKED / PLANNED** | No approved token/mail workflow; the forgot-password link stays demo-only |
| JWT auth, Messenger/Redis queue, Redis rate limiter | **PLANNED** | Proposed, not installed (approval gate) |
| CSP / HttpOnly cookie session | **PLANNED** | Tokens remain in `sessionStorage`; expiry is the current mitigation |
| Playwright real-browser E2E | **PLANNED** | A jsdom scenario test with a mocked API exists; it is not a browser test |
| Permanent board deletion / trash retention | **PLANNED** | Trash is recoverable indefinitely; no retention or purge policy is approved |
| GitHub Actions CI | **NOT VERIFIED** | Run `43715665395` passed the frontend and failed the backend at `composer install` (lock required PHP 8.4). The lock is fixed for PHP 8.3 and CI now creates the `_test`-suffixed database; a re-run is pending a push |

## Highlights

- **Board editor:** create sticky notes with the note tool, drag them, edit text inline, recolor
  from the properties panel or canvas chips, rename the board (persisted), zoom and fit to screen.
- **Time travel:** play/pause, speed, contributor filters and snapshot-backed replay/restore.
- **Comments:** persisted threads, replies and resolve/reopen, created through an in-app dialog.
- **Sharing:** owner/admin board roles, single-use seven-day invitations stored as token hashes,
  Mailpit delivery, and read-only public links when `publicAccess` is enabled.
- **Presence:** authenticated cursor writes throttled to 250 ms; short SSE frames and a polling
  fallback. There is no CRDT merge or offline queue.
- **Security:** expiring hashed bearer tokens, Symfony password hashing with a shared 12–72
  character policy, database-backed login/registration throttling, server-side board/workspace
  voters, sanitized public payloads, loopback-only development CORS and API security headers.

## Stack

- **Frontend:** React 19, Vite 8, TypeScript 5.9 (strict), Tailwind CSS 4, Radix-based primitives
  in the shadcn/ui style, `lucide-react`, React Router, Vitest + Testing Library.
- **Backend:** Symfony 7.4, **PHP >= 8.3**, Doctrine ORM 3 / DBAL 4, migrations, bearer-token
  authenticator, voters, JSON controllers and `symfony/mailer`. API Platform, JWT auth, Messenger
  and Symfony Validator are not installed.
- **Quality:** ESLint, `tsc -b`, Vite build, Vitest, PHPUnit, PHPStan level 5, PHP-CS-Fixer,
  Composer validation and `composer audit`.
- **Local services:** `docker-compose.yml` defines PostgreSQL 16 (`mosaiq-db`), Redis 7
  (`mosaiq-redis`, reserved for Messenger) and Mailpit (`mosaiq-mailpit`), development only.

PHP 8.3 is the floor because the locked PHPUnit 12 requires it; CI uses PHP 8.3 and
`composer.json` pins `config.platform.php` to `8.3.0` so future updates keep the lock
installable on that floor. The backend suite was additionally parsed with PHP 8.3.36.

## Quickstart

Prerequisites: Node 22, pnpm 12, PHP 8.3+ with `pdo_pgsql`, Composer 2, Docker.

Install dependencies once:

```bash
cd frontend && pnpm install && cd ..
cd backend && composer install && cd ..
```

Start the three processes from the repository root:

```bash
# Terminal 1 — database, Redis, Mailpit
docker compose up -d

# Terminal 2 — Symfony API (default port 8000)
cd backend && symfony serve

# Terminal 3 — Vite frontend
npm start
```

Create `backend/.env.local` for the local services (it is gitignored):

```dotenv
DATABASE_URL="postgresql://app:app@127.0.0.1:5432/mosaiq?serverVersion=16&charset=utf8"
MAILER_DSN="smtp://127.0.0.1:1025"
APP_FRONTEND_URL="http://localhost:5173"
```

Apply the schema and seed the demo data once (the loader refuses non-local or non-disposable
targets; never reset the persistent Compose volume):

```bash
cd backend
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:fixtures:load
```

The API defaults to `http://127.0.0.1:8000/api`; set `VITE_API_BASE_URL` in an ignored
`frontend/.env.local` when Symfony uses another port. Demo accounts (Amine Ben Salah, Ons
Trabelsi) are offered on the sign-in screen after seeding; the fixture password is
`MosaiqDemo2026!` and registration requires 12–72 characters with at least one letter and one
digit.

Mailpit UI: <http://localhost:8025>.

## Quality checks

Run from the repository root unless a `cd` is shown:

```bash
pnpm run lint && pnpm run build && pnpm test
cd backend && composer validate --no-check-publish && composer audit --locked
cd backend && composer analyse && composer cs:check && composer test
cd backend && composer check-platform-reqs
cd backend && php bin/console lint:container && php bin/console doctrine:schema:validate
docker compose config --quiet
```

The same sequential backend gate is available as `cd backend && composer check`.

| Check | Result (2026-11-15) |
| --- | --- |
| Root `pnpm run lint` | **PASS** — ESLint exits 0 |
| Root `pnpm run build` | **PASS** — `tsc -b` + Vite 8; `index` 143.35 kB, `ui` 162.99 kB, `vendor` 247.30 kB; no chunk warnings |
| Root `pnpm test` | **PASS** — 10 files, 28 tests (Sprint 3 adds archive/trash and settings lifecycle coverage) |
| `composer validate --no-check-publish` | **PASS** |
| `composer audit --locked` | **PASS** — no advisories after the Symfony 7.4.23 patch update |
| `composer analyse` (PHPStan level 5, src + tests) | **PASS** — 0 errors |
| `composer cs:check` | **PASS** — 0 of 65 files need fixing (`migrations/` excluded) |
| `composer test` (PHPUnit) | **PASS** — 75 tests, 532 assertions |
| `composer check-platform-reqs` | **PASS** on the local PHP 8.5 runtime; the lock itself has no PHP 8.3 blocker (`composer why-not php 8.3.0`) |
| PHP 8.3 syntax pass | **PASS** — all `src`/`tests` files parsed by PHP 8.3.36 in Docker |
| `php bin/console lint:container` | **PASS** |
| Fresh migration sequence | **PASS** — 8 migrations, including up/down/up of the Sprint 3 lifecycle migration on a disposable database; full schema validation passed |
| `docker compose config --quiet` | **PASS** |
| `docker compose ps` | `mosaiq-db` healthy, `mosaiq-mailpit` healthy, `mosaiq-redis` running |
| GitHub Actions | **NOT VERIFIED** — run `43715665395` failed at `composer install`; the PHP 8.3 lock is fixed and every job step was run locally, but the workflow has not been re-run on GitHub |

Not verified in this pass: a successful GitHub Actions run after the lock fix, real-browser
two-client collaboration, invite-link browser signup, the full UI click-through, the expired-session
redirect in a real browser, Mailpit's web UI, and performance/accessibility audits.

## Security notes

- Bearer tokens are random 48-byte values stored as SHA-256 hashes. They expire after
  `AUTH_TOKEN_TTL` (default 604800 seconds / 7 days), rotate on login (one active token per user),
  and are revoked on logout. The browser keeps the token in `sessionStorage` (XSS-exposed,
  documented) and sends it in the `Authorization` header.
- Passwords are hashed with Symfony's configured hasher. The shared policy requires 12–72 bytes
  and at least one letter and one digit; the maximum avoids bcrypt truncation. There is no
  breach-list check.
- Login is limited per email + IP (5 failures / 15 min) and per IP (20 failures / 15 min) by
  default; registration is limited per IP (10 / hour). Exhaustion returns `429` with `Retry-After`.
  Test limits are lower (3 and 5). Throttling is PostgreSQL-backed and pruned on write; it is not
  distributed across independent databases.
- The API sets `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` and
  `X-Frame-Options: DENY` on `/api`, and `Cache-Control: no-store` on `/api/auth`. An authenticated
  browser 401 clears the session and redirects to `/sign-in?expired=1` with a translated notice.
- Board and workspace permissions are enforced by `BoardVoter` / `WorkspaceVoter`; hidden UI is
  never the authorization boundary. `GET /api/workspaces/{id}` filters boards through the same
  membership/administrator rule as `/api/boards` and counts only those boards, so workspace
  membership never reveals an unjoined private board (Sprint 0 regression tests).
- Anonymous access is limited to `GET` board/canvas/timeline reads when `publicAccess` is on, and
  the public serializer omits members, comments, timeline and author emails.
- **Not implemented:** email verification, password reset/change, multi-device sessions, CSP and
  HttpOnly-cookie sessions. Registration returns `409` for an existing email, so it can enumerate
  accounts; login stays generic. These are product/dependency decisions, not claimed protections.
- **Proxy caveat:** the app has no trusted-proxy configuration. Behind a reverse proxy, set
  `SYMFONY_TRUSTED_PROXIES` or all clients share the proxy's IP-based throttle bucket.
- Never commit secrets. Keep local values in untracked `.env.local` files and supply a unique
  `APP_SECRET` through the environment in production.

### Production environment checklist

- `APP_ENV=prod`, `APP_DEBUG=0`, a unique high-entropy `APP_SECRET` and HTTPS-only ingress.
- PostgreSQL `DATABASE_URL`, `MAILER_DSN`, `APP_FRONTEND_URL` and `AUTH_TOKEN_TTL` (seconds; minimum
  60, default 604800).
- `AUTH_THROTTLE_ENABLED=1` plus `AUTH_LOGIN_MAX_ATTEMPTS`, `AUTH_LOGIN_IP_MAX_ATTEMPTS`,
  `AUTH_LOGIN_WINDOW_SECONDS`, `AUTH_REGISTER_MAX_ATTEMPTS` and `AUTH_REGISTER_WINDOW_SECONDS`.
- `SYMFONY_TRUSTED_PROXIES` when a reverse proxy terminates TLS; otherwise per-IP limits are ineffective.
- The frontend and API share an origin, or the API is reverse-proxied under the same origin; the
  development CORS allowlist is loopback-only and is not a production CORS policy.
- Existing tokens are invalid after the Sprint 1 migration (their expiry is null); users must sign
  in once.

## Repository layout

```
backend/                 Symfony 7.4 API (PHP >= 8.3)
  src/Controller/        JSON endpoints
  src/Entity/            11 Doctrine entities (adds AuthAttempt)
  src/Repository/        typed repositories and access-scoped queries
  src/Security/          bearer authenticator + board/workspace voters
  src/Service/           board/canvas serializers, timeline, mailer
  src/DataFixtures/      idempotent synthetic catalog and guarded loader
  migrations/            PostgreSQL schema migrations
  tests/                 PHPUnit functional, fixture and safety tests
  phpstan.neon.dist      static analysis at level 5
frontend/                React + Vite + TypeScript app
  src/api/               typed API client, SSE reader, fallback helpers
  src/components/        board, comments, command, layout, share, ui
  src/i18n/              FR/EN/AR dictionaries and RTL handling
  src/mocks/             typed Tunisian sample data
  src/screens/           the eight screens
  src/theme/             tokens + Light/Dark/System provider
  README.md              frontend commands and limitations
docs/
  ui-spec.md             UI source of truth
  technical-audit.md     findings, priorities and dependency proposal
  repair-report.md       what was fixed, verified and deferred
  sprints/               Sprint 0–3 baselines and reports
docker-compose.yml       Postgres, Redis, Mailpit (dev services)
AGENTS.md                engineering rules and conventions
```

## Documentation

- [`docs/ui-spec.md`](docs/ui-spec.md) — design system, screens and states.
- [`docs/technical-audit.md`](docs/technical-audit.md) — audit findings and the dependency
  approval proposal.
- [`docs/repair-report.md`](docs/repair-report.md) — repairs, exact results and remaining debt.
- [`docs/sprints/sprint-0-report.md`](docs/sprints/sprint-0-report.md) — Sprint 0 stabilization:
  CI root cause, workspace authorization fix and verification evidence.
- [`docs/sprints/sprint-1-report.md`](docs/sprints/sprint-1-report.md) — Sprint 1 auth hardening:
  token expiry/rotation, password policy, throttling, UI 401 handling, evidence and remaining risks.
- [`docs/sprints/sprint-2-report.md`](docs/sprints/sprint-2-report.md) — Sprint 2 persisted
  workspace members, roles, invitations, settings and removal policy.
- [`docs/sprints/sprint-3-report.md`](docs/sprints/sprint-3-report.md) — Sprint 3 board archive,
  trash, restore, lifecycle authorization and dashboard consistency.
- [`docs/sprints/sprint-4-report.md`](docs/sprints/sprint-4-report.md) — Sprint 4 correctness and
  security stabilization: public canvas lifecycle, JSON object payloads, canvas/timeline atomicity
  and note save/retry behavior.
- [`DEVELOPMENT_ROADMAP.md`](DEVELOPMENT_ROADMAP.md) — phased plan and its verification record.
- [`AGENTS.md`](AGENTS.md) — contributor and coding-agent conventions.
