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
surface the API's `{ "error": … }` message when one is returned. Screens keep working with the
typed data in `src/mocks/` when the API is unreachable, and log a single warning through
`warnFallback`; that fallback is demo behaviour, not persistence.

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

Design rules and conventions live in [`../AGENTS.md`](../AGENTS.md); the audit and repair record
is in [`../docs/technical-audit.md`](../docs/technical-audit.md) and
[`../docs/repair-report.md`](../docs/repair-report.md).
