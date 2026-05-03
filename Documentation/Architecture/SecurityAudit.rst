.. include:: /Includes.rst.txt

==============
Security audit
==============

Date
====

2026-03-15 (TYPO3 v14-only extension; transport/logging hardening)

Scope
=====

``Classes/`` directory, OAuth implementation, MCP HTTP endpoint, file sandbox
URL download tool, and extension settings.

Findings and remediation
========================

Fixed findings
--------------

1. Access and refresh tokens were stored in plain text.

   Status: Fixed. Access and refresh tokens are now SHA-256 hashed before
   storage.

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

6. ``localUnsafeMode`` (extension config; default ``auto``) relaxes
   workspace-only writes and the file sandbox when DDEV / Development
   context is detected.

   Rationale: production never sets ``IS_DDEV_PROJECT`` or runs in the
   Development context. The pre-existing OAuth, capability manifest, and
   TYPO3 permission checks remain enforced regardless of local mode.
   Operators that want belt-and-braces gating can pin
   ``localUnsafeMode = off`` so even an accidentally-set DDEV env var
   cannot relax the safety nets.

2026-05-03 (capability manifest + DDEV-aware local mode)
========================================================

Added findings and mitigations
------------------------------

1. Capability manifest now declares per-tool required subsystems and
   outbound network policy.

   Status: Enforced. ``Configuration/Capabilities.yaml`` lists every tool
   and the subsystems it needs. ``AbstractTool::execute()`` rejects calls
   whose required subsystems are not declared. ``UploadFileFromUrl`` and
   ``RenderRecord`` consult ``CapabilityManifestService::assertHostAllowed()``
   before opening a socket. Default ``network.outbound`` ships closed at
   ``[self]``; operators opt in to public web per deployment.

2. ``RenderRecord`` SSRF gate: redirects disabled, TLS verified.

   Status: Hardened. ``CURLOPT_FOLLOWLOCATION`` set to ``false`` so a
   single 302 cannot bypass the host allowlist; ``CURLOPT_SSL_VERIFYPEER``
   stays on except in ``localUnsafeMode`` (where DDEV's self-signed certs
   are common). Initial ``assertHostAllowed`` check is the only gate the
   request must satisfy.

3. CLI ``@path`` parameter file loader.

   Status: Mitigated. ``AbstractMcpToolCommand::coerceValue`` resolves
   ``@file.json`` paths via ``realpath`` and rejects targets outside the
   TYPO3 project root. CLI is operator-trusted but this prevents accidental
   smuggling of host files (``/etc/passwd``, …) into tool params.

Accepted risks (additions)
--------------------------

1. Local-mode auto-detection treats DDEV env vars OR Development context
   as enabling.

   Rationale: the ``OR`` is intentional for ergonomics — a developer using
   DDEV in Production context (e.g. a DDEV-served preview of a production
   build) still wants live writes available. Operators who consider this
   too permissive can pin ``localUnsafeMode = off`` to require the
   stricter AND semantics.

2. Capability manifest enforcement uses ``GeneralUtility::makeInstance``
   inside ``AbstractTool::execute()``.

   Rationale: makeInstance is the documented TYPO3 entry point for early
   bootstrap calls (CLI, eID) where constructor injection isn't fully
   set up. The service has no mutable state.

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
