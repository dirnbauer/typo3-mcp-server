.. include:: /Includes.rst.txt

.. _fork-changes:

========================
Maintained fork changes
========================

This page explains the current maintained fork relative to the original
``hauptsacheNet/typo3-mcp-server`` line. It describes live implementation
paths only: current classes, tools, configuration, tests, and documentation.
Generated render output and obsolete experiments are intentionally not listed
as features.

.. _fork-changes-platform:

TYPO3 v14 foundation
====================

The fork is a TYPO3 v14-only extension:

- Composer requires TYPO3 ``^14.0`` and ``typo3/cms-workspaces``.
- PHP support follows the extension metadata: PHP 8.2 through 8.5.
- Tool code uses constructor dependency injection, final classes, TYPO3 v14
  TCA schema APIs, DataHandler, PageRepository, FAL, site, and language APIs.
- Compatibility branches for older TYPO3 major versions are not part of the
  maintained surface.

The practical result is a smaller contract: documentation and tests describe
one TYPO3 major line instead of preserving legacy behavior.

.. _fork-changes-transport:

Transport and authentication
============================

The fork adds a production-oriented remote endpoint and a local development
entry point:

- ``/mcp`` is routed through ``McpServerMiddleware`` and ``McpEndpoint``.
- Remote clients authenticate with OAuth 2.1 style bearer tokens and PKCE.
- OAuth authorization, token, registration, authorization-server metadata, and
  protected-resource metadata endpoints are implemented under ``Classes/Http``.
- Token-authenticated HTTP calls initialize a backend user context and an
  in-memory backend session for the current request.
- The MCP PHP SDK ``HttpServerRunner`` is called directly, and transport
  headers such as ``Mcp-Session-Id`` are forwarded back to the client.
- ``vendor/bin/typo3 mcp:server`` remains available for trusted local stdio
  clients.

The backend module exposes endpoint URLs, per-client setup snippets, health
checks, and token management so editors do not need to assemble OAuth or stdio
configuration by hand.

.. _fork-changes-runtime:

MCP runtime
===========

The runtime was expanded from a fixed tool set into a service-driven MCP
surface:

- ``ToolRegistry`` collects Symfony services tagged ``mcp.tool``.
- Native tools implement ``ToolInterface``.
- ``CompatibleToolAdapter`` wraps tagged third-party tools that expose
  ``getName()`` and ``execute()`` without taking a hard dependency on the
  extension's interface.
- ``AbstractTool`` centralizes manifest enforcement, admin/dev-site gates,
  initialization, and tool-error handling.
- ``AbstractRecordTool`` adds ``workspace_id`` handling and workspace context
  switching to record-backed tools.
- ``McpServerFactory`` normalizes JSON Schema output so strict MCP clients get
  object-shaped ``properties`` and no empty ``required`` arrays.

Unknown tool names are returned as MCP tool errors with a ``tools/list`` hint
instead of surfacing as generic JSON-RPC internal errors.

.. _fork-changes-records:

Workspace-safe record editing
=============================

The fork makes TYPO3 workspaces the normal write path:

- ``WorkspaceContextService`` keeps a current non-live workspace, switches to
  a requested workspace, or selects/creates an MCP workspace for the user.
- Record-backed write tools expose stable live-facing UIDs, while internal
  TYPO3 workspace version rows stay hidden.
- ``WorkspaceDeletePlaceholderRestriction`` and custom overlay logic prevent
  delete placeholders and version internals from leaking into normal reads.
- ``WriteTable`` supports create, update, translate, delete, movement, inline
  relations, file references, and language-aware writes through DataHandler.
- ``BulkWrite`` batches multiple record operations but rejects inline child
  payloads that should go through ``WriteTable``.
- ``CopyContent`` uses DataHandler copy behavior so relations and file
  references are preserved.
- ``PublishWorkspace``, ``RollbackWorkspace``, and ``WorkspaceReview`` provide
  dry-run-first review and release workflows.

``workspace_id: 0`` is accepted only when local mode explicitly allows live
writes. Production endpoints keep record writes staged in a non-live workspace.

.. _fork-changes-tca:

TCA, permissions, and languages
===============================

The maintained line is TCA-first:

- ``TableAccessService`` is the central gate for table access, field access,
  read-only tables, workspace capability, TSconfig restrictions, and backend
  user permissions.
- ``ListTables`` and ``GetTableSchema`` reflect accessible TCA tables and
  fields rather than hard-coded content types.
- ``GetFlexFormSchema`` reads FlexForm data structures for plugins and content
  types.
- ``BeforeRecordReadEvent``, ``AfterRecordReadEvent``,
  ``BeforeRecordWriteEvent``, ``AfterRecordWriteEvent``, and
  ``AfterSchemaLoadEvent`` let site extensions adapt MCP behavior.
- ``LanguageService`` exposes ISO-code parameters only when meaningful site
  language support exists.
- Page overlays use TYPO3 ``PageRepository``; workspace overlays use the
  extension's transparency logic.

Configured read-only tables such as ``sys_file`` can be exposed safely for
reads, and hidden standalone tables such as ``sys_file_metadata`` can be
exposed without treating them only as embedded child records.

.. _fork-changes-files:

File handling
=============

The fork adds explicit FAL and sandbox behavior:

- ``McpFileSandboxService`` restricts write-capable file tools to the
  configured sandbox root, defaulting to ``1:/mcp/``.
- ``BrowseFiles``, ``ReadFileMetadata``, ``WriteFile``, ``UploadFile``, and
  ``UploadFileFromUrl`` operate in that sandbox in strict mode.
- ``ListStorages``, ``BrowseFolder``, ``SearchFile``, and ``SearchMedia`` give
  read-only FAL visibility across accessible storages.
- ``UploadFile`` stores base64 payloads with randomized filenames and optional
  metadata.
- ``UploadFileFromUrl`` fetches HTTP(S) files with host allowlisting and
  DNS/IP checks outside local mode. Redirect limits, timeouts, and size limits
  still apply to the download.
- ``WriteFile`` can create or overwrite text files and update metadata on
  existing files. SVG is not included in the default text-file allowlist.
- ``AttachImage`` stages, optionally processes, and attaches images to TCA file
  fields by creating workspace-versioned references.

Physical files are not workspace-versioned by TYPO3. The fork documents that
plainly: file writes take effect immediately, while records and file
references can still be staged in workspaces.

.. _fork-changes-verification:

Verification and import workflows
=================================

The fork adds tools that help an assistant verify and review its own edits:

- ``GetPreviewUrl`` builds a TYPO3 workspace preview URL for pages and content
  elements.
- ``RenderRecord`` fetches rendered frontend HTML or text for a page, optionally
  narrowed to one content element.
- ``ContentAudit`` scans page trees for content quality and SEO issues.
- ``ImportContent`` analyzes raw text, Markdown, or HTML and can propose or
  create TYPO3 content elements.
- ``ImportFromUrl`` fetches a public URL and can propose or create a TYPO3 page
  with extracted content.

``RenderRecord`` and ``UploadFileFromUrl`` are the only bundled tools that open
outbound HTTP connections. Both are gated by the capability manifest outside
local mode.

.. _fork-changes-admin-dev:

Admin, optional, and dev-site tools
===================================

The fork adds guarded tools for operations that should not appear as ordinary
editor writes:

- ``CreateSite`` creates or updates YAML site configuration and remains
  admin-only.
- ``SiteSet`` attaches or detaches TYPO3 Site Sets and remains admin-only.
- ``InstallExtension`` installs, activates, searches, or lists extensions and
  remains admin-only.
- ``SafeCli`` runs only an allowlisted set of TYPO3 CLI commands.
- x402 tools are optional and return guidance when the paywall surface is not
  installed.
- ``SiteSettings``, ``ListViewHelpers``, ``GetViewHelperDocumentation``, and
  ``CreateLocallang`` are exposed only in dev-site mode.
- MCP TCA resources ``typo3-mcp://tca`` and
  ``typo3-mcp://tca/{tableName}`` are also dev-site only.

Dev-site mode is the same gate as local mode. Setting
``mcpServer.strictSandbox`` disables those relaxations even inside DDEV.

.. _fork-changes-cli:

CLI mirror
==========

Every bundled MCP tool has a Symfony console command:

- Dedicated commands use the ``mcp:<tool-name>`` naming pattern.
- ``mcp:tool <Name>`` runs any registered MCP tool by exact MCP name.
- ``mcp:tool:list`` lists tools and can dump a tool schema.
- ``--json`` returns a machine-readable ``{ok, result}`` envelope.
- ``--plain`` and ``--no-ansi`` remove decoration for logs and scripts.
- ``--param key=value``, repeated ``--param`` values, ``--params <json>``, and
  ``--param key=@file.json`` cover simple and structured inputs.
- File-based CLI params are constrained to the TYPO3 project root.

Most shortcuts are registered with ``GenericMcpToolCommand`` in
``Configuration/Services.yaml``. Custom command classes are used only when a
tool needs special options or output.

.. _fork-changes-security:

Security hardening
==================

The maintained line adds several explicit security gates:

- Access and refresh tokens are stored as SHA-256 hashes.
- PKCE requires ``S256`` for authorization-code flows.
- Query-string bearer-token authentication is disabled by default.
- The unauthenticated auth-header diagnostic is disabled by default.
- Sensitive request headers and token query parameters are redacted from MCP
  debug logs.
- Browser-defense headers are added to MCP and OAuth responses.
- ``CapabilityManifestService`` enforces tool subsystems and outbound hosts.
- ``AdminOnly`` and ``DevSiteOnly`` attributes gate sensitive tools.
- Unsafe system fields such as ``t3ver_*``, timestamps, permission fields, and
  deletion flags are rejected at the MCP layer.
- ``ReadTable`` validates page access for non-admin users, including UID-only
  lookups.
- ``RenderRecord`` disables redirects and verifies TLS outside local mode.
- ``UploadFileFromUrl`` rejects private/reserved IP targets outside local mode.

Operators can harden further by removing subsystems from
``Configuration/Capabilities.yaml`` or by keeping ``localUnsafeMode`` pinned to
``off``.

.. _fork-changes-local-mode:

DDEV and local mode
===================

``LocalModeService`` detects DDEV environment variables and TYPO3 Development
application context, or accepts explicit ``localUnsafeMode`` configuration:

- ``auto`` enables local mode only when DDEV or Development context is detected.
- ``on`` enables local mode in trusted local environments.
- ``off`` keeps production-style safety nets active.

When active, local mode permits ``workspace_id: 0``, writable non-workspace
TCA tables, unrestricted FAL file targets, unrestricted outbound hosts, and
dev-site tools. It does not bypass OAuth, backend-user permissions, admin-only
attributes, or per-tool subsystem checks.

.. _fork-changes-backend-ui:

Backend module and localization
===============================

The backend module was expanded into an operator-facing setup UI:

- Remote and local client setup instructions are rendered from the module.
- Cursor local stdio setup preserves stdin and supports DDEV project execution.
- Token creation and revocation happen inside the module.
- Health checks report endpoint reachability, OAuth metadata, tool count,
  local CLI availability, workspace state, and token state.
- Module labels were migrated to XLIFF 2 with ICU-style messages and German
  translations.

.. _fork-changes-quality:

Tests, docs, and quality gates
==============================

The fork adds source documentation and verification around the tool surface:

- ``Documentation/`` contains the TYPO3 reStructuredText manual.
- ``TECHNICAL_OVERVIEW.md`` remains the long-form architecture companion.
- ``CHANGELOG.md`` records fork-level changes.
- Unit tests cover focused services and runtime behavior.
- Functional tests cover tool contracts, workspaces, permissions, languages,
  files, OAuth, local mode, and optional surfaces.
- LLM tests exercise real editorial workflows against multiple model families.
- Playwright E2E tests cover the backend module.
- PHPStan, PHP CS Fixer, Rector, Fractor, architecture tests, and docs render
  checks are wired into the development workflow.

When a tool contract changes, update the implementation, deterministic tests,
LLM-facing descriptions, README, and manual together.
