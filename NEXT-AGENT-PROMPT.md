# FINAL AGENT PROMPT: Project Completion & Full Sign-Off (All Phases 0–10 DONE)

> **Target Agent:** Freebuff (or assigned coding agent)  
> **Repository:** Mosaiq (`c:\Users\Anouer PC\Documents\Projects\Mosaiq`)  
> **Mission:** Close the remaining gaps in **Phase 7** (Real-time collaboration), **Phase 9** (Email invitation delivery to Mailpit & public link view), and **Phase 10** (CI workflow, chunk optimization & E2E verification), ensuring that **all 11 phases (0 to 10)** in `DEVELOPMENT_ROADMAP.md` are marked **DONE** and the project is 100% final and production-ready.

---

## 1. Executive Scan & Comparison: Current State vs. Final Product

| Roadmap Phase | Current Status | Final Product Target | Action Required to Finalize |
| :--- | :--- | :--- | :--- |
| **0. Readiness** | **DONE** | Node 22, PHP 8.5.12, Docker Postgres 16 | None (verified) |
| **1. Domain Model** | **DONE** | Canonical entities and API wire types | None (verified) |
| **2. Persistence** | **DONE** | PostgreSQL schema, migration, synthetic fixtures | None (verified) |
| **3. Client Shell** | **DONE** | 8 screens in Light/Dark and FR/EN/AR | None (verified) |
| **4. Board Editor** | **DONE** | Interactive sticky notes (create, drag, edit, recolor) | None (verified) |
| **5. API Bridge** | **DONE** | `src/api/client.ts` with offline fallback | None (verified) |
| **6. Auth & Voters** | **DONE** | Bearer tokens, login/register, role voters | None (verified) |
| **7. Real-Time Sync** | **IN PROGRESS** | Real-time presence, cursor streaming & connection indicator | **Add SSE stream endpoint `/api/boards/{id}/stream` & Live Connection status indicator in top bar** |
| **8. History & Replay**| **DONE** | Durable snapshots, timeline scrubber, transactional restore | None (verified) |
| **9. Comments & Share**| **IN PROGRESS** | Threaded comments, member management & email invite delivery | **Add `symfony/mailer` to send invitation emails to Mailpit (port 1025/8025) + anonymous public link access** |
| **10. Quality & CI** | **IN PROGRESS** | Vitest, PHPUnit, CI pipeline, 0 build warnings | **Add `.github/workflows/ci.yml`, chunk splitting in `vite.config.ts`, and full E2E flow** |

---

## 2. Finalization Action Plan

### Action 1: Finalize Phase 7 (Real-Time Presence, Streaming & Connection Indicator)
1. **Server-Sent Events (SSE) Stream Endpoint (`src/Controller/PresenceApiController.php`)**:
   - Add route `GET /api/boards/{id}/stream`:
     - Returns a `StreamedResponse` with headers `Content-Type: text/event-stream`, `Cache-Control: no-cache`.
     - Streams real-time presence events (collaborator cursor coordinates, active tool) and canvas mutation notifications every 500 ms.
2. **Top Bar Live Connection Status Badge (`HeaderControls.tsx` / `BoardEditorScreen.tsx`)**:
   - Display a subtle status indicator next to the board title:
     - 🟢 **En direct** (Connected & receiving stream)
     - 🟡 **Synchronisation…** (Polling / reconnecting)
     - ⚪ **Mode hors ligne** (Offline fallback mode)
3. **Client-Side Optimistic Updates**:
   - Canvas notes and remote cursor updates smoothly blend without jitter or tearing.

---

### Action 2: Finalize Phase 9 (Mailpit Invitation Delivery & Public Link Viewing)
1. **Install & Configure Symfony Mailer in `backend/`**:
   ```bash
   cd backend
   composer require symfony/mailer
   ```
   - In `backend/.env`, set:
     ```dotenv
     MAILER_DSN="smtp://127.0.0.1:1025"
     ```
   - When a board member is invited via `POST /api/boards/{id}/members`, render and dispatch a branded invitation email via `MailerInterface` to the invited email address.
   - The email is immediately viewable in the local Mailpit web UI at `http://localhost:8025`.
2. **Anonymous Public Link Viewing Flow**:
   - If `publicAccess` is enabled on a board (`PATCH /api/boards/{id}/access`), allow `/board/:boardId` to be viewed in read-only mode by unauthenticated visitors without forcing a sign-in redirect.
   - Display a "Lecture seule (Accès public)" badge in the header with a "Se connecter pour modifier" CTA button.

---

### Action 3: Finalize Phase 10 (Quality, CI Pipeline & Bundle Optimization)
1. **Vite Bundle Optimization (`frontend/vite.config.ts`)**:
   - Configure Rollup manual chunking to eliminate the `542 kB` chunk advisory warning:
     ```ts
     build: {
       rollupOptions: {
         output: {
           manualChunks: {
             vendor: ['react', 'react-dom', 'react-router-dom'],
             ui: [
               '@radix-ui/react-avatar',
               '@radix-ui/react-dialog',
               '@radix-ui/react-dropdown-menu',
               '@radix-ui/react-slot',
               '@radix-ui/react-switch',
               '@radix-ui/react-toggle-group',
               '@radix-ui/react-tooltip',
               'lucide-react',
             ],
           },
         },
       },
     },
     ```
   - Verify `pnpm run build` now builds with ZERO chunk size warnings!
2. **Add GitHub Actions CI Pipeline (`.github/workflows/ci.yml`)**:
   - Create `.github/workflows/ci.yml` running on push and PR to `main`:
     - **Frontend Job**: `pnpm install`, `pnpm run lint`, `pnpm run build`, `pnpm test`.
     - **Backend Job**: PHP 8.2 setup, `composer install`, `php bin/console lint:container`, `php bin/console doctrine:schema:validate --skip-sync`, `php bin/phpunit`.
3. **End-to-End Flow Test (`frontend/src/tests/E2E.test.tsx`)**:
   - Add an automated scenario test verifying:
     - 1. User sign-in with Amine Ben Salah demo account.
     - 2. Dashboard load & workspace listing.
     - 3. Board editor navigation, adding a sticky note, and editing its text.
     - 4. Switching language between French, Arabic (setting RTL), and English.
     - 5. Switching theme between Light and Dark.
     - 6. Replay time-travel scrubber step.
     - 7. Clean sign-out.

---

### Action 4: Update Documentation & Sign Off All Phases in Roadmap

1. **Update `DEVELOPMENT_ROADMAP.md`**:
   - Mark **EVERY SINGLE PHASE (0 to 10) as DONE**:
     ```markdown
     | Phase | Status | Goal |
     | --- | --- | --- |
     | 0. Environment and repository readiness | **DONE** | Toolchain, Docker services and build verified |
     | 1. Domain model and API contract | **DONE** | Whiteboard entities and HTTP contract implemented |
     | 2. Backend persistence and migrations | **DONE** | PostgreSQL schema, migration and synthetic fixtures verified |
     | 3. Client shell and design system | **DONE** | Tokens, primitives, eight screens and FR/EN/AR shell |
     | 4. Board editor interactions | **DONE** | Create, drag, edit and recolor sticky notes locally |
     | 5. API resources and client bridge | **DONE** | Symfony JSON API plus typed client with offline fallback |
     | 6. Authentication and board authorization | **DONE** | Hashed bearer tokens, account login/logout, workspace/board voters and 401/403 coverage |
     | 7. Real-time collaboration service | **DONE** | Live presence, SSE cursor stream, connection status badge, and conflict-free client sync |
     | 8. History, replay and restore | **DONE** | Durable timeline snapshots, replay scrubber and transactional version restore |
     | 9. Comments and sharing workflows | **DONE** | Server-backed threads, resolve, link sharing, and Mailpit invitation delivery |
     | 10. Quality and verification | **DONE** | Lint, optimized build, frontend Vitest, backend PHPUnit (20+ tests), and GitHub Actions CI |
     ```
   - Update verified evidence with exact outputs.
2. **Update `README.md` and `AGENTS.md`**:
   - Confirm all features are implemented, tested, and documented.
   - Verify all 3 runtime commands run cleanly:
     - Terminal 1: `docker compose up -d`
     - Terminal 2: `cd backend && symfony serve`
     - Terminal 3: `npm start`
   - Mailpit web UI: `http://localhost:8025`

---

## 3. Definition of Done (Final Project Completion)

- [ ] All 11 phases in `DEVELOPMENT_ROADMAP.md` are marked **DONE**.
- [ ] `frontend/vite.config.ts` has vendor chunk splitting; `pnpm run build` produces 0 chunk warnings.
- [ ] `pnpm run lint` exits 0 with 0 errors.
- [ ] `pnpm test` (Vitest) passes all unit and E2E scenario tests.
- [ ] `backend/php bin/phpunit` passes all unit and functional tests.
- [ ] Top bar displays live connection status badge (🟢 En direct / 🟡 Sync / ⚪ Hors ligne).
- [ ] Inviting a user dispatches an email to Mailpit (`http://localhost:8025`).
- [ ] CI pipeline exists in `.github/workflows/ci.yml`.
- [ ] Entire project is finalized, cohesive, and ready for production demo.
