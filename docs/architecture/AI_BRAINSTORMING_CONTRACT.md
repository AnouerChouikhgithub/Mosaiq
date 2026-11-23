# AI brainstorming contract — v1.0

**Status:** implemented in Sprint 1A/1B; §7.2, §7.3 and §7.6 revised by the Sprint 2 integration
**Contract version:** `1.0` — carried on every response as `schemaVersion`
**Audience:** Sprint 1A (backend AI generation) and Sprint 1B (frontend AI experience)
**Owner:** the two workstreams jointly; changes follow §12
**Verified against:** `origin/main` at `01aed5e72f6799c5364bc0f1cf90096b15e7049c` (2026-11-18)
**Supersedes:** nothing. This is the first version.

This document is the single shared contract for Mosaiq's first AI feature: **generating a
structured brainstorming proposal from a natural-language prompt**. It defines the input, the
proposal, the limits, the HTTP surface, the provider-independent architecture and the rules for
turning an approved proposal into ordinary Mosaiq board elements.

Both teams implement against this document. Where this document and an implementation disagree,
this document wins until §12 changes it.

Normative language: **MUST**, **MUST NOT**, **SHOULD** and **MAY** are used in their RFC 2119 sense.

### Revision log

| Date | Sprint | Change |
| --- | --- | --- |
| 2026-11-18 | 1A/1B | First implementation of v1.0: endpoint, provider pipeline, preview. |
| 2026-11-18 | 2 | §7.3 layout replaced (the original formula placed notes outside the 1400×880 canvas), §7.6 partial failure replaced by a compensating rollback, §7.2 adds section metadata. Wire shape, request fields, limits and `schemaVersion` are unchanged, so this is **not** a schema bump. |

---

## 1. Scope and non-goals

**In scope (the two downstream sprints, not this sprint):**

- One authenticated endpoint that turns a prompt into a structured proposal.
- A provider-independent backend pipeline with strict schema validation.
- A frontend preview and an explicit user approval step before anything is persisted.
- Conversion of an approved proposal into normal board elements through the existing API.

**Out of scope for v1:**

- Any provider integration in this sprint (no HTTP calls, no keys).
- Any frontend screen in this sprint.
- Streaming, tool/function calling, image generation, board summarisation or board-aware prompts.
- Persisting the proposal server-side, generation history, or a template library.
- A bulk canvas-create endpoint, CRDT/merge changes, or a second persistence path.
- Automatic board creation: generation MUST NOT create a board, elements, timeline entries or
  comments.

---

## 2. Verified architecture facts this contract depends on

These were read from the code at `01aed5e` and are the constraints the contract is built on.

| Concern | What the code actually does | Consequence for AI generation |
| --- | --- | --- |
| Board creation | `POST /api/boards` (`BoardApiController::create`) requires `WORKSPACE_VIEW` **and** `WORKSPACE_ADMINISTER`; title is a trimmed non-empty string; `noteColors` must be a non-empty subset of `Board::NOTE_COLORS` (`amber`, `rose`, `sky`, `mint`, `lilac`); the board, its owner membership and its opening timeline entry commit in one transaction | Applying a proposal to a **new** board is only possible for workspace administrators. Non-admins must apply into an existing board they can edit. |
| Canvas element model | `CanvasElement` has `type ∈ {note, shape, connector, frame}`, floats `x, y, width, height, rotation`, nullable `content`, nullable `color` restricted to `Board::NOTE_COLORS`, nullable JSON-object `metadata`, author, `createdAt` | A note is the only element type this feature needs. `color` must be one of the five tokens. Extra semantic data can travel in `metadata`. |
| Coordinates | Floats, origin top-left, width/height must be ≥ 0, `rotation` default `0.0`. Coordinates are **not** normalised or clamped by the server | The client chooses placement; the contract fixes a deterministic layout (§7.3) so preview and board agree. |
| Element creation | `POST /api/boards/{id}/elements` requires `BOARD_EDIT`; optional `mutationId` (canonical UUID); unique index `(board_id, author_id, mutation_id)`; pessimistic board-row lock serialises concurrent retries; a retry with the same key and payload returns `200` with the stored element; a reused key with different content returns `409` | Each generated note becomes one ordinary idempotent create. A retry MUST reuse the key minted for that note. |
| Authorization | `BoardVoter` checks workspace membership, then `VIEW`/`COMMENT`/`EDIT`/`SHARE`/`MANAGE`; archived boards are view-only; trashed boards deny content access; `ApiTokenAuthenticator` rejects missing/expired/revoked tokens | Applying a proposal MUST pass the same checks as manual editing. The AI endpoint itself is not board-scoped and MUST NOT grant any board rights. |
| Timeline | `TimelineService::record()` writes one entry per canvas mutation inside the same transaction, with a full-element snapshot | Applying 12 notes produces 12 normal timeline entries, so replay works unchanged. No AI-specific timeline kind exists. |
| Collaboration | Authenticated SSE (`GET /api/boards/{id}/stream`) emits presence plus a `canvas` **invalidation** frame (`revision`, `elementCount`); clients reconnect every 500 ms and also poll REST every 3 s; canvas writes are last-write-wins | New notes appear through the existing invalidation/poll path. No broadcast code is added for AI. |
| Frontend board state | `BoardEditorScreen` holds notes in component state, maps API elements with `toStickyNote`, and persists a note through `createCanvasElement` with a `mutationId` minted by `frontend/src/lib/mutationId.ts`; failures keep the note with `saveState: 'failed'` and a retry that reuses the key | The AI apply path MUST reuse this exact flow rather than introduce a second one. |
| Rendered element types | `BoardEditorScreen` maps only `type === 'note'` into the canvas; shapes, connectors and frames coming from the API are not rendered from server data | v1 persists **notes only**. Section headings and connectors are preview-only. |
| Client request timeout | `frontend/src/api/client.ts` bounds every request at `5_000 ms` | The generation call MUST override this to ≥ 35 s, otherwise a healthy generation is aborted client-side. |
| Error shape | Controllers return `{ "error": "<message>" }` with ad-hoc status mapping; there is no central error listener and no `code` field convention | This endpoint adds `code` and optional `details` but keeps `error` first so the existing client renderer keeps working. |
| Dependencies | `symfony/http-client`, `symfony/validator`, API Platform and Messenger are **not installed** | The pipeline is hand-validated in the repository's current style. The HTTP client is the one dependency Sprint 1A must get approved (§10.6, §11). |

---

## 3. Input contract

`POST /api/ai/brainstorming/generate` accepts exactly these top-level fields. Unknown top-level
fields MUST be rejected with `422` rather than ignored, so a client typo cannot silently degrade the
prompt.

| Field | Type | Required | Limits (after trim) | Notes |
| --- | --- | --- | --- | --- |
| `topic` | string | yes | 3–280 characters | The subject to brainstorm. |
| `objective` | string | yes | 5–500 characters | What the user wants out of the session. |
| `context` | string | no | 0–2000 characters; `null` allowed | Free-form context the user chooses to share. Never filled from board data. |
| `language` | string enum | yes | `en` \| `fr` \| `ar` | Language of every generated string. No implicit default: the client sends its current locale. |
| `detailLevel` | string enum | yes | `concise` \| `balanced` \| `detailed` | Maps to the note budget in §4.4. |
| `schemaVersion` | string | no | `"1.0"` | Omitted means `"1.0"`. Any other value returns `422` `ai.unsupported_schema_version`. |

Normalisation applied by the server before validation:

- Trim leading/trailing whitespace, collapse runs of whitespace to one space.
- Reject control characters other than the line feed (`\n`); reject a byte sequence that is not
  valid UTF-8.
- Count limits in Unicode characters, not bytes.

### 3.1 Why there is no preferences object in v1

A `preferences` field (palette, section count, tone) was considered and deliberately excluded:

- the product has no persisted user-preference model to derive defaults from;
- palette choice is already fixed by `Board::NOTE_COLORS` and the note-type mapping (§7.4), and
  letting a prompt choose colors adds validation surface without a user benefit;
- an unused optional object is exactly the speculative abstraction this sprint must avoid.

`noteCount`, `sectionCount` and provider tuning are therefore **not** user-controlled in v1. Adding
any preference is a contract change (§12) and needs a UI that reads it.

---

## 4. Output contract

A successful call returns **one proposal**. A proposal is data for preview; it is not a board and
carries no ids from persistent storage.

```json
{
  "schemaVersion": "1.0",
  "language": "fr",
  "detailLevel": "balanced",
  "truncated": false,
  "proposal": {
    "title": "Lancer une flotte de vélos partagés à Tunis",
    "objective": "Identifier les premiers quartiers, le budget et les risques",
    "sections": [
      {
        "id": "s1",
        "title": "Publics prioritaires",
        "category": "audience",
        "notes": [
          { "id": "s1-n1", "type": "idea", "content": "Étudiants du campus de la Manouba" },
          { "id": "s1-n2", "type": "question", "content": "Combien d'abonnés mensuels faut-il pour rentabiliser ?" }
        ]
      }
    ]
  }
}
```

### 4.1 Board title and objective

- `title`: 3–120 characters, a human board name, no trailing punctuation, no markdown.
- `objective`: 5–300 characters, one sentence restating the agreed goal.

### 4.2 Sections

| Field | Rule |
| --- | --- |
| `id` | Matches `^[a-z0-9][a-z0-9-]{0,31}$`, unique within the proposal. Stable for the response only. |
| `title` | 3–80 characters, no numbering prefix (`1.`, `-`), no markdown. |
| `category` | One of `goals`, `audience`, `ideas`, `risks`, `actions`, `questions`. |
| `notes` | 1–10 notes; the proposal has 3–25 notes in total. |

### 4.3 Notes

| Field | Rule |
| --- | --- |
| `id` | Matches `^[a-z0-9][a-z0-9-]{0,31}$`, unique within its section. |
| `type` | One of `idea`, `question`, `challenge`, `opportunity`, `action`. |
| `content` | 3–280 characters, single factual idea per note, no markdown, no bullet prefixes, no line breaks. |

Note types are **semantic** and independent of the canvas element type: every note becomes a
canvas element of type `note`. Mapping:

| `type` | Intent |
| --- | --- |
| `idea` | A concrete option or concept. |
| `question` | An open question to resolve. |
| `challenge` | A risk, blocker or objection. |
| `opportunity` | A favourable opening. |
| `action` | A next step someone can own. |

### 4.4 Detail levels

| `detailLevel` | Notes per section (prompt guidance) | Total notes (hard cap) |
| --- | --- | --- |
| `concise` | 1–3 | 9 |
| `balanced` | 2–5 | 15 |
| `detailed` | 3–7 | 25 |

The total caps in §5 are authoritative; the per-level numbers shape the prompt and the preview, and
the server enforces the hard caps even when a model ignores the guidance.

### 4.5 Relationships between ideas: not in v1

The canvas model has a `connector` element type, but Mosaiq does not support it safely today:

- `BoardCanvas` resolves connectors by matching `fromId`/`toId` against **notes it already holds**,
  and the only code that writes those keys is the synthetic fixture loader (which had a mapping
  defect, see `docs/technical-audit.md` B-02);
- `POST /api/boards/{id}/elements` accepts connector metadata as an unvalidated JSON object, so a
  connector's endpoints are not a checked reference — nothing prevents a dangling or cross-board id;
- generated notes have no persistent id until they are created, so a proposal-level relationship
  cannot be expressed without inventing a client-side id resolution step;
- the editor does not render server-provided connectors at all, so a persisted relationship would be
  invisible today.

A proposal MUST therefore contain **no relationships**. `BrainstormingResponseValidator` rejects a
proposal that carries a `relationships` key. Reintroducing relationships requires a validated
connector contract (endpoint ids checked against the same board) and editor rendering; that is a
future contract version, not a workaround in this one.

---

## 5. Validation limits (authoritative)

Both teams use these numbers. Each side exposes them as named constants and neither duplicates a
literal:

- backend: `App\Ai\Brainstorming\BrainstormingLimits` (constants used by validation and the prompt);
- frontend: `AI_BRAINSTORMING_LIMITS` next to `client.ts` (used by form validation and preview).

| Limit | Value |
| --- | --- |
| `topic` length | 3–280 characters |
| `objective` length | 5–500 characters |
| `context` length | 0–2000 characters |
| Supported languages | `en`, `fr`, `ar` |
| Supported detail levels | `concise`, `balanced`, `detailed` |
| Maximum sections | **5** |
| Minimum sections | 1 |
| Section title length | 3–80 characters |
| Maximum notes per section | 10 |
| Maximum total notes | **25** |
| Minimum total notes | 3 |
| Note content length | 3–280 characters |
| Proposal board title | 3–120 characters |
| Proposal objective | 5–300 characters |
| Allowed note types | `idea`, `question`, `challenge`, `opportunity`, `action` |
| Allowed section categories | `goals`, `audience`, `ideas`, `risks`, `actions`, `questions` |
| Allowed note colors | `amber`, `rose`, `sky`, `mint`, `lilac` |
| Relationships | none (0) |
| Maximum request body | 8 KiB (8192 bytes) |
| Maximum proposal JSON | 64 KiB (65536 bytes) |
| Supported schema version | `1.0` |
| Maximum provider timeout | 20 s per attempt (server total budget 25 s) |

Enforcement rules:

- **Request limits** are enforced before any provider call; an over-limit request never costs a
  provider token.
- **Output limits** are enforced after decoding: an over-limit proposal is normalised down to the
  caps and returned with `truncated: true` (§10.5). Only structural damage (undecodable JSON,
  missing proposal, zero usable sections) is a provider failure.
- A limit change is a contract change (§12) even when the shape is unchanged.

---

## 6. HTTP API contract

### 6.1 Endpoint

```
POST /api/ai/brainstorming/generate
Content-Type: application/json
Authorization: Bearer <token>
```

The endpoint **generates a proposal only**. It MUST NOT create a board, persist canvas elements,
write timeline entries, send invitations or store the proposal.

### 6.2 Authentication and authorization

| Condition | Result |
| --- | --- |
| Missing, malformed, expired or revoked bearer token | `401` from the existing `ApiTokenAuthenticator` entry point (unchanged auth contract) |
| Authenticated but the account is not an active workspace member (`hasActiveWorkspaceMembership()` is false) | `403` `ai.workspace_inactive` |
| Authenticated active member | `200` on success |

The endpoint is **not** board-scoped, so no `BoardVoter` attribute applies and no `boardId` is
accepted. Workspace administrator rights are **not** required to generate; they are required later
by `POST /api/boards` (§7.1).

### 6.3 Successful response — `200 OK`

`200` is deliberate: nothing is created. Headers: `Content-Type: application/json`,
`Cache-Control: no-store` (the response is derived from private user input).
Body shape: §4.

### 6.4 Validation error — `422 Unprocessable Content`

```json
{
  "error": "\"topic\" must be between 3 and 280 characters.",
  "code": "ai.invalid_request",
  "details": [
    { "field": "topic", "message": "Must be between 3 and 280 characters." }
  ]
}
```

- `error` is the human message and remains the field the existing client renders.
- `code` is machine-stable: `ai.invalid_request`, `ai.unsupported_schema_version`.
- `details` is present for field problems, sorted by field name. Multiple violations are reported
  in one response; validation never stops at the first field.
- A malformed JSON body returns `400` with `code: ai.malformed_json`, matching the existing
  `Malformed JSON payload.` convention.

### 6.5 Provider failure — `502 Bad Gateway`

Triggered by: upstream non-2xx after retries, undecodable provider output, or a structurally unusable
proposal.

```json
{
  "error": "The AI provider could not produce a usable proposal.",
  "code": "ai.provider_failed"
}
```

Provider error bodies, stack traces, model names and prompt text MUST NOT appear in the response.
The log carries an event name, the correlation id, HTTP status and latency only (§8.4).

### 6.6 Provider timeout — `504 Gateway Timeout`

```json
{
  "error": "The AI provider took too long to answer.",
  "code": "ai.provider_timeout"
}
```

### 6.7 Provider not configured — `503 Service Unavailable`

Returned when generation is disabled or the API key is absent, so a misconfigured deployment fails
closed instead of calling an unauthenticated endpoint.

```json
{
  "error": "AI generation is not configured.",
  "code": "ai.provider_unavailable"
}
```

### 6.8 Rate limited — `429 Too Many Requests`

```
Retry-After: 420
```

```json
{
  "error": "Too many AI generation requests. Try again later.",
  "code": "ai.rate_limited",
  "retryAfter": 420
}
```

Limits (initial): **5 requests per 10 minutes and 20 per hour per authenticated user**, plus a
**global cap of 60 per minute per deployment** to protect the provider quota. Counters are stored in
the database (the `auth_attempts` pattern) so they hold across PHP processes; they are not a
distributed limiter and do not coordinate across deployments. Counter rows contain the user id, a
window key and a count — never prompt text.

Rate-limit checks run before the provider call and consume a slot only when a call is attempted.
A `429` from the provider itself is surfaced as `429 ai.rate_limited` with the provider's
`Retry-After` when it is sane (1–3600 s), otherwise 60 s.

### 6.9 Timeout behaviour

| Layer | Budget | Behaviour |
| --- | --- | --- |
| Frontend request | 35 s (overrides the global 5 s) | Aborts with a translated "generation is taking too long" message and offers a retry. Retrying mints **no** board or element data. |
| Server total | 25 s | Returns `504 ai.provider_timeout` without retrying beyond the transport retry below. |
| Upstream attempt | 20 s | Symfony HTTP client timeout; one retry only for transport errors and `5xx`, with 500 ms backoff. |
| Not retried | `4xx` from the provider (including `401`/`429`), invalid output | Mapped immediately (`502`, `429`, `502`). |

A client abort does not cancel the provider request in v1 (PHP-FPM cannot reliably abort upstream);
the server still finishes its own budget and discards the result. No proposal is stored, so nothing
leaks from an abandoned call.

### 6.10 Request/response examples

Concise English request:

```json
{
  "topic": "Onboarding for a design tool",
  "objective": "Find the first three activation moments",
  "context": null,
  "language": "en",
  "detailLevel": "concise"
}
```

Trimmed `200` body (two of several sections shown):

```json
{
  "schemaVersion": "1.0",
  "language": "en",
  "detailLevel": "concise",
  "truncated": false,
  "proposal": {
    "title": "Design tool onboarding",
    "objective": "Find the first three activation moments",
    "sections": [
      {
        "id": "s1",
        "title": "Activation moments",
        "category": "goals",
        "notes": [
          { "id": "s1-n1", "type": "idea", "content": "First import of an existing file" },
          { "id": "s1-n2", "type": "idea", "content": "First shared link opened by a teammate" }
        ]
      },
      {
        "id": "s2",
        "title": "Open questions",
        "category": "questions",
        "notes": [
          { "id": "s2-n1", "type": "question", "content": "Which moment predicts week-two retention?" }
        ]
      }
    ]
  }
}
```

Arabic request (RTL is a rendering concern, not a wire concern):

```json
{
  "topic": "تنظيم ورشة عمل لفريق التصميم",
  "objective": "تحديد المحاور والأدوار قبل الورشة",
  "language": "ar",
  "detailLevel": "balanced"
}
```

---

## 7. From an approved proposal to board elements

### 7.1 Board

`POST /api/boards` with `{ "title": <proposal.title>, "workspaceId": <current workspace> }`.

- Requires `WORKSPACE_ADMINISTER` (verified in §2). For a member without that right the apply action
  MUST be unavailable with a translated explanation; the proposal stays viewable.
- The board, its owner membership and its opening timeline entry commit atomically, unchanged.
- The client SHOULD send all five note colors so any note-type mapping stays valid:
  `["amber","rose","sky","mint","lilac"]`.

### 7.2 Notes

One ordinary create per note:

```
POST /api/boards/{boardId}/elements
{
  "mutationId": "<uuid v4 minted once for this note>",
  "type": "note",
  "x": <layout>,
  "y": <layout>,
  "width": 220,
  "height": 140,
  "rotation": 0,
  "content": "<note.content>",
  "color": "<mapped color>",
  "metadata": {
    "stroke": "none",
    "textSize": "m",
    "align": "left",
    "aiNoteType": "idea",
    "aiSectionId": "s1",
    "aiSectionTitle": "Publics prioritaires"
  }
}
```

Rules:

- `mutationId` is generated client-side per note (`frontend/src/lib/mutationId.ts`) and **reused on
  every retry** of that note. It is scoped per board and author by the server.
- A `200` response means the note was already stored: treat it as success and replace the local id
  with the returned element, exactly like the manual note flow.
- A `409` means the key was reused with a different payload or the insert lost a race; the client
  retries with the **same** key once, then shows the failure state.
- `color` follows the note type (§7.4). `metadata` is additive: the API stores it as-is, the
  serializer returns it, and the frontend ignores keys it does not know.
- Notes keep their semantic type only in `metadata.aiNoteType`; it MUST NOT change what the canvas
  renders. `aiSectionId`/`aiSectionTitle` carry the section the note came from, because v1 has no
  text element and section headings are not persisted; they are display-only metadata for later
  clients and never affect layout or authorization.

### 7.3 Deterministic layout

**Revised in Sprint 2.** The original formula (one column per section, `x = 80 + s * 268`,
`y = 160 + n * 164`) placed the tenth note of a section at `y ≈ 1636` on the real 1400×880 canvas,
so it could not satisfy §5's leading requirement that nothing is created outside the usable area.
The replacement is a pure function of the note counts, implemented once in
`frontend/src/features/ai/layout.ts` and asserted by both the frontend layout tests and the backend
import test.

Safe area (inset so shadows and the editor frame are not clipped): `x 80‥1320`, `y 160‥840`
(1240 × 680), top-left origin, integer CSS pixels.

```
NOTE_WIDTH_MAX  = 220      NOTE_HEIGHT_MAX = 140
NOTE_MIN_WIDTH  = 140      NOTE_MIN_HEIGHT = 72
COLUMN_GAP      = 20       ROW_GAP         = 16      SECTION_GAP = 32

columns      = clamp(ceil(sqrt(totalNotes)), 1, 5)
rows         = sum over sections of ceil(sectionNotes / columns)
noteHeight   = min(140, floor((680 - bandGaps - sectionGaps) / rows))
noteWidth    = min(220, floor((1240 - (columns-1) * 20) / columns))
x(note)      = blockLeft + columnInBand * (noteWidth + 20)
y(note)      = bandTop   + rowInBand    * (noteHeight + 16)
```

- Each section starts a new band, so sections are separated vertically by `SECTION_GAP`; notes keep
  their order inside a section and wrap after `columns`.
- The whole block is centred in the safe area. Notes are never at negative coordinates and never
  extend past `x = 1320` / `y = 840`, for every proposal the limits allow (≤ 5 sections, ≤ 25 notes).
- The worst allowed shape (25 notes skewed across 5 sections) still yields notes of at least
  140 × 72, which the tests assert; normal proposals keep the 220 × 140 note.
- Section **titles are not persisted** as elements in v1: there is no text element type (`TYPES` is
  `note, shape, connector, frame`) and the editor does not render server-provided frames. They travel
  in note metadata (§7.2) and the board shows grouped note bands.
- Rotation stays `0`; the apply path never rotates generated notes.
- The editor's default zoom (0.8) and top-left origin show the whole 1400×880 board, so an imported
  proposal is visible immediately without panning.

### 7.4 Note type → color

| Note type | Color token |
| --- | --- |
| `idea` | `amber` |
| `question` | `sky` |
| `challenge` | `rose` |
| `opportunity` | `mint` |
| `action` | `lilac` |

The mapping lives beside the limits on both sides and MUST NOT be extended beyond
`Board::NOTE_COLORS`.

### 7.5 Timeline and collaboration

- Each created note writes its normal timeline entry ("… added a note") in the same transaction, so
  time-travel replay needs no AI-specific handling.
- There is no "AI generated this board" event in v1; the board creation entry plus per-note entries
  are the record.
- Other clients see the notes through the existing SSE invalidation frame and the 3 s poll. No new
  broadcast, no new stream event, no socket.

### 7.6 Failure and rollback

**Revised in Sprint 2.** Sprint 1B left partial failure undefined beyond "keep the failed note". The
approved integration behaviour is all-or-nothing **from the user's point of view**, built only from
existing endpoints:

- The board is created first (`POST /api/boards`, one server transaction). If it fails, nothing is
  persisted and the proposal stays editable for another attempt.
- Each note is one idempotent create, retried **once with the same `mutationId`** for transport
  errors, `5xx` and `409`. The server replays a stored element, so a retry cannot duplicate a note.
- A `4xx` (permission change, invalid payload, expired session) is final and not retried.
- If any note still fails, the client **compensates**: it deletes the notes this import created
  (newest first) and trashes the board. The result is an empty trashed board, never a partial active
  board that collaborators would see. The UI then reports the failure and keeps the proposal.
- The client MUST NOT show a success message before every note has been persisted, and MUST NOT
  navigate to the board before that point.
- Notes and the board still have no cross-request transaction; there is no bulk endpoint and no
  permanent board deletion (PLANNED). A compensating rollback is therefore best-effort: if the
  deletes or the trash call itself fail, the board may remain with some notes, but it is reported as
  a failure rather than as a success.
- The preview MUST warn that approval writes immediately to the board; the user approves the whole
  proposal, not individual requests.

### 7.7 What MUST NOT be introduced

- A new persistence mechanism, table, DTO-backed endpoint for elements, or "generated" flag on
  boards/elements.
- Direct database access from the AI pipeline.
- A second client write path beside `createCanvasElement`.

---

## 8. Security and privacy

### 8.1 Keys

- Provider API keys live **only** on the backend, in `backend/.env.local` (ignored) or the
  deployment's secret store. They MUST NOT appear in the frontend bundle, a `VITE_*` variable, the
  repository, logs, error responses or the proposal payload.
- The browser never calls the provider. It calls Mosaiq, which calls the provider.
- Configuration is environment-driven, matching the existing auth-throttle pattern:

| Variable | Purpose | Default |
| --- | --- | --- |
| `AI_ENABLED` | Master switch; `false` returns `503` | `false` |
| `AI_PROVIDER` | Provider slug used for selection | `openai-compatible` |
| `AI_BASE_URL` | Provider endpoint base | empty |
| `AI_MODEL` | Model identifier | empty |
| `AI_PROVIDER_API_KEY` | Secret; empty means `503` | empty |
| `AI_TIMEOUT_SECONDS` | Per-attempt timeout, capped at 20 | `20` |
| `AI_RATE_LIMIT_PER_10_MINUTES` / `AI_RATE_LIMIT_PER_HOUR` | Per-user limits | `5` / `20` |

`.env` keeps documented defaults only (no secret). `.env.test` MUST set `AI_ENABLED=false` so the
suite never reaches the network.

### 8.2 What leaves Mosaiq

- The user's `topic`, `objective`, `context`, `language` and `detailLevel` are sent to the external
  provider.
- Nothing else: no board ids, titles, elements, comments, members, emails, workspace names, presence
  or history. The AI endpoint has no board access by construction (§10.2).
- The user MUST be told, before the first generation, that their text is processed by an external AI
  provider. Copy lives in `frontend/src/i18n/dictionary.ts` for FR, EN and AR; the notice names the
  provider and links the privacy note. Consent is stored per session (`sessionStorage`), and the
  notice is shown again in a new session.
- Personal data in a prompt is the user's choice; the UI SHOULD discourage pasting personal data.

### 8.3 Untrusted generated output

Model output is untrusted input and is treated exactly like a hostile HTTP body:

- JSON is decoded with `JSON_THROW_ON_ERROR`; the decoded value MUST be an object.
- Every string is trimmed, whitespace-collapsed, stripped of control characters except none (v1
  notes are single-line) and length-clamped.
- Unknown note types and categories are rejected or normalised (§10.5), never passed through.
- The proposal never becomes HTML: the preview renders every string through React text nodes, with
  no `dangerouslySetInnerHTML`, no markdown renderer and no link auto-detection.
- The proposal never reaches SQL, a shell, a filename or an authorization decision.
- No function calling or tool use in v1, so generated text cannot trigger an action.

### 8.4 Logging

- Logged: event name, correlation id, user id, duration, provider HTTP status, note/section counts,
  whether truncation happened, and the prompt **length**.
- Not logged: prompt text, context text, proposal text, provider response body, authorization
  headers, API key (not even a prefix).
- On failure log the provider HTTP status and a short error code only.
- No generation content is persisted server-side in v1, so there is no generation history to purge.

### 8.5 Persistence approval

- Nothing is written before an explicit user action on the preview: no draft board, no placeholder
  elements, no server-side draft.
- The apply action is explicit ("Create the board" / "Add to this board"), not a side effect of
  generation, and it is the only place that calls `POST /api/boards` or the element endpoint.
- Generation and application are separate requests, so a generated proposal can be discarded
  without leaving any trace.

---

## 9. Contract versioning

- `schemaVersion` is `1.0` for this release.
- Adding an optional response field is a **minor** bump (`1.1`); clients MUST ignore unknown
  response fields.
- A new required request field, a changed limit, a new enum value or a removed field is a **major**
  bump (`2.0`); the server accepts the previous version for one release and logs its use.
- Unknown `schemaVersion` values are rejected with `422 ai.unsupported_schema_version` rather than
  guessed.
- The contract document and both constant sets change in the same pull request.

---

## 10. Provider-independent architecture

Namespace: `App\Ai\Brainstorming` (`backend/src/Ai/Brainstorming/`). No new dependency beyond the
HTTP client in §10.6. No API Platform resource, no Messenger, no queue.

### 10.1 Components

| Class | Responsibility |
| --- | --- |
| `BrainstormingRequest` | Immutable, validated input DTO: `topic`, `objective`, `context`, `language`, `detailLevel`. |
| `BrainstormingProposal` | Immutable output DTO: `title`, `objective`, `sections`. |
| `BrainstormingSection` | `id`, `title`, `category`, `notes`. |
| `BrainstormingNote` | `id`, `type`, `content`. |
| `BrainstormingLimits` | The §5 constants plus `assertRequest()` / `assertProposal()` helpers. |
| `BrainstormingProviderInterface` | `name(): string` and `generate(BrainstormingRequest $request): string` returning the **raw JSON document** from the model. |
| `OpenAiCompatibleBrainstormingProvider` | The single v1 implementation: chat-completions request with a JSON response format, timeout and retry policy from §6.9. |
| `BrainstormingPromptBuilder` | Builds the system and user messages from a validated request and the limits. |
| `BrainstormingResponseValidator` | Decodes the raw document, enforces the schema and limits, and returns `BrainstormingProposal`. |
| `BrainstormingNormalizer` | Trims, clamps, dedupes and drops unusable items; reports `truncated`. |
| `BrainstormingGenerator` | Orchestrates prompt → provider → validate → normalise, and translates provider exceptions into the contract's errors. |
| `AiBrainstormingController` | HTTP boundary: authentication, body decoding, status mapping, response headers. |

`BrainstormingGenerator` is the only class the controller injects besides the request decoder.
Nothing in this namespace injects `EntityManagerInterface`, a repository, `BoardService` or
`TimelineService`.

### 10.2 Boundaries

```
HTTP controller  →  BrainstormingGenerator  →  PromptBuilder
                                           →  ProviderInterface  →  external provider
                                           →  ResponseValidator  →  Normalizer  →  Proposal
```

- The provider receives a `BrainstormingRequest` and returns text. It does **not** build DTOs, read
  the database or know about boards.
- The controller contains no prompt text, no validation rules and no provider details; it maps
  exceptions to `{error, code}`.
- Board persistence is a separate client-driven step (§7). The backend proposal path has no write
  access to `boards`, `canvas_elements` or `timeline_entries` — not even through a service.

### 10.3 Provider interface

```php
interface BrainstormingProviderInterface
{
    /** Stable slug used in logs and configuration. */
    public function name(): string;

    /**
     * @throws AiProviderUnavailableException when the provider is not configured
     * @throws AiProviderTimeoutException     when the attempt budget is exhausted
     * @throws AiProviderFailureException     when the provider answers with an error
     */
    public function generate(BrainstormingRequest $request): string;
}
```

Returning the raw document keeps one owner of the schema: a second provider cannot accidentally
return a subtly different DTO.

### 10.4 Prompt construction

One system message, one user message, no conversation history, no board data.

System message MUST contain:

- the role ("You produce structured brainstorming proposals for a collaborative whiteboard");
- the exact JSON schema and the allowed enums;
- the limits from §5, including "at most 5 sections" and "at most 25 notes";
- the detail-level budget (§4.4) and the instruction to stay within it;
- "write every string in <language>" with the language name;
- for Arabic: Modern Standard Arabic, no Latin transliteration, no bidi control characters;
- "plain text only: no markdown, no bullets, no numbering, no emoji, no line breaks in note
  content";
- "no relationships, no ids other than the section/note ids";
- "return only the JSON object".

User message MUST contain only the normalised topic, objective and optional context, labelled
explicitly, plus the requested language and detail level. Prompt construction is deterministic for a
given request so tests can assert it without a network call.

Provider parameters: `temperature` 0.7, `max_tokens` 2000, JSON response format when the provider
supports it; no streaming, no tools.

### 10.5 Response normalisation

Order: decode → structural check → clamp → dedupe → drop → rebuild.

| Situation | Behaviour |
| --- | --- |
| Not valid JSON, or not an object | `AiProviderFailureException` → `502` |
| Missing `proposal`, `title`, `objective` or `sections` | `AiProviderFailureException` → `502` |
| More than 5 sections | Keep the first 5, set `truncated: true` |
| Section with more than 10 notes | Keep the first 10, `truncated: true` |
| More than 25 notes total | Keep the first 25 in section order, `truncated: true` |
| Note content longer than 280 | Clamp at 280 characters without splitting a UTF-8 sequence, `truncated: true` |
| Unknown `type` | Map to `idea` (never reject the whole proposal for one label) |
| Unknown `category` | Map to `ideas`, `truncated: false` (a label is not content loss) |
| Empty/whitespace note content, or duplicate content inside a section (case-insensitive) | Drop the note |
| Section with no notes after filtering | Drop the section |
| Section title longer than 80 / title longer than 120 / objective longer than 300 | Clamp, `truncated: true` |
| Duplicate section or note ids | Reassign ids deterministically (`s1`, `s2`, `s1-n1`, …) |
| No usable sections remain, or fewer than 3 notes | `AiProviderFailureException` → `502` |
| A `relationships` key anywhere | `AiProviderFailureException` → `502` (removed in v1, §4.5) |

Normalisation never invents content: it only trims, clamps, relabels or drops. A dropped section
never reappears as an empty one.

### 10.6 Error handling

| Exception | HTTP | `code` |
| --- | --- | --- |
| `AiRequestValidationException` (with field details) | `422` | `ai.invalid_request` |
| `AiUnsupportedSchemaVersionException` | `422` | `ai.unsupported_schema_version` |
| `AiMalformedJsonException` | `400` | `ai.malformed_json` |
| `AiWorkspaceInactiveException` | `403` | `ai.workspace_inactive` |
| `AiRateLimitedException` (with `retryAfter`) | `429` | `ai.rate_limited` |
| `AiProviderUnavailableException` | `503` | `ai.provider_unavailable` |
| `AiProviderTimeoutException` | `504` | `ai.provider_timeout` |
| `AiProviderFailureException` | `502` | `ai.provider_failed` |
| anything else | `500` | `ai.internal_error` (message replaced, nothing leaked) |

Exceptions live in `App\Ai\Exception\`; the controller is the only place that maps them to HTTP.
`AiProviderFailureException` may carry a provider status for logs but MUST NOT serialize it.

**Dependency decision (blocking Sprint 1A):** the provider implementation needs an HTTP client.
`symfony/http-client` is not in `backend/composer.json`, and `AGENTS.md` requires approval before a
new dependency. Recommendation: `symfony/http-client` (first-party, PSR-18 compatible, testable with
`MockHttpClient`); the alternative is a hand-rolled `ext-curl` client, which is more code and no
better here. No other new package is needed: validation and DTOs are hand-written in the repository's
current style.

---

## 11. Test obligations

Sprint 1A (backend), with a fake provider so no network call is made:

| Test | Expectation |
| --- | --- |
| Happy path | `200`, `schemaVersion 1.0`, response matches §4 |
| Each request limit (topic, objective, context, language, detailLevel, schema version) | `422`, field in `details`, provider never called |
| Unknown request field / unknown top-level key | `422` |
| Malformed JSON body | `400` |
| No token / expired token | `401` |
| Inactive workspace membership | `403` |
| Provider timeout, provider `5xx`, undecodable JSON, missing proposal | `504` / `502` / `502` / `502` |
| Provider `429` | `429` with `Retry-After` |
| Provider unconfigured (`AI_ENABLED=false` or empty key) | `503`, provider never called |
| Rate limits | the 6th call in the window returns `429`; counters are per user |
| Normalisation | 6 sections → 5 + `truncated`; 30 notes → 25; unknown type → `idea`; duplicate content dropped; empty section dropped; `relationships` rejected |
| No persistence | board, element, timeline and comment row counts are identical before and after a successful call |
| Logging | no prompt or proposal text appears in captured logs |
| Prompt builder | deterministic output; contains language, detail level and limits; contains no board data |
| Controller boundary | `BrainstormingGenerator` is the only collaborator; no repository or `EntityManager` is injected |

Sprint 1B (frontend), jsdom:

| Test | Expectation |
| --- | --- |
| Form validation | limits come from `AI_BRAINSTORMING_LIMITS`; submit is blocked with field errors |
| Success | preview renders title, objective, sections and note types; no write request is issued |
| Discard | no board or element request is made |
| Approve | one board create, then one element create per note, each with a distinct UUID, correct color, `metadata.aiNoteType` and §7.3 coordinates |
| Non-admin | apply to a new board is unavailable with a translated explanation |
| Partial failure | a failing note keeps `failed` state; retry reuses the same `mutationId` |
| Timeout/error mapping | `502`/`504`/`429`/`503` render translated messages |
| i18n | FR/EN/AR labels exist; Arabic sets `dir="rtl"` |

---

## 12. Changing this contract

1. Open one pull request that updates this file and both constant sets together.
2. Bump `schemaVersion` per §9 and record the change in the ADR log.
3. The PR description states the limit or shape change and which tests prove it.
4. Neither workstream starts implementing a change that is not merged here first.

---

## 13. Open decisions

| # | Decision | Owner | Recommendation |
| --- | --- | --- | --- |
| 1 | Approve `symfony/http-client` | repo owner (`AGENTS.md` dependency rule) | approve; it is the smallest testable client |
| 2 | Which provider/model the first release targets | product owner | one OpenAI-compatible provider via `AI_BASE_URL`/`AI_MODEL`; the interface keeps it replaceable |
| 3 | How a non-workspace-administrator applies a proposal | product owner | v1: generate always, apply into an existing editable board; creating a new board stays admin-only |
| 4 | Whether generation history/audit is required | product owner | not in v1 (no content stored); revisit with retention |
| 5 | Rate-limit numbers | product owner | the values in §6.8 are placeholders sized for a demo |
| 6 | Relationships between ideas | both teams | out of scope until connectors have validated endpoints and editor rendering (§4.5) |
