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

2026-05-03 (typo3-security skill pass — RFC 9700 alignment)
===========================================================

Fixed findings
--------------

1. PKCE was conditionally required (``$pkce !== ''`` gate). RFC 9700 §2.1.1
   makes it mandatory.

   Status: Fixed. ``OAuthAuthorizeEndpoint::handle()`` and
   ``handleApproval()`` now reject any authorization-code request without
   a ``code_challenge``. Method must be ``S256`` (no ``plain``).

2. Plaintext token fallback (``token_version=0``).

   Status: Removed. ``OAuthService::validateToken()`` no longer falls back
   to plaintext column lookup. Any pre-migration tokens are rejected and
   the affected MCP client is forced to re-authenticate via the install
   button — issuing a freshly hashed token.

3. HTML-attribute escaping in the OAuth consent page used the
   PHP-version-default flag set, which historically did not include
   ``ENT_QUOTES``.

   Status: Hardened. All escaped values now use
   ``ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5`` so single quotes and
   malformed UTF-8 cannot break the template.

4. ``/mcp`` and ``/mcp_oauth/*`` responses lacked browser-defense headers.

   Status: Fixed. ``CorsHeadersTrait::addSecurityHeaders()`` stamps
   ``X-Content-Type-Options: nosniff``, ``X-Frame-Options: DENY``,
   ``Referrer-Policy: no-referrer``, ``Cache-Control: no-store``, and
   ``Pragma: no-cache``. Wired into ``McpEndpoint`` (success + error path).

5. ``OAuthTokenEndpoint`` debug log used a 20-char token prefix (80 bits).

   Status: Reduced to 8-char prefix (32 bits) — enough for log
   correlation, not enough to reconstruct the full 64-char token.

6. ``WriteFileTool`` default ``textfile_ext`` accepted SVG, which can
   carry inline ``<script>`` and trigger stored XSS.

   Status: SVG removed from the default. Operators who need SVG must
   add it to ``$TYPO3_CONF_VARS[SYS][textfile_ext]`` and pipe through
   ``TYPO3\\CMS\\Core\\Resource\\Security\\SvgSanitizer``.

7. ``RenderRecord`` cURL fetched without resolving the host to an IP.

   Status: Fixed. Outside ``localUnsafeMode``, the host is resolved with
   ``gethostbynamel()`` and any private/reserved address aborts the
   request — same protection model as ``UploadFileFromUrl``.

Accepted risks (added)
----------------------

1. OAuth authorization code is bound to the client name and redirect URI
   only loosely (the exchange call does not re-verify the redirect URI).
   Currently a single-client deployment with a locked-down redirect, so
   the impact is theoretical — flagged for a focused PR that adds the
   per-RFC-9700 §4.1.3 strict comparison.

2. Refresh-token rotation does not invalidate the previous refresh token
   (replay detection / family revocation per RFC 9700 §4.14). Tokens are
   single-use within their TTL and rotated, but a captured token used
   twice goes undetected. To be addressed in a focused refresh-rotation
   PR.

3. No rate limiting on ``/mcp``, ``/mcp_oauth/token``, or
   ``/mcp_oauth/authorize``. Bearer-token brute force is unbounded
   (32-byte random tokens make this impractical, but a defense-in-depth
   limiter is appropriate). Recommend adding an upstream HTTP-tier limit
   (nginx ``limit_req``, Apache ``mod_qos``, or a TYPO3 PSR-15
   middleware that calls ``Symfony\\Component\\RateLimiter``).

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
