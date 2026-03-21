.. include:: /Includes.rst.txt

==============
Security audit
==============

Date
====

2026-03-15 (TYPO3 v14-only extension; transport/logging hardening)

Scope
=====

``Classes/`` directory, OAuth implementation, MCP HTTP endpoint, file harness
URL download tool, and extension settings.

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

4. Access tokens in URL query parameters.

   Status: Fixed for default configuration. Query-string tokens are **disabled**
   unless ``allowMcpTokenInQueryString`` is enabled in extension settings (off by
   default). When enabled, acceptance is logged at notice level.

5. Debug logging of MCP requests could leak secrets.

   Status: Fixed. ``Authorization``, cookies, and related sensitive headers are
   redacted; the ``token`` query parameter is redacted in logged query params.
   Implementation: ``Classes/Http/McpHttpLogRedactor.php`` (covered by unit tests).

6. Unauthenticated ``?test=auth`` probe exposed server fingerprint data.

   Status: Mitigated. The diagnostic can be disabled via
   ``enableMcpAuthHeaderDiagnostic`` (default on for the backend MCP module).
   When disabled, the endpoint returns **403** without detail. When enabled,
   the JSON response is minimal (header presence only; no ``server_software`` or
   similar fingerprint fields).

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

5. ``UploadFileFromUrl`` resolves hostnames before download (SSRF mitigation) but
   cannot fully eliminate DNS rebinding races without additional infrastructure.

   Rationale: documented limits; private/reserved resolved IPs are rejected;
   redirects/size limits apply. Extend functional coverage when changing this
   code path.

Structured MCP results
======================

The bundled ``logiscape/mcp-sdk-php`` build in this project does not expose
``outputSchema`` / structured content on ``CallToolResult``. Tools therefore
return JSON in ``TextContent``; keep schemas and descriptions accurate for client
parsing.

No issues found
===============

- SQL injection paths were closed by parameterized ``QueryBuilder`` usage and
  the constrained ``where`` parser
- Input validation remains TCA-driven in write operations
- No authentication bypass was identified for tool execution
