# TYPO3 Security Audit

Date: 2026-03-13
Scope: `typo3-security` + `security-audit` pass for TYPO3 v13/v14

## Summary

Status: Critical and high-impact findings remediated.

Verified command results:

- `ddev exec composer php-cs-fixer`: passes
- `ddev exec composer phpstan`: passes
- `ddev exec composer test`: passes with `OK (412 tests, 2156 assertions)`

## Remediated findings

### 1. Direct-access tokens were stored inconsistently

Severity: High

- `OAuthService::createDirectAccessToken()` stored direct tokens differently from the
  authorization-code flow.
- Result: direct tokens were not aligned with the validation model and created an
  insecure/incorrect storage path.

Resolution:

- Direct tokens are now stored as SHA-256 hashes, consistent with the rest of the
  OAuth token flow.
- Backend UX was adjusted so existing stored tokens are treated as non-recoverable
  secrets: their full value is only shown at creation time.

### 2. `ReadTableTool.where` accepted raw SQL fragments

Severity: High

- `ReadTableTool` passed the `where` parameter directly into `QueryBuilder::andWhere()`.
- A keyword blacklist blocked only a few mutation statements and still left room for
  unsafe query shaping.

Resolution:

- Replaced raw SQL passthrough with a constrained parser that only allows:
  - field comparisons against literals
  - `AND` / `OR`
  - `LIKE`
  - `IN (...)`
  - `IS NULL` / `IS NOT NULL`
- Field names are now checked against filterable table fields before being used.
- Unsupported syntax, comments, statement separators, and dangerous keywords are rejected.

### 3. OAuth continuation cookie lacked integrity protection

Severity: Medium

- The login continuation cookie used base64-encoded JSON without a signature.
- Result: a forged client-side cookie could alter OAuth continuation parameters.

Resolution:

- The cookie is now signed with TYPO3 `HashService`.
- Middleware verifies the signature before continuing the OAuth flow.

### 4. OAuth authorization flow accepted unsafe redirect and PKCE combinations

Severity: Medium

- The flow accepted `plain` PKCE and arbitrary `redirect_uri` values.
- Result: authorization codes could be redirected to attacker-controlled HTTP(S)
  callbacks more easily than intended.

Resolution:

- Metadata now advertises only `S256`.
- Authorization-code creation and exchange now enforce `S256`.
- Redirect URIs are validated before use:
  - loopback HTTP(S) callbacks are allowed
  - custom URI schemes are allowed
  - arbitrary remote HTTP(S) hosts are rejected

### 5. Auth debug endpoint echoed Authorization headers

Severity: Medium

- The auth test endpoint returned received auth-header values in the response body.
- Result: bearer tokens could be reflected into responses and downstream logs.

Resolution:

- The endpoint now reports only whether an auth header was detected, never the header value itself.

### 6. Internal exception messages leaked to clients

Severity: Low

- Several OAuth/backend-module error paths returned raw exception messages.

Resolution:

- Token and backend-module actions now return generic client-facing failure messages.
- The token endpoint logs server-side details and returns a generic OAuth error response.

## Residual hardening opportunities

These are not blocking for this pass, but remain worthwhile follow-ups:

- Add rate limiting to token creation and OAuth endpoints
- Review CORS policy for credentialed requests and narrow allowed origins where feasible
- Replace the test bootstrap’s fixed admin password with an explicit environment variable
- Add security-focused functional tests around redirect URI validation and cookie tamper detection

## Assessment

Status: Secure baseline improved, with the highest-value issues addressed in code and verified by QA.
