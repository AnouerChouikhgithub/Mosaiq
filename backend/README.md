# Mosaiq backend

Symfony 7.4 JSON API for Mosaiq: authentication, workspaces, boards, canvas elements, comments,
presence/timeline transport and AI brainstorming generation.

- PHP >= 8.3 (the locked PHPUnit 12 floor); `composer.json` pins `config.platform.php` to `8.3.0`.
- PostgreSQL 16 (the only supported database), optional Redis/Mailpit from the root
  `docker-compose.yml`.
- No API Platform, no Messenger, no JWT bundle, no HTTP client package: the AI adapter uses
  `ext-curl`.

## Setup

```bash
cd backend
composer install
# backend/.env.local (ignored)
DATABASE_URL="postgresql://app:app@127.0.0.1:5432/mosaiq?serverVersion=16&charset=utf8"
MAILER_DSN="smtp://127.0.0.1:1025"
APP_FRONTEND_URL="http://localhost:5173"
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:fixtures:load          # local/disposable databases only
symfony serve                              # http://127.0.0.1:8000
```

Tests use a disposable `_test` database and `MAILER_DSN="null://null"`; the harness refuses to reset
anything else. `composer test`, `composer analyse`, `composer cs:check` are the quality gates.

## Environment

| Variable | Purpose | Default |
| --- | --- | --- |
| `APP_ENV`, `APP_SECRET`, `APP_SHARE_DIR` | Symfony runtime | `dev`, empty, `var/share` |
| `DATABASE_URL` | PostgreSQL DSN | local placeholder in `.env` |
| `MAILER_DSN`, `APP_FRONTEND_URL` | Invitation mail and link origin | Mailpit / Vite |
| `AUTH_TOKEN_TTL` | Bearer token lifetime in seconds | `604800` |
| `AUTH_THROTTLE_ENABLED`, `AUTH_LOGIN_*`, `AUTH_REGISTER_*` | Credential throttling | see `.env` |
| `AI_ENABLED` | Master switch for AI generation | `0` |
| `AI_PROVIDER` | Provider slug (only `openai-compatible` is supported) | `openai-compatible` |
| `AI_BASE_URL` | API root of the provider, **without** `/chat/completions` | empty |
| `AI_MODEL` | Model identifier sent to the provider | empty |
| `AI_PROVIDER_API_KEY` | Secret key; **only** in `.env.local` or the deployment secret store | empty |
| `AI_TIMEOUT_SECONDS` | Per-attempt timeout, clamped to 20 by the code | `20` |
| `AI_RATE_LIMIT_PER_10_MINUTES` | Generation quota per user | `5` |
| `AI_RATE_LIMIT_PER_HOUR` | Generation quota per user | `20` |
| `AI_RATE_LIMIT_GLOBAL_PER_MINUTE` | Generation quota per deployment | `60` |

`AI_ENABLED=0`, an empty key, an empty base URL/model or an unknown `AI_PROVIDER` make the endpoint
answer `503 ai.provider_unavailable` (fail closed) instead of calling anything.

The key is read by the backend only: it is never sent to the browser, never written to a log and
never included in a response.

## AI brainstorming generation

Contract: [`docs/architecture/AI_BRAINSTORMING_CONTRACT.md`](../docs/architecture/AI_BRAINSTORMING_CONTRACT.md).
Decision record: [`docs/architecture/adr/ADR-001-ai-generation-isolated-from-persistence.md`](../docs/architecture/adr/ADR-001-ai-generation-isolated-from-persistence.md).

```
POST /api/ai/brainstorming/generate
Authorization: Bearer <token>
Content-Type: application/json
```

The endpoint returns a validated proposal. It does **not** create a board, a canvas element, a
timeline entry or a comment, and it accepts no board or workspace identifier.

### Request

```json
{
  "topic": "Location de vélos à Tunis",
  "objective": "Identifier les premiers quartiers et le budget",
  "context": "Budget limité, trois personnes, quartier du Lac",
  "language": "fr",
  "detailLevel": "balanced"
}
```

`topic` (3–280), `objective` (5–500), optional `context` (0–2000), `language` (`en|fr|ar`),
`detailLevel` (`concise|balanced|detailed`), optional `schemaVersion` (`"1.0"`). Unknown fields are
rejected. The whole body is capped at 8 KiB.

### Success — `200`

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

`category` is one of `goals, audience, ideas, risks, actions, questions`; `type` is one of
`idea, question, challenge, opportunity, action`. `truncated` means the validator had to clamp or
drop model output to respect the limits. The response is `Cache-Control: no-store`.

### Errors

Every error keeps the existing `error` field and adds a stable `code`:

| Status | `code` | When |
| --- | --- | --- |
| `400` | `ai.malformed_json` | Body is not JSON or not a JSON object |
| `401` | — | Missing, invalid, expired or revoked token |
| `403` | `ai.workspace_inactive` | Defensive: membership is not active (the authenticator normally answers 401) |
| `422` | `ai.invalid_request` | A field is missing, mistyped, out of range, unknown, or the body is too large; `details` lists every field |
| `422` | `ai.unsupported_schema_version` | `schemaVersion` is not `"1.0"` |
| `429` | `ai.rate_limited` | User or deployment quota exhausted; `Retry-After` and `retryAfter` give the wait |
| `502` | `ai.provider_failed` | Provider error, unparseable output or a structurally unusable proposal |
| `503` | `ai.provider_unavailable` | Generation disabled or provider not configured |
| `504` | `ai.provider_timeout` | The attempt/total provider budget elapsed |
| `500` | `ai.internal_error` | Unexpected failure; nothing internal is exposed |

```json
{
  "error": "\"topic\" must be at most 280 characters.",
  "code": "ai.invalid_request",
  "details": [{ "field": "topic", "message": "must be at most 280 characters." }]
}
```

```json
{
  "error": "Too many AI generation requests. Try again later.",
  "code": "ai.rate_limited",
  "retryAfter": 420
}
```

```json
{
  "error": "The AI provider could not produce a usable proposal.",
  "code": "ai.provider_failed"
}
```

### Rate limits and cost protection

- 5 requests per 10 minutes and 20 per hour per authenticated user, plus 60 per minute for the whole
  deployment. All three are environment-driven.
- Counters live in the `ai_generation_usage` table (`scope`, `window_key`, `used_count`,
  `expires_at`) and are incremented with a single atomic upsert, so parallel PHP workers share them.
  Expired rows are deleted on write. This is **not** a distributed limiter and does not coordinate
  across deployments.
- Invalid requests never reach the limiter or the provider; an attempted request that is then refused
  still consumes its slot, so retrying cannot probe for capacity.
- Provider timeouts: 20 s per attempt (clamped), 25 s total across at most two attempts, with one
  retry only for transport errors and `5xx`. `429` from the provider is surfaced as `429`, and other
  `4xx` answers are not retried.
- Request body (8 KiB) and proposal size (64 KiB) are capped before/after the provider call.

### Privacy and logging

Only the user's `topic`, `objective`, `context`, `language` and `detailLevel` are sent to the
provider; no board, workspace, member or history data is included. Logs record the event, user id,
provider slug, error code, prompt **length**, section/note counts and duration — never the prompt,
the proposal or the key. Provider error bodies and stack traces are never returned to clients.

Nothing is persisted by generation, so there is no server-side generation history in this release.

## Tests

```bash
composer validate --no-check-publish && composer audit --locked && composer check-platform-reqs
php bin/console lint:container
php bin/console doctrine:schema:validate
composer analyse && composer cs:check && composer test
```

The suite replaces the provider with `App\Tests\Support\FakeBrainstormingProvider`
(`config/services_test.yaml`), so it needs no API key and never reaches the network.
