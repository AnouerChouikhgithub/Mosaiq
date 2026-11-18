# AI Sprint 1A — backend brainstorming generation

**Date:** 2026-11-18
**Branch:** `feat/ai-brainstorming-backend`
**Base:** `051400c` (Sprint 0 contract, published on `chore/ai-foundation-contract`)
**Contract:** [`docs/architecture/AI_BRAINSTORMING_CONTRACT.md`](../architecture/AI_BRAINSTORMING_CONTRACT.md) v1.0
**Scope:** the backend generation service and its HTTP endpoint. No frontend work, no board or canvas
persistence, no second provider.

---

## 1. Preparation

| Check | Result |
| --- | --- |
| `git fetch --all --prune` | no new `main` commit; `origin/main` remains `01aed5e` |
| Sprint 0 merged into `main`? | **No.** `docs/architecture/AI_BRAINSTORMING_CONTRACT.md` is absent from `origin/main` |
| Sprint 0 contract available? | **Yes** — published on `origin/chore/ai-foundation-contract` (`051400c`), with the ADR and the Sprint 0 report |
| Branch | created with `git switch -c feat/ai-brainstorming-backend origin/chore/ai-foundation-contract` |
| Working tree | the two pre-existing user edits (`BoardPresenceRepository.php`, `PresenceApiControllerTest.php`) and untracked `docs/screenshots/` are preserved and excluded from this branch's commits |
| Contract + ADR read | yes, including §3–§10 and ADR-001 |
| Backend conventions inspected | controllers (`JsonPayloadTrait`, status/`error` shape), `ApiTokenAuthenticator`, `security.yaml` access control, Doctrine mapping/`UuidGenerator`, migrations style, `ApiFunctionalTestCase`, `AuthThrottle` counter pattern, PHPStan/CS-Fixer config |

Reused instead of rebuilt: `AbstractController` + `JsonPayloadTrait`, the existing stateless bearer
authenticator and its `^/api → ROLE_USER` access-control rule, Doctrine ORM/DBAL, `LoggerInterface`,
the functional test harness, and the database-backed counter approach of `AuthThrottle`.

**No Composer dependency was added.** `symfony/http-client` is still not installed and the contract
lists a hand-written client as the accepted alternative, so the adapter uses `ext-curl` (present in
this environment; the transport fails closed with `503` if the extension is missing).

> A Sprint 1B branch (`feat/ai-brainstorming-frontend`, commit `51b17bf`) appeared in the working
> tree from a parallel workstream while this sprint was running. It contains frontend files only; it
> was left untouched and this branch does not include it.

---

## 2. Provider selected and why

**One OpenAI-compatible chat-completions provider**, selected through `AI_PROVIDER`
(only `openai-compatible` is accepted) and configured by `AI_BASE_URL` + `AI_MODEL`. The deployment
chooses the vendor or gateway; the code shape stays the same.

| Criterion | Why this shape wins for v1 |
| --- | --- |
| Structured output | `response_format: {"type":"json_object"}` is the most widely supported forced-JSON mode, and the server validates the document anyway, so a provider cannot bypass the contract |
| Quality | The vendors exposing this API are the ones with strong instruction-following and multilingual (including Arabic) output |
| Latency | One non-streaming request with a 20 s per-attempt cap and a 25 s total budget across at most two attempts |
| Cost | `max_tokens: 2000` and a 25-note/5-section output cap bound both prompt and completion spend |
| Availability / SDK | Plain HTTPS + JSON, no vendor SDK, so swapping vendor is configuration, not code; only one provider integration ships |

Only this one adapter exists; `OpenAiCompatibleBrainstormingProvider` is the only service implementing
`BrainstormingProviderInterface`.

---

## 3. Architecture

### New files

| File | Responsibility |
| --- | --- |
| `src/Ai/Brainstorming/BrainstormingLimits.php` | The contract's limits as constants shared by prompt and validator |
| `src/Ai/Brainstorming/BrainstormingRequest.php` | Immutable validated input DTO (`fromPayload`) |
| `src/Ai/Brainstorming/BrainstormingProposal.php`, `BrainstormingSection.php`, `BrainstormingNote.php` | Output DTOs and their wire payload |
| `src/Ai/Brainstorming/BrainstormingPromptBuilder.php` | Deterministic system/user messages |
| `src/Ai/Brainstorming/BrainstormingProviderInterface.php` | `name()` + `generate(BrainstormingRequest): string` |
| `src/Ai/Brainstorming/Provider/OpenAiCompatibleBrainstormingProvider.php` | Configuration, request envelope, retry/timeout policy, error mapping |
| `src/Ai/Brainstorming/Provider/ChatCompletionTransportInterface.php` | HTTP seam (testable without a network) |
| `src/Ai/Brainstorming/Provider/CurlChatCompletionTransport.php` | `ext-curl` POST with timeout and `Retry-After` capture |
| `src/Ai/Brainstorming/BrainstormingNormalizer.php` | Trim, strip control characters, collapse whitespace, clamp, relabel |
| `src/Ai/Brainstorming/BrainstormingResponseValidator.php` | Strict schema/limit/duplicate/relationship validation |
| `src/Ai/Brainstorming/BrainstormingGenerator.php` | Provider → validator orchestration and technical logging |
| `src/Ai/AiGenerationRateLimiter.php` | Fixed-window per-user + per-deployment quota |
| `src/Ai/Exception/*.php` | Eight typed failures mapped to contract statuses/codes |
| `src/Controller/AiBrainstormingController.php` | `POST /api/ai/brainstorming/generate` |
| `src/Entity/AiGenerationUsage.php`, `src/Repository/AiGenerationUsageRepository.php` | Quota counters |
| `migrations/Version20261118120000.php` | `ai_generation_usage` table |
| `config/services_test.yaml`, `tests/Support/FakeBrainstormingProvider.php`, `tests/Support/RecordingChatCompletionTransport.php` | Deterministic test seam |
| `tests/Ai/*`, `tests/Controller/AiBrainstormingApiControllerTest.php` | Unit + functional coverage |
| `backend/README.md` | Setup, environment, provider configuration, API/error examples, quota |

Modified: `config/services.yaml` (provider alias + rate-limiter arguments), `.env` / `.env.test`
(AI variables, documented defaults, no secret), root `README.md` (documentation pointers).

### Boundaries

```
Controller (auth, body size, decoding, status mapping)
  └─ BrainstormingGenerator (provider + validator + logging; no EntityManager)
       ├─ BrainstormingProviderInterface → OpenAiCompatible… → ChatCompletionTransport → curl
       └─ BrainstormingResponseValidator → BrainstormingNormalizer → BrainstormingProposal
AiGenerationRateLimiter → AiGenerationUsageRepository (DB counters)
```

No class in `App\Ai\Brainstorming` depends on Doctrine, a repository, `BoardService` or
`TimelineService`; the only Doctrine access in the feature is the quota repository, which stores no
prompt or proposal data.

---

## 4. Endpoint, request and response

```
POST /api/ai/brainstorming/generate
```

Request (all fields validated before any provider call; unknown fields rejected; body ≤ 8 KiB):

```json
{
  "topic": "Location de vélos à Tunis",
  "objective": "Identifier les premiers quartiers et le budget",
  "context": "Budget limité, trois personnes, quartier du Lac",
  "language": "fr",
  "detailLevel": "balanced"
}
```

`200` response (validated, `Cache-Control: no-store`, nothing persisted):

```json
{
  "schemaVersion": "1.0",
  "language": "fr",
  "detailLevel": "balanced",
  "truncated": false,
  "proposal": {
    "title": "Location de vélos à Tunis",
    "objective": "Identifier les premiers quartiers et le budget",
    "sections": [
      {
        "id": "s1",
        "title": "Publics prioritaires",
        "category": "audience",
        "notes": [
          { "id": "s1-n1", "type": "idea", "content": "Étudiants du campus de la Manouba" }
        ]
      }
    ]
  }
}
```

Errors keep the existing `error` field and add a stable `code`:

| Status | `code` | Example body |
| --- | --- | --- |
| `400` | `ai.malformed_json` | `{"error":"Malformed JSON payload.","code":"ai.malformed_json"}` |
| `401` | — | `{"error":"Authentication required."}` |
| `403` | `ai.workspace_inactive` | `{"error":"Forbidden.","code":"ai.workspace_inactive"}` |
| `422` | `ai.invalid_request` | `{"error":"\"topic\" must be at most 280 characters.","code":"ai.invalid_request","details":[{"field":"topic","message":"must be at most 280 characters."}]}` |
| `422` | `ai.unsupported_schema_version` | `{"error":"Unsupported schema version \"2.0\".","code":"ai.unsupported_schema_version"}` |
| `429` | `ai.rate_limited` | `{"error":"Too many AI generation requests. Try again later.","code":"ai.rate_limited","retryAfter":600}` + `Retry-After: 600` |
| `502` | `ai.provider_failed` | `{"error":"The AI provider could not produce a usable proposal.","code":"ai.provider_failed"}` |
| `503` | `ai.provider_unavailable` | `{"error":"AI generation is not configured.","code":"ai.provider_unavailable"}` |
| `504` | `ai.provider_timeout` | `{"error":"The AI provider took too long to answer.","code":"ai.provider_timeout"}` |
| `500` | `ai.internal_error` | `{"error":"The AI generation request failed.","code":"ai.internal_error"}` |

`details` is present for field errors and lists **every** violation (not just the first), sorted by
validation order.

---

## 5. Security and rate limiting

- **Authentication only**: bearer token through the existing authenticator; `^/api` already requires
  `ROLE_USER`. The endpoint has no `boardId`, so it cannot read or target existing content.
- **Fail closed**: `AI_ENABLED=0`, an empty key, an empty base URL/model or an unknown provider slug
  returns `503` without any network call.
- **Key handling**: `AI_PROVIDER_API_KEY` is read by the backend only; `.env` holds an empty
  placeholder, `.env.local`/the deployment secret store holds the value. The key is never logged, put
  in a response or sent to the browser. Log lines contain names of missing variables, not values.
- **Untrusted output**: JSON decoding with throwing errors, object shape checks, required-field
  checks, enum normalisation, length clamps, section/note caps, duplicate removal, empty-content
  rejection, relationship rejection, 64 KiB response cap. Raw model text never reaches the database
  or an HTML sink.
- **Prompt/privacy**: only `topic`, `objective`, `context`, `language`, `detailLevel` leave Mosaiq.
  No board/workspace/member/history data is sent. Logs carry prompt **length** only.
- **Quota**: 5 per 10 minutes and 20 per hour per user, 60 per minute per deployment, all
  environment-driven. Counters are `INSERT … ON CONFLICT … RETURNING` rows in `ai_generation_usage`,
  so parallel workers share them and cannot double-spend the last slot. Expired windows are pruned on
  write. Invalid requests never consume a slot; a refused attempt does.
- **Provider budget**: 20 s per attempt, 25 s total, at most two attempts, retry only on transport
  errors and `5xx`, `Retry-After` honoured and clamped to 1–3600 s.
- **No persistence**: no board, element, timeline, comment or proposal row is written by generation;
  the only writes are the three quota counters.

---

## 6. Testing

Deterministic: the test environment aliases the provider interface to
`App\Tests\Support\FakeBrainstormingProvider` (`config/services_test.yaml`), which replays queued
documents or exceptions; the adapter's own tests use `RecordingChatCompletionTransport`. No API key
is needed and the suite never reaches the network.

Coverage (25 functional + 24 unit tests):

1. valid generation (shape, ids, headers, normalized request) · 2. invalid input (language, type) ·
3. missing required fields · 4. unsupported language · 5. excessive prompt length · 6. excessive
section count (capped + `truncated`) · 7. excessive note count (10/section, 25 total) ·
8. malformed JSON body · 9. unexpected note type/category normalised · 10. provider timeout (`504`) ·
11. provider unavailable (`503`) · 12. invalid provider response (non-JSON, no sections,
`relationships`, missing title) · 13. unauthorized (`401`) · 14. rate limit (6th request `429`,
`Retry-After`, provider not called) · 15. provider adapter configuration (URL, headers, model,
timeout, disabled/missing key/slug, 429 mapping, one retry on `5xx`, no retry on `4xx`, envelope
parsing) · 16. no board/canvas/timeline/comment persistence.

Plus: request-size guard, unknown field, unsupported schema version, non-object body, Arabic prompt
instruction, whitespace/duplicate note removal, clamping, and `truncated` semantics.

### Actual results

```
php bin/phpunit tests/Ai                                  → OK (24 tests, 72 assertions)
php bin/phpunit tests/Controller/AiBrainstormingApiControllerTest.php
                                                          → OK (25 tests, 134 assertions)
composer analyse                                           → 0 errors (PHPStan level 5)
composer cs:check                                          → 0 of 103 files need fixing
composer validate --no-check-publish                       → valid
composer audit --locked                                    → no advisories
composer check-platform-reqs                               → all requirements success
php bin/console lint:container                             → OK
APP_ENV=test doctrine:migrations:migrate (fresh database)  → 10 migrations, 80 statements
APP_ENV=test doctrine:schema:validate                      → mapping correct, schema in sync
php bin/phpunit (full suite)                               → see the final message / PR
```

---

## 7. Was real provider execution tested?

**No.** No provider credential is available in this environment, so no live API call was made and no
"successful live generation" is claimed. What was verified is the adapter against a recorded
transport (URL, headers, body, timeout, retry, 429, envelope errors) and the full pipeline against
scripted provider documents. A live smoke test needs `AI_ENABLED=1`, `AI_BASE_URL`, `AI_MODEL` and
`AI_PROVIDER_API_KEY` in `backend/.env.local`, then one `curl` to the endpoint.

---

## 8. Known limitations and implementation notes

1. **`ext-curl` is required at runtime.** It is not declared in `composer.json` (avoiding an
   unrelated lock/`check-platform-reqs` change); the transport throws `503 ai.provider_unavailable`
   when the extension is missing, rather than a fatal error.
2. **`response_format: json_object` is assumed.** A compatible endpoint that rejects it answers
   `4xx`, surfaced as `502`. Making it configurable would be a contract/config change.
3. **The prompt builder is called by the provider adapter**, not by the generator as the contract §10.2
   diagram suggests; this keeps the interface at `generate(request)` so a second provider still gets
   the shared prompt. Documentation-level deviation, no API change.
4. **The server assigns all section/note ids** (`s1`, `s1-n1`, …) and ignores model-supplied ids. The
   contract allows deterministic reassignment; this is a superset (always reassigned) and removes id
   collisions entirely.
5. **`403 ai.workspace_inactive` is unreachable over HTTP in practice**: the token authenticator
   already refuses a non-active membership with `401`. The branch is kept as defense in depth.
6. **The quota is fixed-window and DB-backed**, not distributed across deployments; a refused attempt
   consumes a slot. Numbers are initial/demo values, configurable per §6.8.
7. **No generation history or audit**: nothing is stored, which is also why no prompt retention exists.
8. **No content moderation or prompt-injection detection.** Injected instructions can only shape
   proposal text, which is validated, never executed and never persisted automatically.
9. **The migration was validated on a fresh disposable test database, not applied to the developer
   database** (`doctrine:migrations:migrate` was deliberately not run against `mosaiq`).
10. **No frontend changes and no frontend test run** for this sprint; Sprint 1B owns the client.
11. `.env.test` sets `AI_ENABLED=1` (not `0` as the contract text suggests) because isolation in tests
    comes from the fake provider alias, not from the flag; the real adapter is never instantiated by
    the suite.

---

## 9. PR description (ready to paste)

> **feat(ai): add the backend brainstorming generation service**
>
> Implements Sprint 1A against the Sprint 0 contract (`051400c`). One OpenAI-compatible provider,
> strict server-side validation, a database-backed quota, and an endpoint that only generates.
>
> - `POST /api/ai/brainstorming/generate` (authenticated, board-less) returns
>   `{schemaVersion, language, detailLevel, truncated, proposal}` with stable `code` values for
>   malformed JSON, validation, unsupported schema version, quota, provider failure/unavailable/
>   timeout and unexpected errors.
> - Provider-independent pipeline: validated request DTO, deterministic prompt, provider interface +
>   single cURL adapter (no new Composer dependency), normalizer, strict validator, generator with
>   technical-only logging.
> - Quota: 5/10 min and 20/h per user, 60/min per deployment, atomic DB upsert counters; 20 s per
>   attempt, 25 s total, one retry on transport/5xx only.
> - No board, element, timeline or comment is created; a test asserts all counts are unchanged.
> - Docs: `backend/README.md` (setup, env vars, provider config, examples, errors, quotas) and
>   `docs/sprints/ai-sprint-1a-report.md`.
>
> Tests use a scripted fake provider, so CI needs no key and no network. Results: 25 functional +
> 24 unit tests green, PHPStan level 5 clean, CS-Fixer 0/103, container lint, fresh-DB migrations and
> schema validation pass.
>
> Real provider execution was **not** verified (no credential available).
