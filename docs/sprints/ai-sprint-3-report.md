# AI Sprint 3 — brainstorming quality, security and release readiness

**Date:** 2026-11-18
**Branch:** `test/ai-brainstorming-release`
**Base:** `origin/main` (`01aed5e`) + the 8 AI commits ending at `feat/ai-brainstorming-board-integration` (`aed5f97`)
**Scope:** verify, harden, test and document the already-integrated AI brainstorming feature. **No new AI feature was added.**
**Decision:** **ready for a controlled demo**, not for unrestricted release. See §15.

---

## 1. Branch and final commit

| Item | Value |
| --- | --- |
| Branch | `test/ai-brainstorming-release` (created from `aed5f97`) |
| `origin/main` | `01aed5e` — *does not contain the AI feature* |
| Commits on this branch | `273d7d4` test(ai) — tests + validator hardening; `6cd1e27` docs(ai) — documentation and this report; plus the docs-only commit that fills in §16 (no code after the CI run) |
| Push | pushed to `origin`; PR opened against `main` (link in §16) |
| `main` | untouched; nothing merged, nothing force-pushed |

**Base decision (recorded because it deviates from the literal instruction "start from the latest
main"):** `main` has no AI code at all — `git rev-list --left-right --count origin/main...aed5f97`
returns `0 8`, and `origin/main` is an ancestor of the AI branch. A branch cut from bare `main` could
not test the feature, so the release branch is `main` + the AI commits, verified to be a fast-forward
relationship (`git merge-base --is-ancestor origin/main HEAD` is true). This is therefore "latest main
plus the AI integration under review", not an arbitrary older base.

The worktree also contained **unrelated, uncommitted** edits when the sprint started
(`backend/src/Repository/BoardPresenceRepository.php`, `backend/tests/Controller/PresenceApiControllerTest.php`,
and untracked `docs/screenshots/`). They were preserved, exercised by the full suite, and **not**
committed — they are someone else's in-flight work, not part of the AI release.

## 2. AI architecture summary

```
dashboard (AiBrainstormCard / header button)
  └─ BrainstormingDialog              form + consent + states
       └─ useBrainstormingGeneration   one request in flight, cancel, retry with the same request
            └─ api/aiBrainstorming.ts  POST /api/ai/brainstorming/generate, 35 s budget, shape guard
                 └─ AiBrainstormingController
                      ├─ firewall `^/api` → ROLE_USER; explicit User + active-membership check
                      ├─ BrainstormingRequest::fromPayload  8 KiB body, unknown fields, UTF-8, enums, lengths
                      ├─ AiGenerationRateLimiter            DB fixed windows, atomic upsert
                      └─ BrainstormingGenerator
                           ├─ OpenAiCompatibleBrainstormingProvider → CurlChatCompletionTransport
                           │     (ext-curl, no redirects, 20 s attempt / 25 s total / 2 attempts)
                           └─ BrainstormingResponseValidator    the only trust boundary for model output
       └─ BrainstormingPreview        local draft, editable, nothing persisted
            └─ importProposal (on explicit approval)
                 ├─ buildProposalImportPlan   re-validate, deterministic layout, mint mutation ids
                 ├─ POST /api/boards                          normal endpoint, normal voters
                 ├─ POST /api/boards/{id}/elements  × N        idempotent per (board, author, mutationId)
                 └─ rollback on failure: delete created notes, then trash the board
```

**Isolation by construction:** `BrainstormingGenerator` has no `EntityManager`, repository or board
service in its constructor (ADR-001), so a successful generation cannot write. The generation endpoint
accepts no board or workspace identifier, so it cannot be aimed at existing content.

**Import is not a second persistence path:** it is the same board/element endpoints the editor uses,
with the same authorization, timeline, presence and sharing behaviour. `metadata.aiSectionId`,
`aiSectionTitle` and `aiNoteType` are additive canvas metadata.

## 3. Security review

Reviewed against the real code, not the reports. Findings are in §12; what follows is what was
**verified**, and by which test.

| Area | Verdict | Evidence |
| --- | --- | --- |
| Authentication | **Correct** | `^/api` firewall requires `ROLE_USER`; the controller also rejects a non-`User` principal. `AiBrainstormingApiControllerTest::testRequiresAuthentication` (401, provider never called) |
| Authorization (generation) | **Correct** | No board/workspace id accepted; inactive membership → `403 ai.workspace_inactive` (defensive, normally 401 from the authenticator) |
| Authorization (import) | **Correct** | Import goes through `POST /api/boards` + elements, so `BoardVoter`/`WorkspaceVoter` decide. `AiProposalImportTest::testNonAdministratorCannotCreateTheBoard` (403, nothing written) and `testOutsiderCannotAddGeneratedNotes` (403, 0 elements) |
| Provider credential handling | **Correct** | Injected server-side in `config/services.yaml` from `%env(...)%`; never in a response, log or frontend bundle. The browser client sends only the session bearer token. Grep finds no `AI_PROVIDER_API_KEY` under `frontend/`; `pnpm audit`/`composer audit` clean |
| Input validation | **Correct** | 8 KiB cap before decoding, unknown fields rejected, invalid UTF-8 and control characters rejected, enums enforced, 280/500/2000 limits. `testRejectsMissingRequiredFields`, `testRejectsUnknownField`, `testRejectsOversizedRequestBody`, `testRejectsExcessivePromptLength`, plus `BrainstormingPromptQualityTest` dataset |
| Output treated as untrusted | **Correct** | `BrainstormingResponseValidator` is the sole trust boundary: 64 KiB cap, JSON-object shape, enum normalisation, clamping, duplicate removal (now cross-section too), server-assigned ids, `relationships` refused. `BrainstormingResponseValidatorTest` (11 cases), `AiBrainstormingApiControllerTest` (malformed/whitespace/oversized/structural) |
| Nothing persisted before approval | **Correct** | `testGenerationPersistsNoBoardOrCanvasData` asserts boards/elements/timeline/threads unchanged; only the 3 quota counters are written |
| Prompt length / section / note limits | **Correct and shared** | `BrainstormingLimits` feeds both the prompt and the validator, so instruction and check cannot drift; asserted by `testTheSystemInstructionCarriesTheSchemaAndTheHardLimits` |
| Rate limiting | **Correct, not distributed** | `INSERT … ON CONFLICT … RETURNING` is a single atomic statement — no check-then-write race. 5/10 min + 20/h per user, 60/min per deployment. `testRateLimitBlocksTheSixthRequestWithoutCallingTheProvider`; invalid requests never spend quota (`testRejectsInvalidRequestBeforeSpendingQuota`) |
| Provider timeout / retries | **Correct** | One shared 25 s deadline; per-attempt timeout clamped to ≤20 s; retry only for transport errors and `5xx`; `429` surfaced as `429`; other `4xx` not retried. `OpenAiCompatibleBrainstormingProviderTest` (5 cases) |
| Error disclosure | **Correct** | Stable codes and fixed messages; unexpected failures log the exception and return `500 ai.internal_error`. Provider text/stack traces never reach the client; the provider is faked at the HTTP boundary in PHPUnit |
| Logging / sensitive data | **Correct** | `ai.brainstorming.generated|failed` log user id, provider slug, language, detail level, prompt **length**, counts, duration — never prompt, proposal or key. `ai_generation_usage` stores the user id as `scope`, a window key and a count: no content |
| External provider processing | **Documented** | Only `topic`, `objective`, `context`, `language`, `detailLevel` leave the server. No board, workspace, member, comment or history data. Consent notice is required before the first generation per browser session |
| `curl` hardening | **Correct** | `CURLOPT_FOLLOWLOCATION => false` prevents the bearer key from following a redirect; no response body read at the transport layer; `Retry-After` parsed defensively (`^\d{1,4}$`) and clamped |
| Dependency vulnerabilities | **None found** | `composer audit --locked`: no advisories. `pnpm audit --prod`: no known vulnerabilities |

**Bypass attempts that were checked and failed:** pointing generation at a board (no such field);
importing into a board the caller cannot access (403 at both endpoints); duplicating notes on retry
(replayed by idempotency key, `testRepeatedApprovalReplaysInsteadOfDuplicating`); exceeding output
limits (validator caps, `truncated`); reading the key from the response or the bundle (absent).

## 4. Privacy review

- **Data minimisation:** the request carries five fields. `context` is optional and omitted when empty.
  No board content, no collaborator list, no user email is ever sent to the provider.
- **No server-side generation history:** generation persists nothing, so there is no prompt archive to
  breach or leak. The only AI table is `ai_generation_usage` (scope, window, count, expiry).
- **Logs:** length and counts only. Verified by reading both log sites; no `$request->topic`,
  `$proposal` or `$apiKey` appears in any log call.
- **Credential exposure:** none in the browser, the response, the logs or git (`.env` holds an empty
  documented default; the real key belongs in `.env.local` or the deployment secret store).
- **External processing:** under the GDPR framing this is a processor relationship between the Mosaiq
  operator and whichever provider `AI_BASE_URL` points at. **Mosaiq does not control that provider's
  retention, training or sub-processor policy**, and the UI notice says the text leaves Mosaiq. A
  deployment that needs a DPA, a region or a zero-retention tier must settle it with the provider; the
  code cannot enforce it. This is a **P3 operational dependency**, recorded, not fixable in code.
- **Consent:** `sessionStorage` per browser session (not per user, not a legal consent record). It is a
  reminder, not a control — unchanged from Sprint 1B and still honestly labelled as such.
- **Public read path (pre-existing, not AI):** anonymous `GET` of a board's canvas is allowed when
  `publicAccess` is on. Generated boards inherit that behaviour because they are ordinary boards; the
  public serializer still omits members, comments, timeline and author emails. If "AI boards must never
  be publicly readable" is a product rule, it is a board-feature decision, not an AI one.

## 5. AI output quality assessment

**What was tested deterministically** (`backend/tests/Ai/BrainstormingPromptQualityTest.php`, 11 tests,
10 prompt shapes): broad, highly specific, short, at-the-limit (280/500/2000), English, French, Arabic,
ambiguous, "many ideas", and a prompt containing unexpected instructions. The suite proves:

- every allowed shape is accepted at the request boundary and the normalised lengths are bounded;
- the **system instruction is byte-identical for every prompt** and never contains the user's topic, so
  user text cannot rewrite the instruction (prompt-injection resistance at the instruction level — not
  a guarantee about model behaviour);
- language and detail level reach the prompt, and only Arabic asks for Modern Standard Arabic without
  Latin transliteration or bidi controls;
- unexpected instructions in the topic/objective/context stay in the **user** message as data;
- proposals written in Latin, French-accented and Arabic script survive validation unchanged (UTF-8
  handling, RTL text, em dashes);
- prose wrapped around a JSON document, unknown enum values, unknown nested fields and repetition are
  handled deterministically — accepted-and-normalised or rejected, never guessed;
- repetition is now controlled **across sections**, not only within one.

**What was NOT tested, and cannot be without a provider key:** whether a real model's ideas are
relevant to the prompt, whether sections are meaningful, whether notes are genuinely concise, whether
the requested language is actually respected in the output, and whether "many ideas" prompts produce
distinct rather than padded ideas. **No model output was inspected in this sprint.** The Sprint 2
manual pass used a local stub, which proves transport and rendering, not quality. Treat the quality of
real generations as **unverified**; the prompt is engineered for it (one idea per note, no filler, no
markdown, explicit limits) but engineering intent is not evidence.

**Known quality limitations that remain true even with a good model:**

- The proposal objective is not persisted (no text element for it), so it exists only in the preview.
- Section headings are metadata; the board shows bands, not headings.
- Output is not fact-checked and the UI does not claim it is; a plausible-sounding note can be wrong.
- Cross-section cleaning removes exact (case-insensitive, whitespace-normalised) duplicates only;
  paraphrases are not detected.

## 6. Backend test results

Command: `cd backend && php bin/phpunit` — **OK (218 tests, 1664 assertions)**, 07:56 on Windows/PHP 8.5.12.
Baseline was 207/1520; this sprint adds 11 tests / 144 assertions.

| Check | Command | Result |
| --- | --- | --- |
| Validation | `composer validate --no-check-publish` | `./composer.json is valid` |
| Advisories | `composer audit --locked` | `No security vulnerability advisories found` |
| Platform | `composer check-platform-reqs` | PASS (local PHP 8.5.12; lock pinned to 8.3.0) |
| Static analysis | `composer analyse` (PHPStan level 5) | `[OK] No errors` |
| Coding style | `composer cs:check` | `Found 0 of 105 files that can be fixed` |
| Container | `php bin/console lint:container` | `[OK] The container was linted successfully` |
| Schema | `php bin/console doctrine:schema:validate` | mapping correct, database in sync |
| Full suite | `php bin/phpunit` | `OK (218 tests, 1664 assertions)` |

## 7. Frontend test results

| Check | Command | Result |
| --- | --- | --- |
| Lint | `pnpm run lint` | exit 0, no findings |
| Build | `pnpm run build` | `tsc -b` + Vite 8, built in 8.04 s; `index` 226.23 kB, `ui` 164.42 kB, `vendor` 247.30 kB, css 41.85 kB |
| Tests | `pnpm test` | **15 files passed, 103 tests passed** (baseline 14/82) |

New in this sprint: `frontend/src/tests/AiBrainstormingClient.test.ts` — **21 tests** exercising the
real client rather than a mocked module: contract request/headers/body, bearer token, 8 KiB refusal
before the network, the 401/403/422/429/500/502/503/504 → error-kind map, validation details and
`Retry-After` clamping (including a hostile value), non-JSON body, four off-contract shapes, a future
`schemaVersion`, caller cancellation as *cancelled*, the 35 s budget as *timeout*, and an unreachable
endpoint as *network*.

Existing AI coverage already proved: local-only preview editing (title, objective, section titles,
note content, note type, add/remove/reorder under caps), nothing written before approval, import
mapping and coordinates, duplicate-approval guard, rollback, permission change, expired session,
non-administrator gate, FR/EN/AR rendering with RTL `dir`, responsive classes, and **the feature flag
hiding every entry point while normal board creation keeps working**.

## 8. CI workflow links and actual status

| Run | Branch | Result |
| --- | --- | --- |
| [44141113308](https://github.com/AnouerChouikhgithub/Mosaiq/actions/runs/44141113308) | `main` | **success** (2m09s, 2026-11-17) — the last green `main` run |
| [44319153923](https://github.com/AnouerChouikhgithub/Mosaiq/actions/runs/44319153923) | `test/ai-brainstorming-release` (PR #5) | **success** (1m48s) — Backend pass 1m45s, Frontend pass 47s. The first CI execution the AI code has ever had |

**Finding (P2, structural):** `.github/workflows/ci.yml` triggers only on pushes to `main` and pull
requests targeting `main`. The AI integration lived on feature branches, so **no CI run had ever
executed the AI code** before this sprint — the "CI passed" in the Sprint 2 report refers to a `main`
run that predates the AI work. The feature's first CI execution is the PR listed in §16. Local parity
with the CI job was run in full (§6/§7), but local ≠ CI; the run status in §16 is the authoritative
answer.

## 9. Database migration verification

The AI feature adds exactly one migration, `Version20261118120000` (creates `ai_generation_usage` +
its expiry index). It was verified on a **disposable** database (`mosaiq_fresh_check`), never on the
persistent Compose volume, which was not reset.

| Scenario | Command | Actual result |
| --- | --- | --- |
| Fresh database | `doctrine:migrations:migrate` on an empty DB | `10 migrations executed, 80 sql queries`, `finished in 745.1 ms`, `[OK] Successfully migrated to Version20261118120000` |
| Fresh schema | `doctrine:schema:validate` | mapping correct, `[OK] The database schema is in sync with the mapping files` |
| Table shape | `\d ai_generation_usage` | `scope varchar(120)`, `window_key varchar(16)`, `used_count integer`, `expires_at timestamp(0) with time zone`; PK `(scope, window_key)`; index `idx_ai_generation_usage_expiry` — matches the entity mapping |
| Existing database | `doctrine:migrations:migrate` re-run | `[OK] Already at the latest version` (no-op) |
| Local dev database | `doctrine:migrations:status` | `Executed 10`, `Available 10`, `New 0` |
| Cleanup | `DROP DATABASE mosaiq_fresh_check` | dropped; the Compose volume is untouched |

**Deployment requirement:** run migrations **before** setting `AI_ENABLED=1`. Sprint 2 hit a real
`500` because the counter table did not exist yet; that failure mode is a deployment-order bug, not a
code defect, and remains a checklist item.

## 10. Browser-level verification evidence

**Outstanding for this sprint.** No browser session was run in Sprint 3, so the 20-step journey is
**not** verified at browser level. What exists is:
- Sprint 2's manual pass (recorded in `docs/sprints/ai-sprint-2-report.md`, screenshots
  `docs/screenshots/ai-preview-sprint2.png` and `ai-import-board-sprint2.png`), performed against a
  **local OpenAI-compatible stub**, not a real provider. Those artefacts predate this sprint and are
  not evidence about the current commit.
- jsdom + PHPUnit coverage of the same steps (§6/§7), which proves wiring and state, not rendering,
  focus order, scroll behaviour, real network timing or two-browser collaboration.

Mapping the requested journey to the strongest available evidence:

| Step | Evidence | Level |
| --- | --- | --- |
| 1–2 sign in, dashboard | `DashboardLifecycle`, `AiBrainstorming` flag tests | jsdom |
| 3–6 open, fill, choose language/detail, generate | `AiBrainstorming.test.tsx`, `AiBrainstormingClient.test.ts` | jsdom / unit |
| 7–11 review, edit titles/notes, remove, add | `AiBrainstorming.test.tsx` preview suites | jsdom |
| 12–13 approve, create board | `AiProposalImport.test.tsx`, `AiProposalImportTest.php` | jsdom + HTTP |
| 14–15 reopen, refresh | `AiProposalImportTest::testApprovedProposalImportsIntoAnOrdinaryBoard` | HTTP |
| 16–17 invite, collaboration updates | `testCollaboratorSeesAndEditsGeneratedNotes` | HTTP (single process) |
| 18–19 edit generated notes, timeline | same test (11 timeline entries, element patched) | HTTP |
| 20 permissions/sharing | `testNonAdministratorCannotCreateTheBoard`, `testOutsiderCannotAddGeneratedNotes` | HTTP |
| Normal non-AI board creation | `BoardApiControllerTest` + full suite | HTTP |

**A real-browser pass, ideally with a real provider, is the main outstanding verification item.**

## 11. Performance measurements

**Not measured.** No real provider access was available, so no generation latency, token cost or
throughput number exists, and none is claimed. The only timings observed are unrelated: full PHPUnit
07:56 on this Windows host, frontend build 8.04 s, fresh migration 745 ms, backend validation and
normalisation are sub-millisecond in the unit suite. **Do not quote a generation latency figure for
this release until someone measures one.**

## 12. Remaining findings

| # | Sev | Area | Finding | Status |
| --- | --- | --- | --- | --- |
| 1 | **P2** | CI | CI never ran the AI code (workflow only triggers on `main`/PRs to `main`); the feature's first CI execution is this PR | **Mitigated** — PR opened; §16 records the run |
| 2 | **P2** | Docs | `frontend/README.md` documented a **deleted** mock (`VITE_AI_BRAINSTORMING_MOCK`, `src/features/ai/mockResponse.ts`, "Simulated response" badge) and claimed there was no approval action — a deployer following it would misconfigure the feature | **Fixed** |
| 3 | **P2** | Docs | Root `README.md` had no AI section, no AI environment variables and a stale "CI NOT VERIFIED" row | **Fixed** |
| 4 | P3 | Quality | The same note could appear in two sections; the contract asks for controlled repetition | **Fixed** — validator now dedupes across sections, with a test |
| 5 | P3 | Reliability | Approval has no board-level idempotency key: a lost response after a successful import, then "approve" again, creates a **second** board with the same notes. A failure mid-import is compensated (delete notes, trash board), but that rollback is best-effort and permanent board deletion is still PLANNED | **Open, documented** in `frontend/README.md`, `docs/architecture/AI_BRAINSTORMING_CONTRACT.md` §7.6 and the Sprint 2 report. Fixing it needs a board-level `mutationId`, which is a contract change — out of scope for a hardening sprint |
| 6 | P3 | Cost | `AI_ENABLED=0`/unconfigured deployments still consume a quota slot per attempt before the provider refuses with `503`. Harmless while generation is unavailable, but it means a misconfigured deployment silently drains a user's 10-minute window | **Open, accepted** — the limiter intentionally counts attempts so retries cannot probe for capacity |
| 7 | P3 | Privacy | Provider retention/training policy is outside Mosaiq's control; the DPA/region/zero-retention choice belongs to the operator | **Open, operational** |
| 8 | P3 | Client | `AI_BRAINSTORMING_LIMITS.responseMaxBytes` (64 KiB) is declared but only the server enforces it; the client guards shape, not size | **Open, low risk** — the server rejects oversized output first |
| 9 | P3 | Privacy | `ai_generation_usage.scope` stores the user UUID (necessary for a per-user quota, not content) | **Accepted** |
| 10 | P3 | Security | A `422` echoes unknown client field names back in the `error` string (JSON, `nosniff`, `no-store`) | **Accepted, low risk** |
| 11 | P3 | Quality | No cross-language semantic duplicate detection; paraphrased duplicates survive | **Open, documented** |
| 12 | P3 | Verification | Real-provider output quality and a browser pass are unverified | **Open** — see §5, §10 |

**No P1 was found.** Generation cannot write, cannot be aimed at a board, cannot leak the key, and the
import path is the same audited board/element API used by manual editing.

## 13. Known limitations

- Generation is **opt-in and fails closed**; without `AI_ENABLED=1` plus key/base URL/model it answers
  `503 ai.provider_unavailable` and the dialog says *AI is not configured*.
- Import targets a **new** board only, and requires workspace administration (Owner/Admin). Editors can
  generate and edit but not import — the UI explains this and the API enforces it.
- One provider shape (`openai-compatible` chat completions). No second provider, no AI chat, no
  AI-generated images, no editing of existing boards — by design for this release.
- The proposal objective is not persisted; section headings are metadata only.
- No server-side transaction spans the board and its notes; rollback is compensating (§12 #5).
- The rate limiter is database-backed per deployment, **not** distributed across independent databases.
- Section/note quality and factual accuracy depend on the model; nothing is fact-checked.
- Rate-limit counters and the SSE/polling collaboration model are unchanged and carry their existing
  caveats (last-write-wins, not CRDT).

## 14. Deployment configuration

Backend (`backend/.env.local` locally, secret store in production — never committed):

| Variable | Required | Default | Note |
| --- | --- | --- | --- |
| `AI_ENABLED` | to use the feature | `0` | `0` ⇒ `503`, fail closed |
| `AI_PROVIDER` | to use the feature | `openai-compatible` | any other slug ⇒ `503` |
| `AI_BASE_URL` | to use the feature | empty | root of the API, **without** `/chat/completions` |
| `AI_MODEL` | to use the feature | empty | provider model id |
| `AI_PROVIDER_API_KEY` | to use the feature | empty | server-side only; never in the browser |
| `AI_TIMEOUT_SECONDS` | no | `20` | clamped to ≤ 20 s per attempt |
| `AI_RATE_LIMIT_PER_10_MINUTES` | no | `5` | per user |
| `AI_RATE_LIMIT_PER_HOUR` | no | `20` | per user |
| `AI_RATE_LIMIT_GLOBAL_PER_MINUTE` | no | `60` | per deployment |

Frontend: `VITE_AI_BRAINSTORMING_ENABLED=false` hides both entry points in a build (default on).
`VITE_API_BASE_URL` must point at the API when it is not on the default local port.

Deployment order: deploy the code → run `doctrine:migrations:migrate` (must include
`Version20261118120000`) → set the AI variables → verify `GET /api/boards` still works and
`POST /api/ai/brainstorming/generate` answers `503` before the key is added and `200` after.
Documented environment variables were cross-checked against `backend/config/services.yaml`,
`backend/.env` (names only) and the three READMEs; no required variable is missing from the docs.

## 15. Final recommendation

**Ready for a controlled demo — not ready for unrestricted release.**

Release criteria, honestly scored:

| Criterion | Status |
| --- | --- |
| All required CI checks pass | **Verified** — run `44319153923` on PR #5: both jobs green (§8) |
| AI generation works with the configured provider | **Not verified** — no provider access; the client, adapter, retry, timeout and validation paths are covered, the live call is not |
| Proposal validation works | **Verified** (218 backend tests) |
| Preview editing works | **Verified** (jsdom) |
| Approval creates a normal Mosaiq board | **Verified** (HTTP-level test + jsdom) |
| Collaboration works with generated elements | **Verified at HTTP level** (collaborator joins and edits); **not** in two browsers |
| Existing board functionality passes regression checks | **Verified** (full suites) |
| No unresolved P1/P2 | P1: none. P2: CI mitigated, docs fixed, no open P2 **except** the CI run itself |
| Required environment configuration is documented | **Verified** |

A controlled demo to internal users with a real provider key is reasonable now. Unrestricted release
should wait for (a) one real-provider generation inspected by a human against §5's quality questions,
and (b) one two-browser pass of §10.

## 16. PR link and CI status

- **PR:** [#5](https://github.com/AnouerChouikhgithub/Mosaiq/pull/5) — `test/ai-brainstorming-release` → `main`. **Not merged** (merging is out of scope for this sprint).
- **CI run:** [`44319153923`](https://github.com/AnouerChouikhgithub/Mosaiq/actions/runs/44319153923) — **success** in 1m48s on 2026-11-18T13:58:07Z.
  - Backend job: **pass**, 1m45s — install, validate, audit, platform reqs, container lint, migrations on a fresh PostgreSQL 16 service, schema validate, PHPStan, CS-Fixer, PHPUnit.
  - Frontend job: **pass**, 47s — `pnpm install --frozen-lockfile`, lint, build, Vitest.
- This is the **first time the AI integration has been executed by CI**. The Sprint 2 report's green CI referred to a `main` run that predates the AI work (§8).
- The only commit after the run above is the docs-only commit that fills in this section; no code, test, workflow or configuration changed after the validated run.
- CI runs on PHP 8.3 with PostgreSQL 16, so the run also confirms the feature on the declared runtime floor, not only on this machine's PHP 8.5.
