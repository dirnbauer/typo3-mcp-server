.. include:: /Includes.rst.txt

==============
Security audit
==============

Date
====

2026-07-11 (TYPO3 v14-only extension; protocol and full-feature hardening)

Scope
=====

``Classes/`` directory, OAuth implementation, MCP HTTP endpoint, file sandbox,
outbound URL tools, and extension settings.

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

   Status: Fixed. Query-string bearer authentication was removed. ``/mcp``
   accepts tokens only from the ``Authorization`` header, and an obsolete
   ``allowMcpTokenInQueryString`` value is ignored.

5. Debug logging of MCP requests could leak secrets.

   Status: Fixed. ``Authorization``, cookies, and related sensitive headers are
   redacted. Token-shaped query values are still redacted as defense in depth,
   even though they cannot authenticate a request.
   Implementation: ``Classes/Http/McpHttpLogRedactor.php`` (covered by unit tests).

6. Unauthenticated ``?test=auth`` probe exposed server fingerprint data.

   Status: Mitigated. The diagnostic can be disabled via
   ``enableMcpAuthHeaderDiagnostic``. The default extension configuration is
   off; operators can enable it when they want the backend module connection
   check to verify whether a proxy strips the ``Authorization`` header. When
   disabled, the endpoint returns **403** without detail. When enabled, the
   JSON response is minimal (header presence only; no ``server_software`` or
   similar fingerprint fields).

Accepted risks
--------------

1. ``DataHandler->admin = true`` is still used during workspace creation.

   Rationale: the scope is limited to workspace creation and gated by explicit
   permission checks.

2. Resolved (2026-07-11): the OAuth consent form now uses TYPO3 backend form
   protection. Approval POSTs without the session-bound token, or with a forged
   or expired token, fail before an authorization code is created.

3. Redirect handling accepts client-provided redirect URIs within the supported
   OAuth registration model.

   Rationale: the implementation now restricts unsafe remote HTTP(S) targets and
   enforces PKCE ``S256``.

4. CORS permits only exact same-origin or configured origins.

   Rationale: malformed, ``null``, and unlisted origins are rejected before
   authentication. Operators must explicitly list each additional HTTP(S)
   origin in ``allowedOrigins``.

5. ``localUnsafeMode`` (extension config or User TSconfig; default
   ``auto``) relaxes workspace-only writes, the workspace-capable table
   requirement, outbound HTTP, and the file sandbox when DDEV /
   Development context is detected.

   Rationale: production never sets ``IS_DDEV_PROJECT`` or runs in the
   Development context. OAuth, TYPO3 permission checks, and the manifest's
   per-tool subsystem checks remain enforced regardless of local mode; the
   manifest's outbound allowlist is intentionally relaxed only for local
   development ergonomics.
   Operators that want belt-and-braces gating can pin
   ``localUnsafeMode = off`` or set ``mcpServer.strictSandbox`` so even
   an accidentally-set DDEV env var cannot relax the safety nets.

2026-05-03 (local-mode UX fix: outbound HTTP relaxes too)
==========================================================

Issue
-----

Local-mode (DDEV / ``localUnsafeMode=on``) relaxed the workspace-only
writes and the file sandbox, but did not relax the capability
manifest's ``network.outbound`` allowlist or the SSRF private-IP
filter inside ``UploadFileFromUrl``. Developers reported that fetching
images from Unsplash failed in DDEV with "no permission to network this
resource", contradicting the README claim that "everything is allowed
in DDEV".

Fix
---

``LocalModeService::allowsUnrestrictedOutbound()`` added; both production
gates are deliberately bypassed when it returns true:

- ``CapabilityManifestService::assertUrlAllowed()`` returns immediately.
- ``UploadFileFromUrl``, ``ImportFromUrl``, ``RenderRecord``, and optional x402
  facilitator verification skip ``OutboundUrlGuardService`` so local/private
  development hosts remain reachable. Redirects and response bounds remain in
  force.

Production behavior is unchanged: with the default
``localUnsafeMode=auto`` outside DDEV / Development context the new
method returns false and the strict gates remain active.

2026-05-03 (security-audit skill pass — OWASP / CWE)
=====================================================

Fixed findings
--------------

1. **OWASP A01 / IDOR** — ``ReadTableTool`` did not validate ``pid``/``uid``
   against the BE user's webmount, only against table-level access via
   ``TableAccessService``. A non-admin token holder could read records on
   pages outside their DB mount.

   Status: Fixed. ``ReadTableTool::ensurePageAccess()`` mirrors
   ``WriteTableTool::validatePageAccess()`` (admins pass through, others
   need ``isInWebMount(pid)``). UID-only lookups also post-filter the
   result set via ``filterRecordsByWebMount()`` so cross-page reads via
   ``uid`` are equally gated.

2. **OWASP A04 / Mass-assignment** — ``BulkWriteTool`` and ``WriteTableTool``
   accepted ``t3ver_*``, ``deleted``, ``tstamp``, ``crdate``, ``cruser_id``,
   ``perms_*`` in the data array. DataHandler sanitized most, but
   defense-in-depth at the MCP layer is appropriate.

   Status: Fixed. Both tools reject these system columns up-front with a
   structured error rather than letting the value silently disappear.

3. **OWASP A05 / misconfig** — ``enableMcpAuthHeaderDiagnostic`` defaulted
   to ON. The ``?test=auth`` probe is unauthenticated.

   Status: Default flipped to OFF in ``ext_conf_template.txt``. Operators
   who want the backend module connection-check indicator turn it on
   explicitly.

4. **OWASP API4 / resource consumption** — ``GetPageTreeTool`` accepted
   any depth (``depth=10000`` would scale linearly).

   Status: Bounded to 10 with ``max(1, min(10, $depth))``.

5. **OWASP A09 / logging gap** — ``OAuthService`` returned ``null`` silently
   on PKCE mismatch, missing/expired auth code, invalid bearer token,
   and refresh-token rotation failure. Production log monitoring had no
   way to detect attack patterns.

   Status: Fixed. Each branch now logs at warning level with ``client_ip``
   context (and PKCE method on the wrong-method branch). ``OAuthService``
   gained an optional ``LoggerInterface`` constructor parameter (DI
   provides one; existing callers that pass none get a ``NullLogger``).

6. **CWE-93 / header injection** — ``validateRedirectUri()`` accepted
   non-http custom schemes (e.g. ``cursor://``, ``vscode://``) without
   filtering CR/LF/NUL bytes. The validated string was later
   concatenated into a ``Location:`` response header.

   Status: Fixed. ``preg_match('/[\\r\\n\\0]/', $url)`` rejects any URL
   carrying line-break / NUL bytes before parse_url is even called.

Accepted risks (added)
----------------------

1. Cookie ``Secure`` flag drops behind a TLS-terminating reverse proxy
   when ``getUri()->getScheme()`` returns ``http``. Operators behind a
   proxy must set ``X-Forwarded-Proto`` and configure trusted proxies in
   TYPO3, or the OAuth-state cookie is sent in the clear over the
   internal hop. Documented as an operator-side concern.

2. Resolved (2026-06-16): the fork no longer ships to TER. The
   ``Build/build-ter.sh`` packaging script and the ``Release to TER``
   workflow were removed, so there is no bundled
   ``Resources/Private/PHP/vendor`` payload to sign and no build-to-build
   TER zip. Distribution is via Composer/Git, which resolves dependencies
   from the committed ``composer.lock``.

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

7. Outbound tools did not share complete IPv4, IPv6, and DNS-rebinding
   protection.

   Status: Fixed. Outside ``localUnsafeMode``, ``UploadFileFromUrl``,
   ``ImportFromUrl``, ``RenderRecord``, and optional x402 facilitator
   verification all use
   ``OutboundUrlGuardService``. It resolves every A and AAAA address, rejects
   the target when any address is private or reserved, and pins cURL to a
   validated address with ``CURLOPT_RESOLVE``. This closes the validation-to-
   connection DNS-rebinding window. Redirects remain disabled so every new
   authority must pass the complete policy again.

Accepted risks (added)
----------------------

1. Resolved (2026-07-11): authorization codes are hashed, consumed with an
   atomic one-time delete, and exactly bound to ``client_id``, redirect URI,
   resource indicator, and scope. Token exchange revalidates every binding
   together with the mandatory ``S256`` verifier.

2. Resolved (2026-07-11): refresh rotation transactionally records every used
   token hash in ``tx_mcpserver_oauth_refresh_replay`` before a compare-and-
   swap update. Stale-history matches, unique-key conflicts from concurrent
   double use, and CAS failure revoke the active family. An absolute family
   expiry prevents indefinite rotation. Replay-history rows are retained only
   until that family expiry and then removed by normal OAuth cleanup; after
   expiry there is no active successor token to protect.

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
   whose required subsystems are not declared. ``UploadFileFromUrl``,
   ``ImportFromUrl``, ``RenderRecord``, and optional x402 facilitator
   verification consult
   ``CapabilityManifestService::assertUrlAllowed()`` before opening a socket.
   The URL-aware check enforces both the declared host and protocol. Default
   ``network.outbound`` ships closed at ``[self]`` for direct PHP HTTP;
   operators opt in to public web per deployment.

2. Shared outbound SSRF gate: redirects disabled and DNS pinned.

   Status: Hardened. All direct outbound paths reject redirects. The initial URL
   must satisfy the manifest protocol/host rule and the shared A/AAAA
   public-address check, then cURL connects to the validated address through
   ``CURLOPT_RESOLVE``. ``RenderRecord`` also keeps TLS peer and hostname
   verification enabled except in ``localUnsafeMode``, where DDEV self-signed
   certificates are common.

3. Subprocess and scheduler network effects bypass the PHP URL guard.

   Status: Explicitly bounded. ``InstallExtension`` and
   ``ApplyShadcnPreset`` are admin-only and now dev-site-only; their Composer
   or JavaScript package-manager subprocesses require the manifest's
   ``network:package-manager`` subsystem. ``SolrIndexQueue`` selects only a
   pre-existing task identified as Solr-related and invokes it by UID, but the
   task may contact its configured Solr host; the tool therefore declares
   ``scheduler:task`` and ``network:scheduler``. These effects are documented
   exceptions, not covered by ``network.outbound`` or DNS pinning.

4. The opt-in ``abilities`` REST API inherited unrelated API Core controllers.

   Status: Fixed. An outer policy middleware honors
   ``activateAbilitiesApi``, allows only ability list/describe/run and the two
   public documentation paths, and returns 404 for inherited auth, demo,
   health, and MCP routes. It blocks API Core's POST MCP handler independently
   of the global MCP switch. The same layer filters OpenAPI and adds exact
   registry-derived contracts for the five ``typo3-mcp/*`` abilities.

5. CLI ``@path`` parameter file loader.

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

2026-07-11 (dual-era transport and origin hardening)
=====================================================

Fixed findings
--------------

1. Browser origins were not rejected early and consistently.

   Status: Fixed. Exact same-origin or ``allowedOrigins`` matching now runs
   before bearer authentication. Malformed, ``null``, and unlisted origins
   return 403.

2. MCP request-metadata headers needed an explicit CORS allowlist.

   Status: Fixed. ``MCP-Protocol-Version``, ``Mcp-Method``, ``Mcp-Name``,
   validated ``Mcp-Param-*`` names, and required content headers are exposed
   without allowing arbitrary request headers.

3. A stable protocol session could be confused with modern request state.

   Status: Fixed by the SDK's dual-era handling. ``2026-07-28`` requests use
   ephemeral contexts and never receive ``Mcp-Session-Id``; stable requests
   retain the legacy session contract.

4. Manifest inventory could drift from executable tools and commands.

   Status: Fixed. Consistency tests compare all registered native tools,
   ``mcp:*`` Symfony commands, skills, and owned database tables with
   ``Configuration/Capabilities.yaml``.

5. Raw request-target logging could preserve rejected query-string secrets.

   Status: Fixed. MCP request logs record only the URI path and separately
   parsed query parameters after recursive secret-key redaction. The raw
   request target, which contains the unredacted query string, is never logged.

6. OAuth resource identifiers accepted unspecified URI schemes.

   Status: Fixed. Resource identifiers and request-derived MCP resource URLs
   must be absolute HTTP(S) URIs without user information or fragments. Scheme,
   host, IPv6 brackets, and default ports are normalized before binding and
   comparison.

7. Outbound URL checks differed between importing, uploading, and rendering.

   Status: Fixed. ``UploadFileFromUrl``, ``ImportFromUrl``, ``RenderRecord``,
   and optional x402 facilitator verification now apply the same manifest
   allowlist, HTTP(S)-only URL validation, full A/AAAA private-address
   rejection, DNS pinning, and no-redirect policy outside explicitly unsafe
   local mode.

8. Authorization responses were not bound to an authorization-server issuer.

   Status: Fixed. Successful authorization responses include the RFC 9207
   ``iss`` parameter, the discovery document advertises support, and tests
   require the value to match the HTTPS public server base. Insecure
   non-loopback HTTP bases cannot be emitted as issuers.

9. OAuth and outbound HTTP request bodies could consume unbounded memory.

   Status: Fixed. Dynamic registration and token requests are limited to
   64 KiB, MCP requests to 25 MiB, ``ImportFromUrl`` and ``RenderRecord`` to
   5 MiB, ``UploadFileFromUrl`` streams to a 20 MiB temporary file, and x402
   facilitator responses are capped at 64 KiB. The outbound paths reject
   excess data while streaming rather than buffering it first.

10. Backend connection diagnostics trusted the request-derived host and
    followed redirects without consulting the outbound manifest.

    Status: Fixed. ``DiagnosticHttpClient`` checks every final probe URL with
    ``CapabilityManifestService::assertUrlAllowed()`` before constructing the
    request or opening a socket. Denied targets remain a normal "unreachable"
    diagnostic result, and redirects are disabled so an allowed self URL cannot
    escape to an unvalidated internal destination.

.. _security-audit-page-file-authorization:

2026-07-11 (page-tree and file-mount authorization)
===================================================

Fixed findings
--------------

1. Page-oriented read tools trusted table permission without consistently
   enforcing the backend user's page-tree entry points.

   Status: Fixed. ``PageAccessService`` resolves live records, workspace
   versions, and page translations before requiring both Core ``PAGE_SHOW``
   permission and ``isInWebMount()``. ``GetPage``, ``GetPageTree``,
   ``ContentAudit``, ``GetPreviewUrl``, ``RenderRecord``, and
   ``WorkspaceReview`` use the shared guard. Per-table read permission is
   checked before related rows are queried.

2. File search queries could return ``sys_file`` rows, metadata, counts, and
   thumbnails from folders outside a non-admin user's FAL mounts.

   Status: Fixed. ``SearchFile`` and ``SearchMedia`` add TYPO3 Core's
   ``FolderMountsRestriction`` to every result and count query. A user with no
   file mount receives an empty search result rather than an unrestricted one.

3. Storage discovery and direct FAL readers did not share one explicit mount
   boundary.

   Status: Fixed. ``ListStorages`` uses ``BE_USER->getFileStorages()``.
   ``FileAccessService`` requires ``sys_file`` read permission, an
   authenticated backend user, a user-visible storage, Core
   ``ResourceStorage::isWithinFileMountBoundaries()``, and a matching file
   mount before metadata or folder output is returned. This remains enforced
   when ``localUnsafeMode`` relaxes the separate MCP file sandbox.

4. Multi-mount behavior lacked end-to-end regression coverage.

   Status: Fixed. Functional tests use disjoint page branches and sibling FAL
   folders to prove allowed reads still work while page details, trees, audit
   results, previews, renders, workspace diffs, searches, counts, metadata,
   browsers, and storage listings cannot cross the authenticated mounts.

Structured MCP results
======================

The v2 ``logiscape/mcp-sdk-php`` build supports ``outputSchema`` and
``structuredContent``. ``ToolResultNormalizer`` mirrors a successful single
JSON text result into structured content while retaining text for stable
clients. Result schemas and descriptions remain security-relevant contracts;
clients must not treat a schema as authorization.

No issues found
===============

- SQL injection paths were closed by parameterized ``QueryBuilder`` usage and
  the constrained ``where`` parser
- Input validation remains TCA-driven in write operations
- No authentication bypass was identified for tool execution
