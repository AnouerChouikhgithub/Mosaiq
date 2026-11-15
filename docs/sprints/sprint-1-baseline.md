# Sprint 1 — Authentication baseline and threat assessment

**Captured:** 2026-11-15, before Sprint 1 code changes
**Branch / HEAD:** `main` at `5b993fa`, plus the uncommitted Sprint 0 working tree
**Scope:** authentication, token lifecycle, password handling, rate limiting, email verification,
account authorization, frontend session state and security configuration.

## 1. Sprint 0 gate

`docs/sprints/sprint-0-report.md` exists and the local Sprint 0 gates pass on the current tree:
backend 41 tests / 263 assertions, PHPStan clean, PHP-CS-Fixer 0/54, frontend lint/build and
7 files / 11 jsdom tests. The dev database still holds its four fixture boards; tests use
`mosaiq_test` via Doctrine's `_test` suffix.

The one unverified Sprint 0 item is the **GitHub Actions re-run** (no push was performed; run
`43715665395` failed before the PHP 8.3 lock repair). This does not block local Sprint 1 work, but
a green CI run must still be confirmed by the owner before release claims. No other Sprint 0
blocker remains.

## 2. Current authentication architecture

- **Identity:** `App\Entity\User` implements Symfony's `UserInterface` and
  `PasswordAuthenticatedUserInterface`, with a unique e-mail and a nullable `password_hash`.
- **Credentials:** opaque bearer tokens. Login/register generate 48 random bytes, base64url-encode
  them, and store only `hash('sha256', $token)` in `users.api_token_hash` (unique index). The raw
  token is returned once in the JSON response.
- **Authentication:** `ApiTokenAuthenticator` matches `^Bearer ([A-Za-z0-9_-]{32,128})$`, hashes
  the token and looks the user up by hash. A missing/invalid token returns a generic 401 through
  `start()`/`onAuthenticationFailure()`.
- **Authorization:** `BoardVoter` and `WorkspaceVoter` enforce board/workspace roles; the API
  firewall covers `/api`, with explicit public access only for login/register and the public board
  read routes.
- **Logout:** `POST /api/auth/logout` sets `api_token_hash = null`; the old token immediately fails.
- **Passwords:** registration uses `password_hash($password, PASSWORD_DEFAULT)`; login uses
  `password_verify()`. The Symfony hasher is configured but not used by the controller. Fixtures
  use the same `PASSWORD_DEFAULT` hash.
- **Sessions:** there is exactly one active token per user; issuing a new token overwrites the
  previous hash. There is no session table, device list, last-used timestamp or per-device
  revocation. Logout clears the single token.
- **Expiry:** **none.** Tokens remain valid until logout or a new login.
- **Rate limiting:** **none** on login, registration, invitations or any other auth endpoint.
- **Email verification:** **not implemented** (no token, endpoint, column or UI).
- **Password reset:** **not implemented** (the sign-in “forgot password” control only shows a demo
  toast).
- **Account endpoints:** only `GET /api/auth/me` and `POST /api/auth/logout`. There is no profile
  update, email change, password change, account deletion or session-list endpoint, so there is no
  client-supplied user id to abuse in those operations.
- **Frontend:** the token and a copy of the user profile live in `sessionStorage`; protected routes
  read `getSessionToken()` on render; `logout()` calls the backend and clears local state in a
  `finally` block. API errors are surfaced as `Error` objects with a `status` property.
- **CORS:** a development subscriber allows only loopback Vite origins (5173–5179 and 518xx);
  production origins receive no grant. There is no permissive wildcard with credentials.
- **Security headers:** no common hardening headers (`X-Content-Type-Options`, frame policy,
  referrer policy) and no explicit `Cache-Control: no-store` on auth responses.

## 3. Existing controls and test baseline

| Control | State |
| --- | --- |
| Password hashing | `PASSWORD_DEFAULT` (bcrypt/argon), verified with `password_verify` |
| Password hash in API | Not serialized (`CanvasSerializer::user` returns id/name/email/role/city/accent only) |
| Token storage | SHA-256 hash only; unique index; raw token returned once |
| Token comparison | Parameterized lookup by hash; no direct raw-token comparison |
| Logout revocation | Implemented and tested |
| Expiration | Missing |
| Rate limiting | Missing |
| Email verification | Missing |
| Password reset | Missing |
| Account self-service mutations | Missing (not a Sprint 1 requirement to invent) |
| CORS | Loopback-only development allowlist |
| Auth tests | Successful login, invalid password, invalid/missing token, logout revocation, isolated registration, duplicate-name workspaces |
| Current suite | Backend **41 tests / 263 assertions**; frontend **7 files / 11 tests**; both pass |

## 4. Confirmed weaknesses and risks

| Finding | Evidence | Risk | Proposed correction |
| --- | --- | --- | --- |
| **F1 — Tokens never expire** | `User` has only `api_token_hash`; authenticator checks existence, not age | **HIGH** — a stolen token is valid forever until logout/new login | Add `api_token_expires_at`, a configurable TTL, and an expiry check on every authenticated request |
| **F2 — No authentication rate limiting** | `AuthApiController::login/register` has no counter; unlimited attempts are accepted | **HIGH** — online brute force and credential stuffing | Add a server-side, environment-aware throttle keyed by IP plus e-mail/IP, with 429 and `Retry-After` |
| **F3 — Password policy is only “12 characters”** | Registration checks `strlen($password) < 12`; no character/quality check; no upper bound | **MEDIUM** — trivially weak passwords and oversized inputs | Central `PasswordPolicy`: 12–72 characters, at least one letter and one digit, backend error |
| **F4 — Symfony hasher configured but bypassed** | Controller calls `password_hash`/`password_verify` directly | **MEDIUM (maintainability/consistency)** — hasher upgrades and test-cost config do not apply to the actual flow | Inject `UserPasswordHasherInterface` and use it for registration and login |
| **F5 — Registration reveals whether an e-mail exists** | Duplicate e-mail returns 409 while a new account returns 201 | **MEDIUM** — account enumeration | A complete fix needs an email-verification/always-accept flow; document as BLOCKED/product decision, rate-limit registration, and keep the generic login response |
| **F6 — Auth responses lack hardening headers** | No `X-Content-Type-Options`, frame/referrer policy, or explicit no-store on auth payloads | **LOW** — browser-side defense in depth | Add a small response subscriber for API responses |
| **F7 — Token stored in `sessionStorage`** | `saveSession()` uses `sessionStorage` | **MEDIUM (known trade-off)** — XSS can read the bearer token; no CSP | Document; a storage migration needs an HttpOnly cookie/CSRF design and is not a Sprint 1 change |
| **F8 — Single token, no session inventory** | One `api_token_hash` column; new login overwrites the old token | **LOW** — no way to list/revoke individual devices; only one session can be active | Preserve the simpler model; document as a product decision, not a hidden capability |
| **F9 — No email verification / password reset** | No columns, routes, tokens or screens | **MEDIUM** — unverified e-mail addresses and no self-service recovery | No approved product rule exists; record as PLANNED/BLOCKED rather than inventing one |

### Threat assessment details

- **Brute force / credential stuffing:** confirmed F2. Login currently reveals only “Invalid email
  or password” for both unknown and wrong accounts, but attempts are unlimited.
- **Weak/compromised passwords:** confirmed F3/F4. There is no breach-list check (would require an
  external service/dependency; out of scope).
- **Token theft/replay:** confirmed F1. Tokens are bearer credentials; HTTPS is assumed at
  deployment. There is no device binding, which is normal for this model.
- **Expired/revoked tokens:** revocation works; expiration is missing (F1).
- **Post-logout access:** works for the single token (tested), but there is no multi-session
  revocation because no multi-session model exists.
- **User enumeration:** login is generic; registration is distinguishable (F5).
- **Unauthorized account modification:** no account-mutation endpoints exist; `me` derives the user
  from the token only. No confirmed issue.
- **Email verification bypass:** not applicable; verification is absent (F9).
- **Excessive auth requests:** confirmed F2.
- **Sensitive data in responses/logs:** no password/token hash serialization found; the raw token
  is returned only at issuance. No auth logging of secrets exists.
- **Header/malformed-request bypass:** the bearer regex rejects malformed headers; JSON decoding
  errors return 400. No confirmed bypass.
- **Workspace/board context changes:** voters enforce the loaded resource; Sprint 0 fixed the
  workspace board disclosure. No new issue found.

## 5. Already implemented (must not regress)

Login/registration, isolated workspace creation, invitation-bound registration, bearer
authentication on every protected endpoint, logout revocation, public board reads, board/workspace
voters, comments/canvas/timeline authorization, FR/EN/AR sign-in UI, session persistence in
`sessionStorage`, and the offline mock fallback.

## 6. Proposed Sprint 1 changes

1. Token lifecycle: `api_token_expires_at`, `AUTH_TOKEN_TTL` (default 7 days), expiry check in the
   authenticator, `issueApiToken()`/`revokeApiToken()` domain methods, logout unchanged in meaning.
2. Password handling: Symfony `UserPasswordHasherInterface` and a shared `PasswordPolicy` used by
   registration; update the sign-in hint and backend validation.
3. Rate limiting: a small Doctrine-backed throttle (`auth_attempts` table) for login and
   registration, keyed by hashed IP/e-mail identifiers, with environment-configured limits. No new
   infrastructure service and no new package (the rate-limiter component is not installed and
   adding it needs owner approval).
4. Account security: keep the existing “token-derived user” model; no new account-mutation
   endpoints. Document email verification and password reset as planned/blocked.
5. Frontend: clear the session and redirect to sign-in on a 401 from an authenticated request; show
   an “expired session” message; keep the existing design, locales and RTL.
6. Configuration: API security headers and explicit no-store for auth responses; document
   production environment/proxy requirements. No wildcard CORS or cookie changes.
7. Tests: token expiry/revocation, hashing/policy, rate limits, malformed credentials, account
   isolation, frontend login/logout/expired-state, and regression of the full suite.
