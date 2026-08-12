.. include:: /Includes.rst.txt

=======================
Implementation overview
=======================

Overview
========

The extension is organized around a small MCP runtime with a set of TYPO3-aware
tool classes. The important design goal is that MCP clients see stable,
editor-friendly behavior while TYPO3 keeps control over permissions,
workspaces, TCA, language overlays, and file access.

For a product-level summary of what this maintained fork adds compared with
the original upstream line, see :doc:`../Introduction/ForkChanges`.

Request flow
============

Remote MCP requests follow this path:

1. ``Classes/Middleware/McpServerMiddleware.php`` intercepts the ``/mcp`` path
   before TYPO3's site resolver and dispatches to :php:`McpEndpoint`.
2. ``Classes/Http/McpEndpoint.php`` authenticates the request with
   :php:`OAuthService`, initializes a backend user context plus an anonymous
   in-memory backend user session, and creates an ``HttpServerRunner`` from the
   MCP PHP SDK.
3. The endpoint calls ``HttpServerRunner::handleRequest()`` directly. Stable
   requests can receive ``Mcp-Session-Id`` and file-backed session state;
   ``2026-07-28`` requests use an ephemeral, sessionless context.
4. ``Classes/MCP/McpServerFactory.php`` registers tools, prompts, and
   resources. The SDK supplies ``server/discover`` and adapts typed results to
   the negotiated protocol era.
5. ``Classes/MCP/ToolRegistry.php`` provides the discovered tool instances.
6. A tool executes and delegates most TYPO3-specific work to shared services.
7. The tool returns MCP content. Valid single-JSON text results retain their
   text representation and also gain ``structuredContent``.

Local stdio requests skip the HTTP/OAuth layer and start at
``Classes/Command/McpServerCommand.php``.

MCP ergonomics (external guidance)
==================================

Tool design is checked against the public `mcp-builder` skill (Anthropic,
https://github.com/anthropics/skills/blob/main/skills/mcp-builder/SKILL.md ):
clear descriptions, JSON Schema properties, the four MCP tool **annotations**
on every tool, pagination hints where results are bounded (for example
``ReadTable`` ``hasMore``), and actionable errors via :php:`AbstractTool` and
:php:`ExceptionHandlerTrait`. Unknown tool names use the SDK's typed Invalid
Params protocol error.

**Naming:** Tools use PascalCase names aligned with TYPO3 concepts
(``ReadTable``, ``GetPageTree``, …), not a ``prefix_action`` pattern; the
official tools table in :doc:`../Tools/Index` lists names and access mode for
LLM discoverability.

**Structured outputs:** The v2 SDK supports ``outputSchema`` and
``structuredContent``. Existing tools keep readable text for legacy clients;
``ToolResultNormalizer`` mirrors valid JSON into structured content.

Main layers
===========

HTTP and backend module layer
-----------------------------

``Classes/Http/`` contains:

- the MCP endpoint (``McpEndpoint``), which handles OAuth validation, backend
  user context setup, and the MCP SDK HTTP transport. The endpoint calls the
  SDK's ``HttpServerRunner::handleRequest()`` directly and maps the SDK
  response (headers, status, body) into TYPO3's PSR-7 response pipeline.
  Stable transport sessions are persisted to ``var/mcp_sessions/`` via
  ``FileSessionStore``. ``2026-07-28`` has no protocol session. TYPO3
  backend-user state for token calls stays request-local.
- OAuth authorization, token, metadata, and registration endpoints
- shared CORS helpers

``Classes/Controller/McpServerModuleController.php`` powers the backend module
under :guilabel:`User > MCP Server`. That module is the operator-facing control
surface for endpoint discovery, client setup, token management, and current
workspace information.

MCP runtime layer
-----------------

The runtime itself is intentionally thin:

- ``McpServerFactory`` wires typed tools, prompts, resources, private cache
  hints, and negotiated errors into the SDK server
- ``ToolRegistry`` collects every Symfony DI service tagged ``mcp.tool``.
  Services implementing ``ToolInterface`` are kept as-is. Any other tagged
  object that exposes ``getName()`` and ``execute()`` is wrapped in
  ``CompatibleToolAdapter``, which normalizes its schema, description, and
  result types to the native ``ToolInterface`` contract. This lets
  lightweight or third-party tools participate without depending on the
  extension's interface directly.
- ``SkillRegistry`` validates bundled skill frontmatter and paths.
  ``PromptRegistry`` projects user-invocable skills, and ``ResourceRegistry``
  serves their original Markdown.
- ``AbstractTool`` centralizes initialization and exception handling
- ``AbstractRecordTool`` adds one important behavior: it injects an optional
  ``workspace_id`` parameter into record-backed tools and switches workspace
  context before the concrete tool runs

Tool layer
----------

The public MCP surface lives in ``Classes/MCP/Tool/``:

- page navigation, page context, and cross-table search tools
- workspace discovery, review, publish, and rollback tools
- FAL-wide read tools and sandbox-scoped write tools
- preview/render verification tools
- import, content-audit, copy, and image-attachment helpers
- site configuration, Site Set, extension-management, and safe CLI tools
- dev-site tools for site settings, ViewHelpers, XLF files, and TCA resources
- optional x402 monetization helpers

``Classes/MCP/Tool/Record/`` contains the TCA-driven record tools:

- ``ListTables``
- ``ReadTable``
- ``GetTableSchema``
- ``GetFlexFormSchema``
- ``WriteTable``
- ``AttachImage``
- ``BulkWrite``
- ``PublishWorkspace`` / ``RollbackWorkspace`` / ``WorkspaceReview``
- ``ImportContent`` / ``ImportFromUrl`` / ``ContentAudit``
- ``CopyContent`` / ``CreateSite`` / ``ManageRedirects``
- ``GetPreviewUrl`` / ``RenderRecord`` / ``SiteSet`` / ``SiteSettings``

These tools are deliberately generic. They are not built around one specific
extension. Instead they derive their behavior from TYPO3 TCA and the current
backend user's permissions.

Shared services
===============

``WorkspaceContextService``
   Honors an explicit workspace first. Without one, trusted local mode selects
   live workspace ``0``; strict/production mode keeps the current draft or
   selects a writable draft, creating an MCP workspace only when needed and
   permitted.

``TableAccessService``
   The central gatekeeper for table and field visibility. It is the semantic
   facade over ``TcaSchemaFactory`` and ``TcaSchemaCapability``, combined with
   backend permissions, web mounts, read-only policy, and TSconfig.

``LanguageService``
   Maps TYPO3 site languages to ISO codes. Tool schemas use this service to
   decide whether to expose language parameters at all.

``McpFileSandboxService``
   Restricts file operations to a configured sandbox root such as ``1:/mcp/``
   and computes workspace-specific upload folders when that feature is enabled.

``SiteInformationService``
   Resolves available domains and generates page URLs so page-oriented tools
   can work with URLs as well as page UIDs.

``OAuthService``
   Stores only hashes of authorization codes, access tokens, and refresh
   tokens; validates client/resource/scope/redirect bindings and mandatory
   ``S256`` PKCE; rotates refresh-token families transactionally; and detects
   stale or concurrent replay.

``CapabilityManifestService``
   Loads public capability fields plus ``x-mcp``, checks prerequisites,
   inventories commands and skills, enforces tools, and gates outbound URL
   schemes and hosts.

``OutboundUrlGuardService``
   Resolves all A and AAAA addresses, rejects private or reserved targets, and
   pins the validated destination to prevent DNS rebinding.

``AbilityBackendUserContextService`` / ``McpCliBackendUserBootstrapService``
   Revalidate and hydrate real TYPO3 backend-user state for Abilities,
   REST, and CLI projections before native tools run.

``McpToolCatalogService``
   Shares deterministic list, describe, and execute behavior with
   Abilities, CLI, and REST projections.

``LocalModeService``
   Detects DDEV / TYPO3 Development context and resolves the
   ``localUnsafeMode`` and ``mcpServer.strictSandbox`` policy used by
   workspace, file, network, and dev-site gates.

``DevSiteToolService``
   Provides the shared gate for dev-site-only tools and MCP resources.

``FileReferenceAttachmentService``
   Handles FAL reference creation for image and file fields while preserving
   DataHandler/workspace behavior.

TYPO3 core integration
======================

The extension tries to stay close to TYPO3 core behavior:

- writes use ``DataHandler``
- page language overlays use ``PageRepository`` and ``LanguageAspect``
- TCA-driven semantics come from ``TcaSchemaFactory`` capabilities; raw TCA is
  retained only for field detail not exposed by the Schema API
- file operations go through TYPO3 FAL

Where TYPO3 core does not provide transparent MCP behavior directly, the
extension adds a small adaptation layer instead of replacing TYPO3 wholesale.
The main example is workspace transparency.

Transparency contracts
======================

Workspace transparency
----------------------

MCP clients should not have to understand TYPO3 version rows or workspace
overlay internals. The implementation therefore keeps client-facing UIDs stable
and resolves workspace rows internally.

Important pieces:

- ``WorkspaceDeletePlaceholderRestriction`` hides live rows that are deleted in
  the active workspace
- tools resolve live UIDs to workspace rows for writes
- read and search results are normalized back to stable live-facing UIDs

See :doc:`WorkspaceTransparency` for the detailed rationale.

Language visibility
-------------------

Language handling is intentionally conditional. If an instance has only one
language, tools do not expose translation-oriented parameters just for the sake
of symmetry. When multiple languages exist, tools expose ISO-code based
language parameters so MCP clients do not need numeric language IDs.

See :doc:`LanguageOverlays` for the overlay strategy.

File safety model
-----------------

File tools are sandboxed to the MCP file sandbox, but TYPO3 physical files are
not workspace-versioned. The implementation does not hide that fact. Workspace
subfolders only reduce collisions and keep draft-oriented uploads grouped more
predictably.

Tests and quality
=================

The repository uses several test layers:

- unit tests for focused service behavior
- TYPO3 functional tests for tool contracts, permissions, workspaces,
  translations, file handling, and extension compatibility
- architecture tests with PHPat for a few dependency rules
- LLM-oriented tests that verify tool descriptions are usable in realistic,
  multi-step workflows

The functional test surface is broad enough that the documentation should be
read as executable behavior, not only as product copy. When tool behavior
changes, the corresponding functional tests are expected to change with it.
