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
| Workspace settings member roles | **PARTIALLY IMPLEMENTED** | Roles and plan are mock/local; no workspace membership endpoint yet |
| Dashboard trash | **PARTIALLY IMPLEMENTED** | Local-only demo control; no soft-delete/retention endpoint |
| Browser navigation and history | **IMPLEMENTED** | React Router, route guards, `Ctrl K` palette |
| Presence and canvas updates | **IMPLEMENTED (interim)** | Short authenticated SSE frames + 500 ms reconnect + 3 s poll; last-write-wins |
| Shape / connector / text / pen / image tools | **PARTIALLY IMPLEMENTED** | Connectors and frames render from data; the five tools do not create anything |
| Yjs/Hocuspocus document sync | **NOT IMPLEMENTED** | Design recorded in the audit; needs approval and a Node service |
| API Platform / OpenAPI-generated client | **NOT IMPLEMENTED** | Hand-written controllers and wire types |
| JWT auth, Messenger/Redis queue, rate limiting | **NOT IMPLEMENTED** | Proposed, not installed (approval gate) |
| Playwright real-browser E2E | **NOT IMPLEMENTED** | Current "E2E" test is jsdom with a mocked API |
| Board deletion / trash retention | **FUTURE** | Needs a product decision (hard vs soft delete) |

## Highlights

- **Board editor:** create sticky notes with the note tool, drag them, edit text inline, recolor
  from the properties panel or canvas chips, rename the board (persisted), zoom and fit to screen.
- **Time travel:** play/pause, speed, contributor filters and snapshot-backed replay/restore.
- **Comments:** persisted threads, replies and resolve/reopen, created through an in-app dialog.
- **Sharing:** owner/admin board roles, single-use seven-day invitations stored as token hashes,
  Mailpit delivery, and read-only public links when `publicAccess` is enabled.
- **Presence:** authenticated cursor writes throttled to 250 ms; short SSE frames and a polling
  fallback. There is no CRDT merge or offline queue.
- **Security:** hashed bearer tokens, server-side board/workspace voters, sanitized public
  payloads, loopback-only development CORS.

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

PHP 8.3 is the floor because the locked PHPUnit 12 requires it; CI uses PHP 8.3.

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
`MosaiqDemo2026!` and registration requires 12 characters.

Mailpit UI: <http://localhost:8025>.

## Quality checks

Run from the repository root unless a `cd` is shown:

```bash
pnpm run lint && pnpm run build && pnpm test
cd backend && composer validate --no-check-publish && composer audit --locked
cd backend && composer analyse && composer cs:check && composer test
cd backend && php bin/console lint:container && php bin/console doctrine:schema:validate
docker compose config --quiet
```

The same sequential backend gate is available as `cd backend && composer check`.

| Check | Result (2026-11-15) |
| --- | --- |
| Root `pnpm run lint` | **PASS** — ESLint exits 0 |
| Root `pnpm run build` | **PASS** — `tsc -b` + Vite 8; `index` 142.17 kB, `ui` 162.99 kB, `vendor` 247.30 kB; no chunk warnings |
| Root `pnpm test` | **PASS** — 7 files, 11 tests |
| `composer validate --no-check-publish` | **PASS** |
| `composer audit --locked` | **PASS** — no advisories after the Symfony 7.4.23 patch update |
| `composer analyse` (PHPStan level 5, src + tests) | **PASS** — 0 errors |
| `composer cs:check` | **PASS** — 0 of 57 files need fixing |
| `composer test` (PHPUnit) | **PASS** — 33 tests, 194 assertions |
| `php bin/console lint:container` | **PASS** |
| `php bin/console doctrine:schema:validate` | **PASS** after migration `Version20261115090000` |
| `docker compose config --quiet` | **PASS** |
| `docker compose ps` | `mosaiq-db` healthy, `mosaiq-mailpit` healthy, `mosaiq-redis` running |
| GitHub Actions | **NOT RUN on GitHub** — workflow now also gates Composer validation, advisories, PHPStan and CS-Fixer |

Not verified in this pass: real-browser two-client collaboration, invite-link browser signup, the
full UI click-through, and performance/accessibility audits.

## Security notes

- Bearer tokens are random 48-byte values stored as SHA-256 hashes; the browser keeps them in
  `sessionStorage` (XSS-exposed, documented) and sends them in the `Authorization` header.
- Board and workspace permissions are enforced by `BoardVoter` / `WorkspaceVoter`; hidden UI is
  never the authorization boundary.
- Anonymous access is limited to `GET` board/canvas/timeline reads when `publicAccess` is on, and
  the public serializer omits members, comments, timeline and author emails.
- Login and registration are **not rate-limited** and emails are **not verified**; both need the
  approved dependencies listed in `docs/technical-audit.md` §6.
- Never commit secrets. Keep local values in untracked `.env.local` files and supply a unique
  `APP_SECRET` through the environment in production.

## Repository layout

```
backend/                 Symfony 7.4 API (PHP >= 8.3)
  src/Controller/        JSON endpoints
  src/Entity/            10 Doctrine entities
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
docker-compose.yml       Postgres, Redis, Mailpit (dev services)
AGENTS.md                engineering rules and conventions
```

## Documentation

- [`docs/ui-spec.md`](docs/ui-spec.md) — design system, screens and states.
- [`docs/technical-audit.md`](docs/technical-audit.md) — audit findings and the dependency
  approval proposal.
- [`docs/repair-report.md`](docs/repair-report.md) — repairs, exact results and remaining debt.
- [`DEVELOPMENT_ROADMAP.md`](DEVELOPMENT_ROADMAP.md) — phased plan and its verification record.
- [`AGENTS.md`](AGENTS.md) — contributor and coding-agent conventions.
