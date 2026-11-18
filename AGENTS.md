# Mosaiq

Real-time collaborative whiteboard: shared canvas with sticky notes, shapes, live cursors and a time-travel replay of how the board was built.

## Status

- Frontend: design foundation plus all eight `docs/ui-spec.md` screens are implemented (light and dark) with FR/EN/AR localization. The dashboard and board editor load data through `frontend/src/api/client.ts` and fall back to typed mock data from `frontend/src/mocks/` when the API is unreachable.
- Backend: Symfony 7.4 with Doctrine entities, repositories, expiring hashed bearer-token authentication, Symfony password hashing with a shared policy, database-backed credential throttling, board/workspace voters, JSON API controllers, durable snapshots, comments, sharing controls, workspace membership/invitations, persisted board active/archived/trashed lifecycle, presence, idempotent canvas creation (client `mutationId`, unique per board and author), migrations, fixtures and functional tests. Board creation and renaming commit together with their timeline entry. Permanent board deletion and trash retention are **PLANNED**.
- AI brainstorming: `POST /api/ai/brainstorming/generate` (Sprint 1A) is called by the dashboard dialog, which shows an editable preview and, after explicit approval, imports the proposal into a brand-new board through the normal board and idempotent element endpoints (Sprint 2). Generation persists nothing; the import is all-or-nothing by compensating rollback (delete the created notes, then trash the board) because there is no cross-request transaction or permanent board deletion. The deterministic layout in `frontend/src/features/ai/layout.ts` replaced contract §7.3.
- Sync: `GET /api/boards/{id}/stream` sends authenticated SSE presence/canvas frames; the client reconnects every 500 ms, shows **En direct / Synchronisation… / Mode hors ligne**, and keeps a 3 s REST poll fallback (cursor writes are throttled to 250 ms). This is last-write-wins, not Yjs/Hocuspocus CRDT or conflict-free merge.
- Quality gates (verified 2026-11-17, Sprint 5): root `pnpm run lint`, `pnpm run build` and `pnpm test` (11 files, 41 tests); backend PHPUnit (131 tests, 795 assertions), PHPStan level 5 (`composer analyse`), PHP-CS-Fixer (`composer cs:check`, 0/70), `composer validate`, `composer audit` (no advisories), container checks, fresh/existing/populated-database migrations and schema validation pass, and all PHP parses under 8.3. Sprint 5 evidence is in `docs/sprints/sprint-5-report.md`. `.github/workflows/ci.yml` gates these checks plus `composer check-platform-reqs` and the migration sequence, and the Sprint 3 run (`43735221770`) passed on `main`; the Sprint 4 branch run is recorded in its sprint report. Archive/trash/settings and note-persistence failure flows are covered by jsdom tests, not a real-browser pass.

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

- Installed: PHP >= 8.3 (the locked PHPUnit 12 floor, also used by CI); `composer.json` pins `config.platform.php` to `8.3.0` so the lock always resolves for that floor. Symfony 7.4, Doctrine ORM 3 / DBAL 4, DoctrineBundle, DoctrineMigrationsBundle, SecurityBundle, `symfony/mailer`, and Symfony's password hasher via SecurityBundle.
- Not installed: API Platform, LexikJWT, Symfony Messenger, Symfony Validator and constraints, `symfony/rate-limiter`, Doctrine/Symfony PHPStan extensions. The consolidated proposal and the decisions they depend on are in `docs/technical-audit.md` §6; do not install them piecemeal.
- Auth limits and token lifetime are environment-driven (`AUTH_TOKEN_TTL`, `AUTH_THROTTLE_ENABLED`, `AUTH_LOGIN_*`, `AUTH_REGISTER_*`). `.env` holds documented defaults; `.env.test` uses lower limits. The throttle stores SHA-256 keyed rows in `auth_attempts`, not Redis.

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
- Canvas creation is idempotent only when the client sends `mutationId`, and a retry must reuse the key minted for that operation. The key is scoped per board and author, never bypasses board authorization, and the database unique index plus the board row lock are the guarantee—not frontend state.
- Email verification, password reset/change, multi-device sessions, CSP and HttpOnly-cookie sessions are **not implemented**. Do not describe the `409` registration response as enumeration-safe, the DB throttle as distributed, or `sessionStorage` as XSS-proof. Read the Sprint 1 report before changing auth.
- Read `docs/technical-audit.md` before changing auth, canvas validation, workspace uniqueness, board lifecycle, the realtime transport or the dependency set, and `docs/repair-report.md` for what is still deferred. Workspace member roles, invitations and board archive/trash are persisted; the five canvas tools remain partly demo-only, and permanent board deletion/retention is **PLANNED**.

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
