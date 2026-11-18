# Sprint 1B — Frontend AI brainstorming experience

**Branch:** `feat/ai-brainstorming-frontend`
**Contract:** `docs/architecture/AI_BRAINSTORMING_CONTRACT.md` v1.0 and
`docs/architecture/adr/ADR-001-ai-generation-isolated-from-persistence.md`
**Scope:** generation + editable preview only. No board is created, no proposal is persisted and
the Symfony backend is untouched.

## 1. Entry points

| Location | Control | Behaviour |
| --- | --- | --- |
| Dashboard header (`view=boards`) | Secondary button *Brainstorm with AI* / *Brainstormer avec l’IA* / *عصف ذهني بالذكاء الاصطناعي* | Opens the generation dialog |
| Dashboard board-creation grid | *Assisted brainstorming* card, first in the template grid | Opens the same dialog |
| Full templates view (`?view=templates`) | Same card | Opens the same dialog |

Both are hidden when `VITE_AI_BRAINSTORMING_ENABLED=false`. The rest of the dashboard, board
creation, templates and lifecycle actions are unchanged.

## 2. Component architecture

```
DashboardScreen
└── BrainstormingDialog            form + all generation states
    ├── useBrainstormingGeneration  idle → generating → success | error | cancelled
    ├── validation.ts               validateAiForm (submit gate)
    └── BrainstormingPreview        local draft, useReducer(proposalDraftReducer)
        └── validateProposalDraft   advisory contract limits
```

| File | Purpose |
| --- | --- |
| `frontend/src/features/ai/types.ts` | v1.0 wire types (`AiBrainstormingRequest/Response`, enums) |
| `frontend/src/features/ai/limits.ts` | `AI_BRAINSTORMING_LIMITS`, note-type→colour, §7.3 layout |
| `frontend/src/features/ai/validation.ts` | character counting, normalisation, form + preview validation |
| `frontend/src/features/ai/errors.ts` | `AiErrorKind`, `AiGenerationError`, HTTP/code mapping |
| `frontend/src/features/ai/config.ts` | feature flags, per-session provider notice |
| `frontend/src/features/ai/mockResponse.ts` | contract-shaped mock (FR/EN/AR, per detail level) |
| `frontend/src/features/ai/proposalDraft.ts` | pure reducer: edit, add/remove, reorder, caps |
| `frontend/src/features/ai/useBrainstormingGeneration.ts` | single-flight request lifecycle |
| `frontend/src/features/ai/BrainstormingDialog.tsx` | form, consent, generating/error/cancelled UI |
| `frontend/src/features/ai/BrainstormingPreview.tsx` | editable proposal preview |
| `frontend/src/features/ai/AiBrainstormCard.tsx` | dashboard entry card |
| `frontend/src/api/aiBrainstorming.ts` | dedicated client, 35 s budget, shape guard, mock switch |

Shared-file edits: `frontend/src/api/client.ts` exports `API_BASE_URL`; `ui/input.tsx` gains an
optional `Field` counter and uses logical padding for the select chevron; `ui/dialog.tsx` close
button uses logical `end-4` so RTL dialogs mirror correctly; `i18n/dictionary.ts` gains the `ai.*`
keys in FR/EN/AR.

## 3. API contract implementation

* Request is built by `toWireRequest`: server-style normalisation (trim, control characters
  removed, whitespace collapsed), `context: null` when empty, `schemaVersion: "1.0"`.
* The real transport calls `POST {API_BASE_URL}/ai/brainstorming/generate` with the session bearer
  token, overrides the shared 5 s timeout with the contract's **35 s**, and maps
  `400/401/403/422/429/502/503/504/500` plus `{ error, code, details, retryAfter }` onto
  `AiGenerationError.kind`. `Retry-After` is clamped to 1–3600 s.
* The untrusted 200 body is shape-checked (`isAiBrainstormingResponse`) before rendering, and an
  unexpected `schemaVersion` is reported as an unsupported version rather than rendered.
* Request bodies over 8 KiB are refused client-side.
* No provider credential, model name or prompt text is logged or rendered.

## 4. Mock details (Sprint 1B only)

`mockResponse.ts` returns the exact v1.0 shape: `schemaVersion`, `language`, `detailLevel`,
`truncated: false`, and a proposal whose section count follows the detail level
(concise 3, balanced 4, detailed 5) and whose note counts stay under the §4.4 budgets. Content is
written in the requested language (FR/EN/AR) and quotes the user's topic. Ids follow
`^[a-z0-9][a-z0-9-]{0,31}$` (`s1`, `s1-n1`). The mock resolves after ~800 ms and rejects with
`cancelled` when aborted, so the loading and cancel states are exercisable without a backend.

It is clearly marked: file header, `mockResponse.ts` name, a *Simulated response* badge in the
preview, and `VITE_AI_BRAINSTORMING_MOCK=false` to bypass it entirely. No second contract exists;
tests use the same builder as the mock.

## 5. Preview editor

Editable: board title, objective, section titles, note content, note type. Structural actions:
add/remove note (1–10 per section, ≤25 total), add/remove section (1–5), move section up/down.
Live counts and an advisory validation panel show contract violations. The draft is component
state; editing issues no request and there is deliberately no save/apply button until Sprint 2.
The provider notice is acknowledged once per browser session (`sessionStorage`) and the checkbox
gates submission.

## 6. Localisation and RTL

Every label, hint, counter, error and state is a translated `ai.*` dictionary key in FR, EN and AR
(`Record<TranslationKey, string>`, so a missing translation fails the typecheck). Arabic content
from the mock renders as plain React text nodes; nothing is auto-translated. New components use
logical utilities (`text-start`, `ms-`, `me-`, `ps-`, `pe-`, `end-*`) and the Arabic locale sets
`document.documentElement.dir = "rtl"` through the existing provider.

## 7. Feature flag

`VITE_AI_BRAINSTORMING_ENABLED` (default `true`) is read at render time. When `false`, the header
button, both cards and the dialog are absent; existing dashboard functionality is untouched.
`VITE_AI_BRAINSTORMING_MOCK` (default `true`) selects the mocked vs real transport. No feature
management platform was introduced.

## 8. Tests and results

`frontend/src/tests/AiBrainstorming.test.tsx` — 19 tests:

* dialog rendering, limits and defaults; character-limit counters and max lengths
* required-field validation; provider-notice gate
* language and detail-level selection reaching the request
* generating state, duplicate-request prevention, cancel (abort signal asserted)
* successful mock response → preview, no `fetch`, no apply action
* translated provider/network/timeout/unavailable/workspace errors and the 429 retry delay
* retry reusing the identical request
* preview editing of title/objective/section/note, no network calls
* adding/removing notes; adding/reordering/removing sections; section cap
* note-type review; preview isolated from the board editor
* Arabic labels, Arabic generated content, `dir="rtl"`
* responsive dialog/grid class assertions
* feature flag off hides every entry point; on shows both

Commands run on 2026-11-18 (frontend directory):

```
npx tsc -b                                  → 0
npx eslint .                                → 0
npx vitest run                              → 12 files, 60 tests passed
npx vite build                              → built in 10.81 s
```

No automated accessibility (axe/Lighthouse) or real-browser check exists in this repository, so
they were not run; responsive and RTL coverage is jsdom-level only.

## 9. Known limitations

* Applying a proposal to a board (and the non-admin explanation) is Sprint 2; the preview ends at
  *Close without saving* / *Edit the request*.
* The backend endpoint must exist and be enabled (`AI_ENABLED=true`) before
  `VITE_AI_BRAINSTORMING_MOCK=false` can succeed.
* Rate-limit copy depends on the server's `retryAfter`; the fallback wording is used otherwise.
* Provider notice consent is per browser session and is a reminder, not a security control.
* Generated section titles remain preview-only, matching contract §7.3 (notes are the only
  persisted element type).
