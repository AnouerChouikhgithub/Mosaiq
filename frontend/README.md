# Mosaiq frontend

React 19 + Vite 8 + TypeScript 5.9 single-page app for Mosaiq: the eight screens from
[`../docs/ui-spec.md`](../docs/ui-spec.md), the design-token system, FR/EN/AR copy and the
Light/Dark/System theme.

## Commands

Run from this directory (Node 22, pnpm 12):

```bash
pnpm install
pnpm run dev      # Vite dev server, default http://localhost:5173
pnpm run lint     # ESLint
pnpm run build    # tsc -b && vite build
pnpm test         # Vitest + jsdom
pnpm run preview  # serve the production build
```

The repository root forwards the same four checks with `npm run lint`, `npm run build`,
`npm start` and `npm test`.

## API configuration

The client in `src/api/client.ts` defaults to `http://127.0.0.1:8000/api`. To point it at another
local Symfony port, create an ignored `frontend/.env.local`:

```dotenv
VITE_API_BASE_URL=http://127.0.0.1:8001/api
```

Requests carry the session bearer token from `sessionStorage`, time out after 5 seconds, and
surface the API's `{ "error": … }` message when one is returned. An authenticated 401 clears the
session and redirects to `/sign-in?expired=1`; login/registration 401s remain credential errors.
Screens keep working with the typed data in `src/mocks/` when the API is unreachable, and log a
single warning through `warnFallback`; that fallback is demo behaviour, not persistence.

## AI brainstorming

Turns a natural-language prompt into a structured brainstorming proposal, which the user reviews
and edits, and — only after explicit approval — imports into a brand-new Mosaiq board. Generation
persists nothing: the preview is local state and no board, element or timeline row exists until the
user presses *Create the board*. Approval then uses the normal `POST /api/boards` and idempotent
`POST /api/boards/{id}/elements` endpoints, so an imported board is an ordinary board with ordinary
collaboration, timeline, sharing and permissions. Creating a board needs workspace administration;
non-administrators can generate and edit but not import.

The endpoint needs the backend configuration described in
[`../backend/README.md`](../backend/README.md) (`AI_ENABLED` plus the provider variables). Without it
generation fails closed with `503 ai.provider_unavailable` and the dialog shows *AI is not
configured*; the rest of Mosaiq is unaffected.

Entry points (dashboard): the *Brainstorm with AI* button in the header and the *Assisted
brainstorming* card in the board-creation/template grid. Both open
`src/features/ai/BrainstormingDialog.tsx`.

| Component | Responsibility |
| --- | --- |
| `src/features/ai/BrainstormingDialog.tsx` | Form, validation, consent notice, generating/error/cancelled states |
| `src/features/ai/BrainstormingPreview.tsx` | Editable proposal preview (title, objective, sections, notes, types, reordering) |
| `src/features/ai/useBrainstormingGeneration.ts` | One-request-at-a-time state machine with cancel and retry |
| `src/features/ai/proposalDraft.ts` | Pure reducer for preview edits and contract caps |
| `src/features/ai/validation.ts` | Form and preview validation from the shared limits |
| `src/features/ai/errors.ts` | Contract error codes → translated states |
| `src/features/ai/limits.ts` | `AI_BRAINSTORMING_LIMITS`, note-type colours, §7.3 layout |
| `src/api/aiBrainstorming.ts` | Dedicated client: 35 s budget, response shape guard, contract error mapping; the real endpoint is the only transport |
| `src/features/ai/importProposal.ts` | Approval path: re-validates the draft, creates the board and idempotent notes, compensates a failure |
| `src/features/ai/layout.ts` | Deterministic note placement that fits every contract-valid proposal on the canvas |

The wire types mirror `docs/architecture/AI_BRAINSTORMING_CONTRACT.md` v1.0. Do not change them
without changing that document first.

### Configuration

```dotenv
# Hide every AI entry point (default: on)
VITE_AI_BRAINSTORMING_ENABLED=false
```

There is no frontend mock transport: `generateBrainstormingProposal` always sends
`POST /api/ai/brainstorming/generate` and the browser never sees a provider key. Disabling the flag
removes both dashboard entry points and mounts nothing; board creation, the editor and every other
workflow keep working. The backend also fails closed on its own (`AI_ENABLED=0`).

## Structure

```
src/
  api/          typed API client, wire types, SSE reader, mock fallback helpers
  components/
    board/      canvas, thumbnail, properties, colour tokens
    comments/   threaded comments panel
    command/    Ctrl+K command palette
    layout/     app shell and header controls
    share/      share dialog
    ui/         reusable primitives (button, input, dialog, toast, …)
  i18n/         FR/EN/AR dictionaries, provider, RTL handling
  mocks/        typed Tunisian sample data
  features/ai/  generation form, preview editor, limits, validation, mock
  screens/      sign-in, dashboard, editor, time travel, public board, settings
  tests/        Vitest suites
  theme/        CSS tokens + theme context/provider
```

## Known limitations

* Board title edits are persisted (PATCH), but "move to trash" in the dashboard and role changes
  in workspace settings are still local-only demo controls.
* The shape, connector, text, pen and image tools are not implemented; only select, note and
  comment change canvas behaviour.
* Collaboration is SSE presence/canvas invalidation with a polling fallback and last-write-wins
  semantics. There is no Yjs/Hocuspocus CRDT or offline merge.
* The "E2E" suite is a jsdom integration test with a mocked API; there is no real-browser
  Playwright suite yet.
* AI brainstorming imports into a **new** board only (never into an existing one), and there is no
  server-side transaction spanning the board and its notes: a failed import is undone by a
  compensating rollback (delete the notes this import created, then trash the board), and a retried
  approval after a lost response creates a second board. Section headings are stored as note metadata
  and rendered only in the preview. Responsive and RTL coverage is jsdom assertions on layout classes
  and `dir`, not a real-browser or automated accessibility audit.

Design rules and conventions live in [`../AGENTS.md`](../AGENTS.md); the audit and repair record
is in [`../docs/technical-audit.md`](../docs/technical-audit.md) and
[`../docs/repair-report.md`](../docs/repair-report.md).
