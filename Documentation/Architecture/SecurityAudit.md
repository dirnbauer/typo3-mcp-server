# Security Audit Report

**Date:** 2026-03-13
**Scope:** Classes/ directory, OAuth implementation, MCP endpoints

## Findings and Remediation

### Fixed

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| 1 | Access tokens stored in plain text | High | **Fixed** — SHA-256 hashed before storage |
| 2 | PKCE not enforced when challenge stored | Medium | **Fixed** — verifier now required when challenge present, uses hash_equals() |
| 3 | Exception messages leaked to HTTP clients | Medium | **Fixed** — generic error returned, details logged server-side |
| 4 | Token in URL query parameter | Medium | **Mitigated** — deprecation warning logged, will be removed in future version |

### Accepted Risks

| # | Finding | Severity | Rationale |
|---|---------|----------|-----------|
| 5 | DataHandler admin=true for workspace creation | Medium | Scoped to sys_workspace table only, gated by canUserCreateWorkspaces() |
| 6 | CSRF on OAuth consent form | Medium | OAuth consent is protected by backend session + PKCE verifier |
| 7 | Open redirect via redirect_uri | Medium | PKCE S256 prevents code theft; redirect_uri is user-controlled by design in dynamic registration |
| 8 | CORS origin reflection | Low | Required for cross-origin MCP clients; tokens are bearer-only |

### No Issues Found

- SQL injection: All queries use parameterized QueryBuilder
- Input validation: Comprehensive TCA-based validation in WriteTableTool
- Authentication bypass: No paths skip token validation for tool execution
