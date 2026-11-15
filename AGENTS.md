# Mosaiq

Real-time collaborative whiteboard: shared canvas with sticky notes, shapes, live cursors and a time-travel replay of how the board was built.

## Status

- Frontend: design foundation plus all eight `docs/ui-spec.md` screens are implemented (light and dark) with FR/EN/AR localization. The dashboard and board editor load data through `frontend/src/api/client.ts` and fall back to typed mock data from `frontend/src/mocks/` when the API is unreachable.
- Backend: Symfony 7.4 with Doctrine entities, repositories, hashed bearer-token authentication, board/workspace voters, JSON API controllers, durable snapshots, comments, sharing controls, presence, migrations, fixtures and functional tests.
- Sync: `GET /api/boards/{id}/stream` sends authenticated SSE presence/canvas frames; the client reconnects every 500 ms, shows **En direct / Synchronisation… / Mode hors ligne**, and keeps a 3 s REST poll fallback (cursor writes are throttled to 250 ms). This is last-write-wins, not Yjs/Hocuspocus CRDT or conflict-free merge.
- Quality gates (verified 2026-11-15): root `pnpm run lint`, `pnpm run build` (0 chunk warnings) and `pnpm test` (7 files, 11 tests); backend PHPUnit (33 tests, 194 assertions), PHPStan level 5 (`composer analyse`), PHP-CS-Fixer (`composer cs:check`), `composer validate`, `composer audit` (no advisories), container and schema checks pass. `.github/workflows/ci.yml` runs all of these but has not been executed on GitHub; the browser check used one client.

## Brand

Main color: `#6D4AFF` (light) / `#8B73FF` (dark). Use it sparingly as the single primary color via the Tailwind token `primary`. Keep the UI clean, modern and accessible (WCAG AA contrast in both modes).

## Layout

- `backend/` — Symfony (PHP) REST API
- `frontend/` — React + Vite + TypeScript web app
- `docker-compose.yml` — Postgres, Redis, Mailpit (dev services only)
- `docs/ui-spec.md` — UI source of truth
- `DEVELOPMENT_ROADMAP.md` — phased plan and verified status

## Frontend stack (installed)

- React 19, Vite 8, TypeScript 5.9 (strict, `erasableSyntaxOnly` — no enums or parameter properties)
- Tailwind CSS 4 via `@tailwindcss/vite`
- Radix-based primitives in the shadcn/ui style (`frontend/src/components/ui`), `class-variance-authority`, `clsx`, `tailwind-merge`
- `lucide-react` icons (single-stroke set, 1.75px)
- React Router for navigation

## Backend stack

- Installed: PHP >= 8.3 (the locked PHPUnit 12 floor, also used by CI), Symfony 7.4, Doctrine ORM 3 / DBAL 4, DoctrineBundle, DoctrineMigrationsBundle, SecurityBundle, `symfony/mailer`.
- Not installed: API Platform, LexikJWT, Symfony Messenger, Symfony Validator and constraints, `symfony/rate-limiter`, Doctrine/Symfony PHPStan extensions. The consolidated proposal and the decisions they depend on are in `docs/technical-audit.md` §6; do not install them piecemeal.

## UI conventions

- Every color lives in `frontend/src/theme/tokens.css`; components use semantic utilities (`bg-surface`, `text-muted-foreground`, `border-border`, `bg-primary`), never raw hex or palette classes.
- Light/dark is class-based (`dark` on `<html>`) with a Light / Dark / System provider in `frontend/src/theme/`.
- Reuse the primitives in `frontend/src/components/ui` and the typography utilities (`text-display`, `text-title`, `text-body`, `text-caption`). Extract a component when a pattern appears twice.
- Design states for lists and data screens: loading skeletons, empty state (`EmptyState`), error with retry, success via toast.
- API calls belong in `frontend/src/api/client.ts`. Screens catch failures, call `warnFallback(...)` and keep mock data so the demo stays interactive.
- Keep UI copy short and human. French is the default locale; every shell/editor label must exist in `frontend/src/i18n/dictionary.ts` for FR, EN and AR.
- The dashboard, editor, replay and sign-in headers share `HeaderControls` (language + light/dark/system theme). Arabic must set `document.documentElement.dir = 'rtl'`; French and English stay `ltr`.
- Sample data stays Tunisian (names, cities, TND amounts) and lives only in `src/mocks/`.

## Local setup

- Optional services: inspect `docker-compose.yml` and `docker compose ps` first, then `docker compose up -d` when needed. Containers are project-named `mosaiq-db`, `mosaiq-redis` and `mosaiq-mailpit`. The Compose Postgres volume (`mosaiq_mosaiq_db_data`) is persistent; never reset or delete it.
- Intended local database URL (local dev only, `backend/.env.local`): `DATABASE_URL="postgresql://app:app@127.0.0.1:5432/mosaiq?serverVersion=16&charset=utf8"`.
- Backend: `cd backend && composer install`; set that `DATABASE_URL`, `MAILER_DSN="smtp://127.0.0.1:1025"` and `APP_FRONTEND_URL="http://localhost:5173"`, then run `php bin/console doctrine:migrations:migrate --no-interaction` and `php bin/console app:fixtures:load`. The loader refuses non-local or non-disposable targets; never point it at a shared or production database. Tests use `MAILER_DSN="null://null"`.
- API server: `cd backend && symfony serve` (default port 8000); use `VITE_API_BASE_URL` in ignored `frontend/.env.local` if local port assignment differs. A development CORS subscriber allows loopback Vite origins only.
- Frontend: `npm start` from the repository root (delegates to `frontend`); Vite uses 5173 and chooses the next free port if occupied.
- Checks: from the repository root, `pnpm run lint && pnpm run build && pnpm test`; from `backend`, `composer validate --no-check-publish && composer audit --locked && composer analyse && composer cs:check && composer test`, then `php bin/console lint:container` and `php bin/console doctrine:schema:validate`. `composer check` runs tests, PHPStan and the CS-Fixer dry run in sequence. The root scripts forward with `npm --prefix frontend run <script>`; keep that form.
- Mail catcher UI: http://localhost:8025.

## Rules

- TypeScript strict mode; no `any` unless justified in a comment.
- Keep the wire types in `frontend/src/api/client.ts` aligned with the PHP serializers. Once an OpenAPI document exists, generate the client from it (openapi-typescript or Orval) instead of extending the hand-written types.
- Small, focused commits (Conventional Commits). Add or update tests with every feature.
- Never commit secrets; use `.env.local`. Keep `.env` as documented defaults only.
- Prefer simple, readable code over clever code. Explain non-obvious decisions in short comments.
- Ask before adding a new dependency or changing the architecture.
- Treat Yjs/Hocuspocus CRDT sync, API Platform/OpenAPI, a remote CI run and two-client browser verification as incomplete. Email invitations are delivered through Mailpit; do not describe SSE last-write-wins as conflict-free CRDT sync or local fallback UI as a persisted server feature.
- Read `docs/technical-audit.md` before changing auth, canvas validation, workspace uniqueness, the realtime transport or the dependency set, and `docs/repair-report.md` for what is still deferred. Demo-only controls (dashboard trash, workspace member roles, five canvas tools) must not be described as persisted features.

## Docs and skills

- Before writing code that uses a library (React 19, Vite 8, TypeScript 7, Tailwind, shadcn/ui, Symfony, API Platform, Doctrine), look up the CURRENT docs with the Context7 tools if available. Do not rely on memory for APIs that changed recently.
- API Platform docs index for agents: https://api-platform.com/docs/llms.txt
- Load skills with /skill:<name> when relevant. Web: shadcn, web-design-guidelines, vercel-react-best-practices, vercel-composition-patterns. Backend: the api-platform skills (api-resource, state-provider, state-processor, serialization-groups, pagination, securing-collections, api-test).
- Only use skills and tools listed here. Ask before installing any other skill, MCP server or dependency.

## Working safely

- Capture `git status`, branch and HEAD before work; preserve untracked assets (including `frontend/public/Mosaiq.svg`) and existing edits.
- Do not stage, commit, push, deploy or run destructive migrations without authorization; never alter sibling repositories.
- Keep shared contracts (document schema, sync messages, API types, migrations) under one owner and serialize edits to overlapping files.
- Report exact changed paths and real command output. Never present a failed, blocked or unrun check as passing.
