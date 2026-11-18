# ADR-001 — AI brainstorming is an isolated, approved, single-provider feature

- **Status:** Accepted as the Sprint 0 contract decision; implementation pending Sprint 1A/1B
- **Date:** 2026-11-18
- **Deciders:** Mosaiq maintainers (architecture contract, Sprint 0)
- **Related:** [`AI_BRAINSTORMING_CONTRACT.md`](../AI_BRAINSTORMING_CONTRACT.md), `docs/technical-audit.md` §4, `AGENTS.md`
- **Verified against:** `origin/main` at `01aed5e72f6799c5364bc0f1cf90096b15e7049c`

## Context

Mosaiq is a real-time collaborative whiteboard with a Symfony 7.4 JSON API and a React 19 client.
Boards, canvas elements, comments and timeline entries are persisted by Doctrine; canvas creation is
idempotent per (board, author, client mutation id) and authorised by a board voter; collaboration is
authenticated SSE that only invalidates the canvas, with last-write-wins writes.

The first AI feature is **generating a structured brainstorming proposal from a natural-language
prompt**. Two teams implement it in parallel (backend generation, frontend experience), so the
boundary between "generate" and "persist" has to be decided once, before either side writes code.

The forces in play:

- Board data is private and multi-tenant; prompts are user text.
- Applying a large proposal touches many rows and the timeline, where a bad write is visible to
  every collaborator and costly to undo (permanent board deletion is still planned, not shipped).
- Any provider is an external dependency with its own limits, outages, pricing and output quality.
- Generated content is untrusted text produced by a model, not by a Mosaiq client.
- The repository's own rules forbid speculative infrastructure and unapproved dependencies.

## Decision

### 1. AI generation is isolated from board persistence

Generation is a stateless request/response endpoint that returns a proposal document. It has no
board scope and no write path: the pipeline (`App\Ai\Brainstorming`) does not inject the entity
manager, any repository, `BoardService` or `TimelineService`.

Persistence happens afterwards, through the existing endpoints, driven by the client after user
approval: `POST /api/boards` and one `POST /api/boards/{id}/elements` per note.

**Consequences.** The AI path cannot corrupt a board, bypass a voter, or write a partial board
server-side. It also cannot "helpfully" save a draft; a discarded proposal leaves no trace. Testing
is simple: a successful generation must leave every table's row count unchanged. The cost is two
round trips (generate, then apply) and no server-side generation history, which v1 accepts.

### 2. Generated content requires user preview and approval

A proposal is data, never an applied action. The frontend shows the full proposal — title,
objective, sections, notes and their semantic types — and only an explicit "create the board" /
"add to this board" action persists anything.

**Consequences.** Users can reject, and cannot be surprised by notes appearing on a shared canvas
where collaborators would see them as if a human wrote them. It also keeps the write path identical
to manual editing, so every existing permission check applies unchanged. The cost is one extra
screen and a second request per note; both are already part of the existing note flow.

### 3. The first release uses exactly one provider

v1 ships one `BrainstormingProviderInterface` implementation, configured by environment
(`AI_PROVIDER`, `AI_BASE_URL`, `AI_MODEL`, `AI_PROVIDER_API_KEY`). The prompt, limits and validation
are provider-independent.

**Consequences.** One integration, one failure mode, one set of credentials, one quota to observe.
Quality comparisons and fallbacks are deferred until there is evidence about the first provider's
output. The risk is provider lock-in at the *contract* level, which is mitigated by decision 4.

### 4. The provider interface stays replaceable

The interface takes a validated `BrainstormingRequest` and returns the **raw JSON document**. Schema
validation, normalisation and error mapping live outside the provider, in
`BrainstormingResponseValidator` / `BrainstormingNormalizer`, and are shared by every implementation.
A provider never constructs Mosaiq DTOs and never decides what a valid proposal is.

**Consequences.** A second provider (or a self-hosted model) is a new class plus configuration, not a
rewrite of validation or the controller. Tests use a fake provider, so the suite never depends on a
network or a model's mood. The interface deliberately does **not** include streaming, tools,
embeddings or multi-provider routing: those are speculative for one provider and would freeze an API
before there is a second consumer.

### 5. Generated notes use existing Mosaiq persistence and collaboration

An approved note is an ordinary `CanvasElement` of type `note`, created through the existing
idempotent endpoint with a client-minted `mutationId`, the five allowed color tokens, and additive
`metadata.aiNoteType`. It writes its normal timeline entry and reaches other clients through the
existing SSE invalidation and REST poll.

**Consequences.** Time-travel replay, presence, authorization, undo-by-restore and the note save/retry
state work without AI-specific code. There is no "generated" flag, no parallel table, no second sync
path, and no bulk endpoint that would bypass per-element validation. The cost is N creates for N
notes and, for a new board, the workspace-administrator requirement that `POST /api/boards` already
has — a product constraint documented in the contract rather than worked around.

### 6. The first release supports brainstorming only

The endpoint is `POST /api/ai/brainstorming/generate` with a versioned schema (`1.0`). It does not
summarise existing boards, propose comments, generate images, or edit an existing canvas.

**Consequences.** The prompt, the limits and the preview are shaped for one task, so they can be
validated against real use before generalising. A generic "AI action" abstraction with one caller
would be indirection without evidence. Extending to another task is a new endpoint with its own
contract and ADR, reusing the provider interface and validation helpers where they genuinely fit.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Let the backend generate **and** persist a board in one call | A mediocre or malformed proposal becomes a visible shared board; it bypasses the preview decision; error handling mixes provider and storage failures; rollback of a partially written board is a new deletion problem. |
| Store proposals server-side as drafts | New table, retention and privacy obligations, and a second source of truth for data the user may discard. |
| One provider interface with streaming, tools and fallbacks | Speculative for a single provider; freezes an API before a second consumer exists. |
| Client calls the provider directly | Exposes the API key in the browser and lets untrusted code choose the model and spend the quota. |
| A new bulk element endpoint for "apply proposal" | Skips per-element idempotency and validation, and creates a second write path beside `createCanvasElement`. |
| Persist relationships/connectors in v1 | Connectors have no validated endpoint contract today and the editor does not render server-provided connectors (contract §4.5). |
| Build a generic AI platform layer first | No second use case; `AGENTS.md` forbids unapproved architecture and dependencies. |

## Consequences summary

**Positive.** No new persistence mechanism; the write path is the tested one; permissions unchanged;
provider replaceable behind one interface; untrusted output is validated in one place; the whole
feature is testable without a network; the contract is versioned for two parallel teams.

**Negative / accepted costs.** Two round trips; N element creates per proposal; no server-side
generation history or audit; new-board apply is limited to workspace administrators; one provider's
quality is the quality of v1; an additional dependency (`symfony/http-client`) is required and still
needs owner approval.

**Follow-ups (outside this ADR).** Provider selection and quota, generation history/audit,
relationships between ideas, apply-into-existing-board UX for non-administrators, and rate-limit
tuning are tracked as open decisions in the contract (§13).
