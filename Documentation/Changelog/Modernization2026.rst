.. include:: /Includes.rst.txt

.. _modernization-2026:

=======================
2026 MCP modernization
=======================

.. _modernization-2026-scope:

Scope
=====

This page records the complete modernization delivered in the unreleased
2026 line. Tool contracts may change when that improves model ergonomics;
workspace, permission, and security guarantees may not be weakened.

.. _modernization-2026-platform:

Platform and dependencies
=========================

- Raised the runtime minimum from PHP 8.2 to PHP ``^8.3``.
- Aligned the CI matrix with PHP 8.3, 8.4, and 8.5.
- Updated TYPO3 dependencies from 14.3.0 to the current ``^14.3`` patch line.
- Required ``logiscape/mcp-sdk-php:^2.0.0-beta3`` and locked
  ``2.0.0-beta3`` during the release-candidate validation window.
- Raised ``a9f/typo3-fractor`` from ``^0.5`` to ``^1.0``; the validation lock
  snapshot selects TYPO3 Fractor 1.0.0 and its Fractor 1.0 extension set.
- Refreshed the validation lock snapshot to ParaTest 7.20.0, PHPUnit 12.5.31,
  PHPStan 2.2.5, PHP CS Fixer 3.95.13, Rector 2.5.5, and TYPO3 Rector 3.14.3.
  These are development-only validation versions, not runtime requirements.
- Made unit and functional Composer test gates fail on PHPUnit notices and
  converted passive test doubles to stubs, keeping interaction mocks only where
  an expectation is asserted.
- Added source repositories for the TYPO3 v14 ``sg_apicore`` fork and the
  Abilities registry to development/test installation.
- Kept both integration packages optional for consumers; conditional service
  loading preserves a minimal MCP-only installation.
- Removed the archived capability-manifest package from Composer suggestions.
  Its v1.0 schema and article remain design references; local runtime code and
  tests enforce this extension's manifest.

.. _modernization-2026-protocol:

MCP protocol and SDK
====================

- Added dual-era service for stable ``2025-11-25`` and release-candidate
  ``2026-07-28`` requests on stdio and Streamable HTTP.
- Added ``server/discover`` and stateless request handling through the v2 SDK.
- Preserved the stable ``initialize``/``initialized`` handshake and legacy
  session response headers.
- Removed modern dependence on ``Mcp-Session-Id``, standalone GET/SSE resume,
  and connection-scoped protocol state.
- Accepted and validated modern ``Mcp-Method``, ``Mcp-Name``, and designated
  ``Mcp-Param-*`` headers.
- Returned typed, sorted ``ListToolsResult`` data instead of untyped arrays.
- Added private cache hints: 30 seconds for tools and 60 seconds for prompts
  and resources.
- Applied the 60-second private cache contract to individual resource reads as
  well as resource lists and templates.
- Added ``structuredContent`` when a successful single text item is valid
  JSON, while retaining text for stable clients.
- Changed unknown-tool handling to the typed protocol Invalid Params error.
- Adapted resource-not-found codes to the negotiated protocol era.
- Normalized empty tool properties so they serialize as ``{}``.
- Rejected duplicate tool names at registry construction.
- Ensured the v2 stdio runner exits cleanly on EOF.
- Made the executable protocol smoke preserve only the environment required to
  boot TYPO3 subprocesses, including DDEV and conventional TYPO3 path/context
  variables. This keeps host and installed-container protocol checks equivalent.
- Added authenticated raw-HTTP functional coverage for stable session handling,
  stateless discovery, header/body mismatch rejection, negotiated unknown
  tool/resource errors, cache/result fields, and structured tool output.

.. _modernization-2026-prompts:

Prompts, resources, and skills
==============================

- Added a strict ``SkillRegistry`` for bundled Agent Skills, including YAML
  frontmatter validation, safe names and paths, sorting, and caching.
- Exposed a skills overview at ``typo3-mcp:///skills``.
- Exposed each skill's original Markdown at
  ``typo3-mcp:///skills/{skillName}``.
- Kept TCA resources local/development-only while making static skills
  available in strict production mode.
- Projected user-invocable skills through standard MCP ``prompts/list`` and
  ``prompts/get``.
- Added ``request`` and ``context`` prompt arguments.
- Added private prompt caching and typed unknown-prompt errors.
- Added ``mcp:prompt:list`` and ``mcp:prompt:get`` CLI commands. The list
  renders prompt names as slash workflows for humans.
- Kept ``mcp:install-editor-skills`` for hosts that consume filesystem-based
  skills directly.

.. _modernization-2026-manifest:

Manifest and command inventory
==============================

- Reworked ``Capabilities.yaml`` so its public fields use the v1.0 coarse
  capability vocabulary.
- Moved MCP-only runtime data into the namespaced ``x-mcp`` extension block.
- Added stable/preview protocol revisions and transport inventory.
- Added optional Abilities and ``sg_apicore`` integration metadata.
- Added an exact native tool-to-subsystem map and prerequisite chains.
- Added the exact ``mcp:*`` command inventory, including prompts, resources,
  OAuth, diagnostics, server transport, and skill installation.
- Added the bundled skill inventory and expected native tools.
- Updated runtime parsing for ``x-mcp`` with legacy-manifest fallback.
- Added structured outbound host records while retaining exact and wildcard
  policy support.
- Added tests that fail on missing/stale tools, commands, and owned tables.

.. _modernization-2026-schema:

TYPO3 v14 Schema API
====================

- Turned ``TableAccessService`` into the single semantic facade over
  ``TcaSchemaFactory`` and ``TcaSchemaCapability``.
- Added semantic checks for workspace, language, root-level, admin-only,
  read-only, sorting, labels, timestamps, hidden, translation, type, and system
  fields.
- Replaced repeated raw ``ctrl`` interpretation in record read, search, page
  tree, redirects, workspace review, workspace publish, and delete-placeholder
  paths.
- Preserved explicit backend permissions and web-mount checks.
- Retained custom workspace transparency where TYPO3 core does not provide the
  client-facing overlay contract.
- Added focused functional coverage and removed obsolete PHPStan baseline
  suppressions.

.. _modernization-2026-write-hardening:

Write-path validation and Core integration
==========================================

- Reject a positive target ``pid`` that resolves to no page or to a deleted
  page before invoking ``DataHandler``. The tool now returns a structured MCP
  validation error instead of allowing Core to index an incomplete page row,
  emit a PHP warning, or report a misleading success. Root-level ``pid=0``
  remains governed by the table's TCA capability and the explicit root-page
  creation opt-in.
- Reject array input for scalar TCA field types before ``DataHandler``. Arrays
  remain valid only for TCA types whose contract accepts structured relation,
  file, or child-record data.
- Include ``uid`` in the projected reference row before applying
  ``BackendUtility::workspaceOL()`` for ``before:UID`` positioning. This lets
  Core resolve the workspace version without a missing-key warning and keeps
  the move anchored to the intended logical record.
- Seed ``SelectItemResolver``'s new-record FormDataCompiler input with the same
  Core-style ``NEW...`` row UID used by FormEngine. Permission providers can
  therefore evaluate and format errors without indexing a missing
  ``databaseRow.uid``; normal backend permission checks still apply.

.. _modernization-2026-permission-boundaries:

Permission-bound read surfaces
==============================

- Added one ``PageAccessService`` for Core ``PAGE_SHOW`` permission,
  backend web-mount, workspace-version, translation-parent, and record-page
  checks.
- Applied it to page detail/tree, content audit, preview URL, rendered-record,
  and workspace-review reads before unpublished data or signed URLs can leave
  TYPO3.
- Made a restricted editor's web mounts the roots of ``GetPageTree`` and the
  safe default root for ``ContentAudit``.
- Added Core ``FolderMountsRestriction`` to file and media search result and
  count queries, including thumbnail-producing searches.
- Changed storage discovery to ``BE_USER->getFileStorages()`` and added a
  shared FAL guard for metadata and folder browsers.
- Kept backend table, page, and file-mount permissions active when local mode
  relaxes the MCP workspace or file sandbox.

.. _modernization-2026-abilities:

Abilities and ``sg_apicore``
============================

- Added one shared ``McpToolCatalogService`` for list, describe, and execute.
- Registered ``typo3-mcp/list-tools`` and
  ``typo3-mcp/describe-tool`` as low-risk read abilities.
- Registered ``typo3-mcp/execute-tool`` as critical, destructive, and
  non-idempotent with truthful side-effect metadata.
- Registered ``typo3-mcp/list-skills`` and ``typo3-mcp/get-skill`` as
  low-risk, read-only projections of the bundled skill registry.
- Required a real TYPO3 backend user on every MCP ability.
- Exposed all five abilities to CLI and the four read-only abilities to REST,
  while preventing recursive MCP projection. Kept generic ``execute-tool`` off
  REST because the upstream trace recorder persists full arbitrary inputs;
  authenticated remote execution remains on native MCP.
- Registered the optional ``abilities`` REST API through ``sg_apicore`` with
  backend-user-bound tokens and explicit scopes.
- Defaulted REST CORS to deny, rate-limited to 60 requests per minute with
  burst 10, and retained tenant, request-ID, and redacted-log support.
- Made the ``abilities`` API policy registration-order independent by
  reasserting its backend-token provider, safe origins, rate limit, and
  disabled MCP projection before HTTP and console consumers.
- Honored ``activateAbilitiesApi`` instead of implicitly exposing REST merely
  because both optional packages are installed.
- Added a strict abilities-API path allowlist. Inherited API Core auth, demo,
  health, and MCP routes return 404; the native MCP endpoint remains
  authoritative even when API Core's global MCP switch is enabled.
- Filtered generated OpenAPI to the allowed routes and added exact run
  operations, named component schemas, and ``x-typo3-abilities`` metadata from
  the same live registry used for execution.
- Documented that API Core's generated ``docs.json`` and ``docs/ui`` routes are
  public metadata even though ability list, describe, and run routes require a
  backend-user-bound bearer token.

.. _modernization-2026-http:

HTTP and authentication hardening
=================================

- Added exact same-origin validation and a comma-separated
  ``allowedOrigins`` setting for intentional browser origins.
- Rejected malformed, ``null``, and unlisted origins with 403 before bearer
  authentication.
- Applied browser-defense and no-store headers to MCP responses.
- Made bearer parsing strict and header-only.
- Removed query-string bearer authentication unconditionally, including when
  an obsolete local setting is still present.
- Revalidated the active TYPO3 backend-user lifecycle at authorization-code,
  refresh-token, bearer-token, CLI, and Abilities boundaries.
- Required TYPO3 backend form protection on consent approval and RFC 9207
  issuer binding on authorization responses.
- Hashed authorization codes, bound them to client, redirect URI, resource,
  scope, and mandatory ``S256`` PKCE, then consumed them atomically.
- Added refresh-token families with absolute expiry and transactional replay
  history; stale or concurrent reuse revokes the active family.
- Bounded dynamic-registration and token bodies to 64 KiB and MCP bodies to
  25 MiB.
- Removed the false revocation endpoint declaration from protected-resource
  metadata.
- Preserved sensitive-header and token-value log redaction.
- Kept the minimal authentication diagnostic disabled by default.
- Preserved the default outbound policy of configured TYPO3 site hosts only.
- Unified all outbound URL tools on scheme-aware manifest checks, complete
  A/AAAA public-address validation, DNS pinning, no redirects, and streaming
  size limits.
- Applied the outbound manifest to backend connection-diagnostic probes before
  client creation and disabled redirects, preventing request-host and redirect
  based blind SSRF while preserving normal same-origin health checks.
- Replaced the x402 tool's forgeable base64-JSON check with fail-closed x402 v2
  facilitator verification and settlement. Paid content is released only after
  ``/verify`` returns ``isValid=true`` and ``/settle`` returns a successful,
  non-empty transaction. x402 page/content reads now enforce backend table
  and field permissions, page mounts, workspace/language overlays, and enable
  fields; payment statistics are admin-only.
- Applied the outbound manifest, public A/AAAA validation, DNS pinning,
  redirect denial, and a 64 KiB response cap to facilitator verification.
- Declared cache, scheduler, project-wide filesystem, and event footprints in
  the public v1 manifest. Project/cache/scheduler write families now depend on
  ``database:write``, preserving the documented read-only hardening switch.
- Marked Composer and shadcn package-runner tools admin- and dev-site-only and
  declared their non-pinnable subprocess traffic as
  ``network:package-manager``. Declared validated Solr scheduler execution as
  ``scheduler:task`` plus ``network:scheduler``.
- Added a Composer conflict for ``mcp/sdk:*``. The current
  ``typo3-x402-paywall`` 1.0.2 package requires ``mcp/sdk:^0.5`` and therefore
  cannot coexist safely with ``logiscape/mcp-sdk-php`` because both packages
  own incompatible ``Mcp\\`` class trees. The x402 adapter remains ready for a
  compatible paywall release that removes or migrates that dependency.

.. _modernization-2026-workspaces-cli:

Workspaces and CLI context
==========================

- Split read context selection from write escalation. Read-only calls may use
  live or an accessible draft without creating a workspace; strict writes
  select or create a writable non-live workspace.
- Rejected inaccessible explicit workspace IDs and ignored unsafe inherited
  read preselection.
- Made table-independent publish and rollback operations initialize their
  explicit ``workspace_id`` through the shared workspace guard. Fresh CLI or
  HTTP requests no longer depend on an ambient workspace selected by an
  earlier write; regression tests reset to live before addressing the draft.
- Bootstrapped TYPO3's real ``_cli_`` backend user for server, test, generic,
  and dedicated tool commands before executing the shared tool path.
- Kept DDEV/local-mode live-write behavior explicit in schemas, skills, and
  documentation; passing a draft ``workspace_id`` still stages local changes.

.. _modernization-2026-installation:

Installation and operations
===========================

- Updated the setup script to derive the site URL from ``DDEV_PRIMARY_URL``.
- Generated an anchored, escaped ``trustedHostsPattern`` from actual DDEV
  hostnames instead of using localhost or a broad wildcard.
- Verified extension setup creates MCP, Abilities, and API Core tables in the
  development installation.
- Verified native CLI discovery, catalog abilities, generated OpenAPI,
  unauthenticated rejection on protected ability routes, scoped REST
  discovery/description/execution, public documentation routes, request IDs,
  and rate-limit headers.
- Added tests that reject every non-allowlisted abilities-API route, prove the
  API kill switch, remove API Core MCP/auth/demo/health paths from OpenAPI, and
  expose registry-derived ability schemas.
- Verified an installed strict-mode write creates only a workspace overlay,
  leaves the live row unchanged, and can be rolled back by explicit workspace
  ID; the temporary draft, workspace, and smoke tokens are removed afterward.
- Added protocol smoke guidance for stable and release-candidate tracks.
- Corrected the inline-relation functional test to load its missing page
  fixture. This is a test-only setup correction and does not change product
  runtime behavior.

.. _modernization-2026-documentation:

Documentation
=============

- Added MCP fundamentals and a minimal modern PHP server example.
- Added the stable-to-RC protocol diff and client-support caveat.
- Separated MCP, Schema API, manifest, and Abilities meanings of capability.
- Documented skills as prompts/resources and slash-style CLI discovery.
- Added complete ``sg_apicore`` installation, scopes, routes, and checks.
- Removed all query-string-token recommendations.
- Corrected obsolete claims that the PHP SDK lacks structured results.
- Added dual-era, HTTP-security, manifest, and optional-integration test plans.
- Added a functional manifest drift test against TYPO3's booted tool registry,
  in addition to static commands, prerequisites, events, files, tables, and
  skills checks.
