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

- Composer requires TYPO3 ``^14.3.6`` and ``typo3/cms-workspaces``.
- PHP support is PHP 8.4 through 8.5.
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
- HTTP/OAuth routing and discovery URLs honor TYPO3 installations below a
  path prefix, including the RFC 8414 and RFC 9728 well-known URI forms.
- Token-authenticated HTTP calls initialize a backend user context and an
  in-memory backend session for the current request.
- The MCP PHP SDK serves two eras: stable ``2025-11-25`` requests retain their
  handshake/session headers, while ``2026-07-28`` requests use
  ``server/discover`` and no protocol session.
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
- Typed tool catalogs are sorted and cacheable; JSON text results also expose
  ``structuredContent`` without dropping their stable text form.
- ``SkillRegistry``, ``PromptRegistry``, and ``ResourceRegistry`` expose
  bundled workflows through interoperable MCP primitives.

Unknown tool names use the SDK's typed Invalid Params error. Unknown resources
use the error code required by the negotiated era.

.. _fork-changes-records:

Workspace-safe record editing
=============================

The fork makes TYPO3 workspaces the normal write path on **production**:

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

On **production**, record writes stay staged in a non-live workspace unless
local mode is explicitly enabled (which it should not be).

On **DDEV / local development**, when local mode is active, omitted
``workspace_id`` now **defaults to live** so local sites behave like direct
editing. Pass an explicit draft ``workspace_id`` to stage locally. Per-user
User TSconfig can opt out. Plain-language guide:
:doc:`../Configuration/LiveEditsOnDevelopment`.

.. _fork-changes-tca:

TCA, permissions, and languages
===============================

The maintained line is TCA-first:

- ``TableAccessService`` is the central semantic facade over TYPO3's
  ``TcaSchemaFactory`` and ``TcaSchemaCapability``, combined with field access,
  TSconfig, web mounts, and backend-user permissions.
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
  read-only FAL visibility across storages and folders allowed by the backend
  user's file mounts.
- ``UploadFile`` stores base64 payloads with randomized filenames and optional
  metadata.
- ``UploadFileFromUrl`` fetches HTTP(S) files with host allowlisting and
  DNS/IP checks outside local mode. Redirects are disabled; timeouts and size
  limits still apply to the download.
- ``WriteFile`` can create or overwrite text files and update metadata on
  existing files. SVG is not included in the default text-file allowlist.
- ``AttachImage`` stores/processes any physical file immediately, then attaches
  it to TCA file fields through a workspace-aware file-reference record.

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

The bundled outbound paths are ``UploadFileFromUrl``, ``ImportFromUrl``,
``RenderRecord``, and x402 facilitator verification triggered by
``GetPaidContent`` when its optional compatible adapter is available. All are
gated by the capability manifest outside local mode. All reject redirects,
including the x402 verification POST, so operators must allow the actual final
host.

.. _fork-changes-admin-dev:

Admin, optional, and dev-site tools
===================================

The fork adds guarded tools for operations that should not appear as ordinary
editor writes:

- ``CreateSite`` creates or updates YAML site configuration and remains
  admin-only. On ``create`` it also wires up editor access for the new website:
  it provisions a dedicated per-site backend group (``Editors: <root page
  title>``) mounted at the root with content-editing permissions, makes that
  group the owner of the root page, and optionally adds named ``editors`` to it —
  so non-admins can edit the new site without granting access to every existing
  editor team. Page-tree–restricted workspaces are extended to cover the new
  root for staging; unrestricted workspaces are left untouched. The result is
  reported under ``access`` in the response.
- ``SiteSet`` attaches or detaches TYPO3 Site Sets and remains admin-only.
- ``InstallExtension`` installs, activates, searches, or lists extensions and
  is admin-only and dev-site-only because Composer can write the project and
  contact package repositories.
- ``SafeCli`` runs only an allowlisted set of TYPO3 CLI commands.
- ``SolrIndexQueue`` lists and runs validated EXT:solr scheduler index queue
  tasks and remains admin-only; its configured scheduler service is declared
  as an indirect network effect.
- x402 tools are optional and return guidance when the paywall surface is not
  installed.
- ``SiteSettings``, ``ApplyShadcnPreset``, ``ListViewHelpers``,
  ``GetViewHelperDocumentation``, and ``CreateLocallang`` are exposed only in
  dev-site mode.
- MCP TCA resources ``typo3-mcp:///tca`` and
  ``typo3-mcp:///tca/{tableName}`` are also dev-site only.

Dev-site mode is the same gate as local mode. Setting
``mcpServer.strictSandbox`` disables those relaxations even inside DDEV.

.. _fork-changes-cli:

CLI mirror
==========

Every bundled MCP tool has a Symfony console command:

- Dedicated commands use the ``mcp:<tool-name>`` naming pattern.
- ``mcp:tool <Name>`` runs any registered MCP tool by exact MCP name.
- ``mcp:tool:list`` lists tools and can dump a tool schema.
- ``mcp:prompt:list`` and ``mcp:prompt:get`` mirror standard MCP prompt
  discovery and render slash-style workflow names.
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
- Authorization codes are hashed, one-time, and exactly bound to client,
  redirect URI, resource, scope, and mandatory ``S256`` PKCE.
- OAuth consent approval requires TYPO3 backend form protection and successful
  redirects include a validated authorization-server issuer.
- Rotated refresh tokens belong to an absolute-lifetime family; stale or
  concurrent replay revokes its active successor.
- PKCE requires ``S256`` for authorization-code flows.
- Query-string bearer-token authentication was removed; bearer tokens are
  accepted only from the ``Authorization`` header.
- Browser origins are checked exactly before authentication; same-origin is
  implicit and additional origins require ``allowedOrigins``.
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
- ``UploadFileFromUrl``, ``ImportFromUrl``, ``RenderRecord``, and optional x402
  facilitator verification disable redirects, validate every A/AAAA answer,
  pin validated DNS, enforce bounded response sizes, and reject
  private/reserved targets outside local mode.

The public capability declaration now stays compatible with the archived
version 1.0 proposal, while exact tools, commands, skills, protocol revisions,
and optional integrations live in ``x-mcp`` and are checked for consistency.

The bundled Abilities and ``sg_apicore`` packages expose tool list, describe,
and execute abilities plus skill list and get abilities through governed CLI
and opt-in REST projections. Native MCP tools and the bundled skill registry
remain the single implementations and retain their permission and workspace
gates.

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

When active, local mode defaults omitted record writes to ``workspace_id: 0``,
permits writable non-workspace TCA tables, removes the MCP file sandbox and
outbound-host allowlist, and exposes dev-site tools. TYPO3 backend file mounts,
OAuth, backend-user permissions, admin-only attributes, and per-tool subsystem
checks still apply.

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
