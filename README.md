# Mosaiq

Mosaiq is a collaborative whiteboard for teams: a shared canvas with sticky notes, shapes, connectors and live cursors, plus a time-travel replay of how a board was built.

Mosaiq includes eight screens from [`docs/ui-spec.md`](docs/ui-spec.md), **French, English and Arabic** copy (Arabic is RTL), and **light, dark and system** themes. The Symfony API supports bearer-token authentication, board roles, durable timeline snapshots/restores, comments, emailed invitations, read-only public links and short-lived SSE presence/canvas streams. Collaboration is last-write-wins SSE with a polling fallback, not Yjs/Hocuspocus CRDT synchronization.

Brand color: `#6D4AFF` (light) / `#8B73FF` (dark), used as the single primary color across the app.

## Highlights

- **Eight screens:** sign in/sign up, dashboard, board editor, time travel, share dialog, comments, command palette (`Ctrl K`) and workspace settings.
- **Board editor:** create sticky notes by clicking with the note tool, drag them, double-click to edit text, recolor from the properties panel or color chips, zoom and fit to screen.
- **Time travel:** play/pause, speed, contributor filters and snapshot-backed replay/restore.
- **Comments and sharing:** persisted threads/replies/resolve; owners/admins can manage board roles and create single-use, seven-day invitations stored as token hashes. Invitations are emailed through Mailpit and the share dialog also copies a `/sign-in?invite=…` link; when `publicAccess` is enabled, signed-out visitors get a read-only `/board/{boardId}` view with a public-access badge and sign-in CTA (`/share/{boardId}` remains an alias).
- **Presence:** authenticated cursor writes are throttled. `GET /api/boards/{id}/stream` sends short authenticated SSE frames every 500 ms (the client reconnects), and the editor shows **En direct / Synchronisation… / Mode hors ligne** with a three-second polling fallback. There is no CRDT merge or offline queue.
- **API bridge:** `frontend/src/api/client.ts` attaches the session bearer token and retains demo fallbacks when the API is unreachable.
- **Localized shell:** French is default; Arabic sets `dir="rtl"`.

## Stack

- **Frontend:** React 19, Vite 8, TypeScript 5.9 (strict), Tailwind CSS 4, Radix-based primitives in the shadcn/ui style, `lucide-react`, React Router.
- **Backend:** Symfony 7.4, PHP >= 8.2, Doctrine ORM 3 / DBAL 4, migrations, bearer-token authenticator, voters, JSON controllers and `symfony/mailer`. Tokens are hashed at rest. API Platform, JWT auth and Messenger are not installed.
- **Presence synchronization:** SSE (`text/event-stream`) with 500 ms client reconnect plus a polling fallback. Yjs/Hocuspocus CRDT and offline conflict resolution are not implemented.
- **Local services:** `docker-compose.yml` defines PostgreSQL 16 (`mosaiq-db`), Redis 7 (`mosaiq-redis`) and Mailpit (`mosaiq-mailpit`) for development only.

## Quickstart

Install dependencies once (`cd frontend && pnpm install`; `cd backend && composer install`), then use three separate terminals from the repository root:

```bash
# Terminal 1 — database and local services
docker compose up -d
```

```bash
# Terminal 2 — Symfony API
cd backend && symfony serve
```

```bash
# Terminal 3 — Vite frontend (root package.json forwards to frontend)
npm start
```

The API defaults to `http://127.0.0.1:8000/api`; set `VITE_API_BASE_URL` in ignored `frontend/.env.local` when the local Symfony server uses another port. Run migrations and fixtures once against the intended local database (never reset the persistent Compose volume):

```bash
cd backend
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:fixtures:load
```

For fixture demo accounts, the guarded fixture loader sets `MosaiqDemo2026!` for seeded users with no existing password. The sign-in quick-select offers Amine Ben Salah and Ons Trabelsi. Registration requires 12 characters.

Create `backend/.env.local` with:

```dotenv
DATABASE_URL="postgresql://app:app@127.0.0.1:5432/mosaiq?serverVersion=16&charset=utf8"
MAILER_DSN="smtp://127.0.0.1:1025"
APP_FRONTEND_URL="http://localhost:5173"
```

`app:fixtures:load` is idempotent and refuses anything except a loopback PostgreSQL database named `mosaiq` (or containing `test`/`dev`/`fixture`) outside `prod`. The Compose Postgres volume is persistent; do not reset it casually.

Mailpit UI: http://localhost:8025. Test runs override the DSN with `null://null`; production must configure a real transport.

## Checks

| Check | Status |
| --- | --- |
| `docker compose up -d` | **PASS** — Mosaiq DB healthy; persistent volume preserved |
| `cd frontend && pnpm run lint` | **PASS** — ESLint exits 0 |
| `cd frontend && pnpm run build` | **PASS** — TypeScript and Vite build, 0 chunk-size warnings (`index` 138.00 kB, `ui` 162.99 kB, `vendor` 247.30 kB) |
| `cd frontend && pnpm test` | **PASS** — 7 test files, 10 tests including public-board, live-badge and the E2E app flow |
| `cd backend && php bin/phpunit` | **PASS** — 27 tests, 151 assertions |
| `cd backend && php bin/console lint:container` | **PASS** |
| `cd backend && php bin/console doctrine:schema:validate` | **PASS** — local database schema in sync |
| Root `npm start` | **PASS** — HTTP 200 on Vite-selected port 5178; 5173–5177 were already occupied |
| Existing Symfony server | **PASS** — port 8001 returns 401 for unauthenticated protected API requests |
| CORS preflight | **PASS** — `http://localhost:5178` is allowed for local development |
| SSE stream | **PASS** — `text/event-stream` with `retry`, `ready`, `presence` and `canvas` frames; browser badge showed **En direct** |
| Mailpit delivery | **PASS** — branded invitation received at `http://localhost:8025` |
| Signed-out public board | **PASS** — `/board/{id}` with `publicAccess` on rendered sanitized notes and `Lecture seule (Accès public)` + `Se connecter pour modifier` without redirect |
| CI workflow | **PASS (syntax)** — `.github/workflows/ci.yml` has `frontend` and `backend` jobs; not executed on GitHub |

Vite splits vendor code (`react`/router), Radix UI primitives and the app into separate chunks, so the build reports no chunk-size warnings. Port 8000 was already occupied by a different project, so Mosaiq was verified on its existing local Symfony port 8001 instead.

## Current status

- **Verified:** bearer-token login/register/logout, board/workspace role enforcement, hashed API tokens, per-user workspace isolation, snapshot timeline/restore, comments, board sharing controls, SSE presence/canvas streams with reconnect, Mailpit invitation delivery, frontend lint/build/Vitest (including E2E), backend functional tests, schema and container checks.
- **Partially complete:** collaboration is SSE with last-write-wins and polling fallback, not conflict-free CRDT sync; invite acceptance has API/test coverage but no fresh visual pass; the browser check used one client, not two.
- **Not implemented:** Yjs/Hocuspocus CRDT and offline conflict handling, API Platform/OpenAPI generation, a remote GitHub Actions run, and a two-account browser acceptance pass.
- **Feature status:** phases 0–10 are **DONE** with the evidence in [`DEVELOPMENT_ROADMAP.md`](DEVELOPMENT_ROADMAP.md); known limits are listed there rather than hidden.

## Repository layout

```
backend/          Symfony 7.4 API (PHP >= 8.2)
  src/Entity/     Doctrine whiteboard entities
  src/Repository/ typed repositories
  src/Security/   board and workspace voters
  src/Controller/ development JSON endpoints
  src/DataFixtures/ synthetic catalog and idempotent loader
  migrations/     PostgreSQL schema migration
frontend/         React + Vite + TypeScript app
  src/api/        typed API client and mock fallback helpers
  src/theme/      color tokens (single source) + Light/Dark/System provider
  src/i18n/       FR/EN/AR dictionaries, provider and RTL handling
  src/components/ reusable UI, board and header controls
  src/screens/    the eight screens
  src/mocks/      typed Tunisian sample data
docs/ui-spec.md   UI specification — source of truth
DEVELOPMENT_ROADMAP.md  phased plan and verified status
docker-compose.yml      Postgres, Redis, Mailpit (dev services)
```

## Documentation

- [`docs/ui-spec.md`](docs/ui-spec.md) — design system, screens and states.
- [`DEVELOPMENT_ROADMAP.md`](DEVELOPMENT_ROADMAP.md) — execution phases and their verified status.
- [`AGENTS.md`](AGENTS.md) — engineering rules and conventions for contributors and coding agents.

Never commit secrets: keep local values in untracked `.env.local` files and treat tracked `.env` files as documented defaults only.
