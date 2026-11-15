# Sprint 1 — Authentication and Security Hardening

**Date:** 2026-11-15  
**Scope:** harden the existing authentication implementation without redesigning the architecture.  
**Result:** **IMPLEMENTED** for token lifecycle, password handling, credential throttling, API
response headers and frontend expired-session behaviour. Email verification and password reset are
**BLOCKED / PLANNED** because no product rule or dependency was approved. The product is still not
production-ready.

The pre-change threat assessment is in [`sprint-1-baseline.md`](sprint-1-baseline.md). This report
records what changed, what was measured, and what remains.

## A. Sprint result

The Sprint 0 local gate passed before changes: backend 41 tests / 263 assertions, PHPStan clean,
PHP-CS-Fixer 0/54, frontend 7 files / 11 tests, fresh five-migration sequence and schema validation.
Sprint 0's GitHub Actions run remains **NOT VERIFIED** because no push was authorised.

Sprint 1 delivers:

- opaque bearer tokens that expire after `AUTH_TOKEN_TTL` (default 7 days), are stored only as
  SHA-256 hashes, rotate on login, and are revoked on logout;
- Symfony's configured password hasher for registration and login, with a shared 12–72 character
  policy requiring at least one letter and one digit;
- database-backed throttling for login (per email + IP and per IP) and registration (per IP), with
  `429` and `Retry-After` responses;
- server-side security headers and `Cache-Control: no-store` on auth API responses;
- a frontend 401 handler that clears the session, redirects to `/sign-in?expired=1` and explains
  the expiry in FR/EN/AR;
- 10 backend security regression tests and 6 frontend session/validation tests, plus documentation
  and an additive migration.

**Not delivered:** email verification, password reset, password change, multi-device sessions,
breach-list password checks, CSP, and distributed/Redis rate limiting. Each is recorded below rather
than invented.

## B. Findings and disposition

| # | Finding | Severity | Disposition |
| --- | --- | --- | --- |
| F1 | Tokens had no expiry and remained valid indefinitely after theft | High | **FIXED** — expiry column, TTL, authenticator check |
| F2 | Login used `password_verify` directly; registration used `password_hash` directly; policy was only `strlen >= 12` | Medium | **FIXED** — `UserPasswordHasherInterface` + `PasswordPolicy` |
| F3 | Public login/registration had no throttling or lockout | High | **MITIGATED** — DB-backed per-key/IP throttle; distributed limiting remains PLANNED |
| F4 | Token issuance overwrote the single stored hash, silently signing out other devices | Medium | **DOCUMENTED + ROTATION VERIFIED** — single active session retained by design; multi-session is PLANNED |
| F5 | A 401 left the client in a signed-in shell with no explanation | Medium | **FIXED** — clear session, redirect, translated message |
| F6 | Auth responses had no explicit anti-caching or browser-hardening headers | Low/Medium | **FIXED** — `nosniff`, `no-referrer`, `DENY`, `no-store` |
| F7 | Emails are not verified; no password reset; only a "forgot" demo link exists | High (product) | **BLOCKED / PLANNED** — needs an approved token/email workflow |
| F8 | Tokens live in `sessionStorage` and there is no CSP | Medium | **PARTIALLY MITIGATED** — expiry reduces the theft window; CSP and cookie/CSRF redesign remain PLANNED |
| F9 | No account-mutation endpoints exist, so password-change re-authentication cannot be tested | Low today | **NO ENDPOINT ADDED**; `me` identity is token-derived and tested |
| F10 | Rate-limit state is in PostgreSQL, not Redis, and registration is keyed by IP only | Low/Medium | **ACCEPTED** — no dependency approval; NAT users can share the registration budget |
| F11 | Registration returns `409` for an existing email, which can enumerate accounts | Low | **UNCHANGED** — existing product/UX trade-off; login remains generic |
| F12 | Existing tokens have a null expiry after migration and become invalid | Operational | **DOCUMENTED** — users must sign in once after deploy |

## C. Changes

### Backend

| File | Change |
| --- | --- |
| `backend/src/Entity/User.php` | `apiTokenExpiresAt`; `issueApiToken()`, `revokeApiToken()`, `hasValidApiToken()` replace `setApiTokenHash()` |
| `backend/src/Entity/AuthAttempt.php` | New throttling record (UUID, action, key hash, timestamp, indexes) |
| `backend/src/Repository/AuthAttemptRepository.php` | Count within window, prune old rows, clear a key |
| `backend/src/Security/AuthThrottle.php` | Login email+IP / IP limits, registration IP limit, success clearing, pruning; env-configurable and disableable |
| `backend/src/Security/PasswordPolicy.php` | Shared 12–72 byte policy with at least one letter and one digit |
| `backend/src/Security/ApiTokenAuthenticator.php` | Rejects missing, expired and revoked tokens |
| `backend/src/Controller/AuthApiController.php` | Symfony hasher, policy, throttling, `429` + `Retry-After`, expiring token issuance, `expiresAt` response field |
| `backend/src/EventListener/SecurityHeadersSubscriber.php` | API security headers and auth `no-store` |
| `backend/migrations/Version20261115120000.php` | Additive `api_token_expires_at` column and `auth_attempts` table/indexes |
| `backend/config/services.yaml` | Injects the TTL and throttle configuration |
| `backend/.env`, `backend/.env.test` | Default and test limits; test limits make throttle tests deterministic |
| `backend/tests/Controller/ApiFunctionalTestCase.php` | Issues expiring test tokens |
| `backend/tests/Controller/AuthSecurityTest.php` | 10 tests / 62 assertions covering the new behaviour |

### Frontend

| File | Change |
| --- | --- |
| `frontend/src/api/client.ts` | `SESSION_EXPIRED_EVENT`; authenticated 401 clears the session and dispatches; login/registration 401s are credential errors; logout clears locally even if the API is unreachable |
| `frontend/src/App.tsx` | Listens for the expiry event and replaces the route with `/sign-in?expired=1` |
| `frontend/src/screens/SignInScreen.tsx` | Expired-session notice; signup policy check for 12–72 characters, one letter and one digit; hint only on signup |
| `frontend/src/i18n/dictionary.ts` | FR/EN/AR `signin.sessionExpired`; updated password hints/errors |
| `frontend/src/tests/Session.test.tsx` | 6 tests: 401 clearing, protected-route redirect/message, offline logout, login success/failure, registration validation |

### Documentation

- `docs/sprints/sprint-1-baseline.md` and this report.
- `README.md`, `AGENTS.md`, `DEVELOPMENT_ROADMAP.md`, `NEXT-AGENT-PROMPT.md`,
  `frontend/README.md`, `docs/technical-audit.md` and `docs/repair-report.md` updated with the new
  auth model, limits and remaining risks.

No dependency was added. `symfony/rate-limiter` remains uninstalled; throttling uses the existing
Doctrine/PostgreSQL stack. No production code path was disabled.

## D. Architecture after Sprint 1

```text
POST /api/auth/register
  -> PasswordPolicy (12–72, letter + digit)
  -> AuthThrottle (IP registration window)
  -> UserPasswordHasherInterface
  -> issueApiToken(random 48 bytes, SHA-256 at rest, expiresAt)

POST /api/auth/login
  -> AuthThrottle (email+IP and IP windows)
  -> UserPasswordHasherInterface::isPasswordValid
  -> clear failures on success
  -> issueApiToken(...)  (rotates the single active session)

Authorization: Bearer <token>
  -> ApiTokenAuthenticator: hash lookup + expiry/revocation check
  -> User -> voters/controllers

POST /api/auth/logout
  -> revokeApiToken() + flush

Any authenticated 401 in the browser
  -> clear sessionStorage
  -> mosaiq:session-expired
  -> /sign-in?expired=1 (translated notice)
```

Token storage remains `sessionStorage`. The API remains stateless apart from the single token hash
and optional expiry on `users`, plus short-lived rows in `auth_attempts`. No cookie, JWT, refresh
token, CSRF token or session table was introduced.

## E. Verification

All commands were run on 2026-11-15 in the local workspace. Nothing was pushed or deployed.

| Check | Command | Result |
| --- | --- | --- |
| Backend tests | `cd backend && composer test` | **PASS** — 51 tests, 325 assertions (was 41/263) |
| New security tests | `cd backend && php bin/phpunit tests/Controller/AuthSecurityTest.php` | **PASS** — 10 tests, 62 assertions |
| PHPStan | `cd backend && composer analyse` | **PASS** — 0 errors, level 5 |
| PHP-CS-Fixer | `cd backend && composer cs:check` | **PASS** — 0 of 60 files need fixing |
| Composer validation | `composer validate --no-check-publish` | **PASS** |
| Dependency audit | `composer audit --locked` | **PASS** — no advisories |
| Container wiring | `php bin/console lint:container` | **PASS** |
| Dev schema | `php bin/console doctrine:schema:validate` | **PASS** — in sync |
| Dev migration | `php bin/console doctrine:migrations:migrate --no-interaction` | **PASS** — `Version20261115120000`, 4 SQL queries; 4-board fixture data preserved |
| Fresh DB up/down/up | disposable `mosaiq_sprint1_check`, six migrations, rollback and re-apply | **PASS** — schema validated after both applies; database dropped afterwards |
| CI-style test DB | drop/create `mosaiq_test`, migrate, schema validate, `composer test` | **PASS** — six migrations, 51 tests / 325 assertions |
| PHP 8.3 syntax | Docker `php:8.3-cli`, `php -l` over `src`, `tests`, `migrations` | **PASS** |
| Frontend lint | `cd frontend && pnpm run lint` | **PASS** |
| Frontend build | `cd frontend && pnpm run build` | **PASS** — 2,207 modules; `index` 143.35 kB, `ui` 162.99 kB, `vendor` 247.30 kB |
| Frontend tests | `cd frontend && pnpm test` | **PASS** — 8 files, 17 tests (was 7/11) |
| GitHub Actions | — | **NOT VERIFIED** — no push; Sprint 0 run `43715665395` still failed before its lock fix |
| Browser click-through of the expired flow | — | **NOT RUN** — covered by jsdom routing/UI tests; no real-browser pass this sprint |

The rate-limit tests use `.env.test` limits (login 3 per email+IP, registration 5 per IP) and assert
`401` for rejected credentials, `429` plus `Retry-After` once exhausted, and success-clears-failures.

## F. Remaining issues and blocked work

1. **Email verification is not implemented.** There is no verified-email flag, token, route or mail
   template. Invitations are bound to the invited address, but registration itself remains
   unverified. Requires an approved product rule and mail-token design.
2. **Password reset is not implemented.** The "forgot password" link is still a demo. A reset flow
   needs single-use, expiring, hashed tokens, mail delivery, generic responses and throttling.
3. **Password change / account deletion do not exist.** No endpoint was invented. Any future
   sensitive account mutation must re-authenticate the current password and revoke other sessions.
4. **Single active session remains.** Logging in rotates the token and invalidates the previous one.
   Multi-device support needs a token table (or equivalent) and explicit product approval.
5. **Rate limiting is database-backed, not distributed.** It works across processes sharing the
   database but adds a write per attempt; `auth_attempts` rows are pruned on write. A Redis/
   `symfony/rate-limiter` design remains PLANNED and needs dependency approval.
6. **Registration throttling is IP-only.** A shared NAT can exhaust the hourly budget. Defaults are
   10 registrations/hour and 20 login failures/IP/15 min; tune per deployment.
7. **Proxy/IP caveat.** The app uses `Request::getClientIp()` without trusted proxies. Behind a
   reverse proxy every request appears to come from the proxy unless `SYMFONY_TRUSTED_PROXIES` is
   configured, which would collapse all clients into one throttle bucket.
8. **Token storage remains `sessionStorage`; no CSP.** Expiry reduces the stolen-token window, but
   XSS can still read the token. An HttpOnly-cookie + CSRF redesign or a strict CSP is PLANNED.
9. **Existing tokens are invalidated by the migration** because `api_token_expires_at` is null for
   rows created before Sprint 1. Users must sign in once after deployment.
10. **Account enumeration on registration remains** (`409` for an existing email). Login stays
    generic ("Invalid email or password").
11. **No real-browser verification** of the expiry redirect/message, and no GitHub Actions green run.
12. **No breach-list check** and no password history. The policy is deliberately modest.

## G. Sprint 2 handoff

1. Verify the GitHub Actions workflow after an authorised push; confirm the sixth migration and the
   51/325 suite run on PHP 8.3.
2. Decide and approve email verification and password reset. If approved, implement hashed,
   single-use, expiring tokens, generic responses, mail delivery and per-IP/per-account throttling;
   add tests for expiry, reuse, wrong account and enumeration resistance.
3. Decide whether multi-device sessions are required. If yes, replace the single hash with a token
   table (id, user, hash, created/expires/last-used/revoked) and test revocation per device.
4. Harden the browser token model: strict CSP or HttpOnly cookie + CSRF, with an explicit product
   and deployment decision. Keep `sessionStorage` documented until then.
5. Configure `SYMFONY_TRUSTED_PROXIES` for any proxied deployment and re-test per-IP limits.
6. Add real-browser coverage for the 401 redirect and the expired notice; consider Playwright only
   with approval.
7. Tune `AUTH_*` limits for the expected traffic and add an operational cleanup/monitoring plan for
   `auth_attempts`.
8. Do not claim production readiness: email ownership, password recovery, distributed throttling and
   CSP remain unresolved.

## H. Git status

No commit, push, deployment, database reset or volume deletion was performed. The worktree remains
uncommitted on `main` at `5b993fa`, on top of the Sprint 0 changes. The dev database received only
the additive Sprint 1 migration; its four boards were preserved. The disposable
`mosaiq_sprint1_check` database was dropped after verification. `mosaiq_test` was recreated for the
CI-style run.
