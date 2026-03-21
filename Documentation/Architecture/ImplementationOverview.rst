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

Request flow
============

Remote MCP requests follow this path:

1. ``Classes/Http/McpEndpoint.php`` authenticates the request with
   :php:`OAuthService` and initializes a backend user context.
2. ``Classes/MCP/McpServerFactory.php`` builds the MCP server and registers the
   ``tools/list`` and ``tools/call`` handlers.
3. ``Classes/MCP/ToolRegistry.php`` provides the discovered tool instances.
4. A tool executes and delegates most TYPO3-specific work to shared services.
5. The tool returns MCP text content, usually as readable text or as JSON
   encoded into text content.

Local stdio requests skip the HTTP/OAuth layer and start at
``Classes/Command/McpServerCommand.php``.

MCP ergonomics (external guidance)
==================================

Tool design is checked against the public `mcp-builder` skill (Anthropic,
https://github.com/anthropics/skills/blob/main/skills/mcp-builder/SKILL.md ):
clear descriptions, JSON Schema properties, the four MCP tool **annotations**
on every tool, pagination hints where results are bounded (e.g. ``ReadTable``
``hasMore``), and actionable errors via :php:`AbstractTool` /
:php:`ExceptionHandlerTrait`. For an unknown tool name, :php:`McpServerFactory`
returns a ``CallToolResult`` with ``isError`` instead of throwing (so the client
gets a normal tool error, not a JSON-RPC internal error).

**Naming:** Tools use PascalCase names aligned with TYPO3 concepts
(``ReadTable``, ``GetPageTree``, …), not a ``prefix_action`` pattern; the
official tools table in :doc:`../Tools/Index` lists names and access mode for
LLM discoverability.

**Structured outputs:** The bundled PHP MCP SDK does not advertise
``outputSchema`` on tool results; outputs remain JSON-in-text (or plain text)
as documented in :doc:`SecurityAudit`.

Main layers
===========

HTTP and backend module layer
-----------------------------

``Classes/Http/`` contains:

- the MCP endpoint
- OAuth authorization, token, metadata, and registration endpoints
- shared CORS helpers

``Classes/Controller/McpServerModuleController.php`` powers the backend module
under :guilabel:`User > MCP Server`. That module is the operator-facing control
surface for endpoint discovery, client setup, token management, and current
workspace information.

MCP runtime layer
-----------------

The runtime itself is intentionally thin:

- ``McpServerFactory`` wires the MCP SDK server
- ``ToolRegistry`` collects every class implementing
  ``Hn\\McpServer\\MCP\\Tool\\ToolInterface``
- ``AbstractTool`` centralizes initialization and exception handling
- ``AbstractRecordTool`` adds one important behavior: it injects an optional
  ``workspace_id`` parameter into record-backed tools and switches workspace
  context before the concrete tool runs

Tool layer
----------

The public MCP surface lives in ``Classes/MCP/Tool/``:

- page navigation and page context tools
- cross-table search
- workspace discovery
- file harness tools

``Classes/MCP/Tool/Record/`` contains the TCA-driven record tools:

- ``ListTables``
- ``ReadTable``
- ``GetTableSchema``
- ``GetFlexFormSchema``
- ``WriteTable``

These tools are deliberately generic. They are not built around one specific
extension. Instead they derive their behavior from TYPO3 TCA and the current
backend user's permissions.

Shared services
===============

``WorkspaceContextService``
   Keeps the current non-live workspace by default, switches to an explicit
   workspace when requested, otherwise selects a writable workspace, or creates
   an MCP workspace if none exists and the user is allowed to create one.

``TableAccessService``
   The central gatekeeper for table and field visibility. It combines TCA,
   backend permissions, workspace capability checks, read-only restrictions,
   and TSconfig-based field disabling.

``LanguageService``
   Maps TYPO3 site languages to ISO codes. Tool schemas use this service to
   decide whether to expose language parameters at all.

``McpFileHarnessService``
   Restricts file operations to a configured harness root such as ``1:/mcp/``
   and computes workspace-specific upload folders when that feature is enabled.

``SiteInformationService``
   Resolves available domains and generates page URLs so page-oriented tools
   can work with URLs as well as page UIDs.

``OAuthService``
   Stores authorization codes and access tokens, hashes access tokens before
   database storage, validates PKCE, and tracks token usage metadata.

TYPO3 core integration
======================

The extension tries to stay close to TYPO3 core behavior:

- writes use ``DataHandler``
- page language overlays use ``PageRepository`` and ``LanguageAspect``
- TCA-driven schema information comes from raw TCA plus ``TcaSchemaFactory``
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

File tools are sandboxed to the MCP harness, but TYPO3 physical files are not
workspace-versioned. The implementation does not hide that fact. Workspace
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
