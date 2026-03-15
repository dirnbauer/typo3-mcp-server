.. include:: /Includes.rst.txt

==============
Security audit
==============

Date
====

2026-03-13

Scope
=====

``Classes/`` directory, OAuth implementation, and MCP endpoints

Findings and remediation
========================

Fixed findings
--------------

1. Access tokens were stored in plain text.

   Status: Fixed. Access tokens are now SHA-256 hashed before storage.

2. PKCE was not enforced consistently when a challenge was present.

   Status: Fixed. The verifier is now required and validated with constant-time
   comparison.

3. Internal exception messages leaked to HTTP clients.

   Status: Fixed. Generic responses are returned and details are logged
   server-side.

4. Access tokens in URL query parameters remained possible.

   Status: Mitigated. The behavior is deprecated and logged for future removal.

Accepted risks
--------------

1. ``DataHandler->admin = true`` is still used during workspace creation.

   Rationale: the scope is limited to workspace creation and gated by explicit
   permission checks.

2. The OAuth consent form does not add a separate CSRF token.

   Rationale: the flow already depends on a backend session and PKCE verifier.

3. Redirect handling accepts client-provided redirect URIs within the supported
   OAuth registration model.

   Rationale: the implementation now restricts unsafe remote HTTP(S) targets and
   enforces PKCE ``S256``.

4. CORS reflects allowed origins for cross-origin MCP clients.

   Rationale: bearer-token usage makes this acceptable at the current risk
   profile.

No issues found
===============

- SQL injection paths were closed by parameterized ``QueryBuilder`` usage and
  the constrained ``where`` parser
- Input validation remains TCA-driven in write operations
- No authentication bypass was identified for tool execution
