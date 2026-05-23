.. include:: /Includes.rst.txt

.. _tools:

=====
Tools
=====

The MCP server exposes a focused tool set for TYPO3 navigation, schema
inspection, record operations, workspaces, and file handling.

General notes
=============

- Record-backed tools keep the current non-live workspace by default, or
  otherwise select or create a suitable workspace automatically.
- Most record-backed tools also accept an optional ``workspace_id`` override.
- Language-related parameters are only exposed when the TYPO3 instance has
  multiple configured languages.
- File-write tools are restricted to the configured MCP file sandbox in
  strict mode. FAL-wide read tools can inspect storages the backend user may
  access, and DDEV / local-development mode can relax the sandbox for local
  work.
- Some tool families are optional at runtime. For example, ``ManageRedirects``
  requires ``sys_redirect`` to be available, and the x402 tools require the
  optional paywall extension surface.
- Several tools return human-readable text, while record and file write tools
  typically return JSON encoded into MCP text content.

Dev-site tools
--------------

When DDEV, TYPO3 Development context, or ``localUnsafeMode=on`` is active,
additional tools and MCP resources are exposed. They share the same
``localMode`` gate as live workspace writes (``workspace_id: 0``) and
unrestricted FAL file access; ``mcpServer.strictSandbox`` disables all three
even inside DDEV.

On production (``localUnsafeMode=auto`` outside DDEV/Development, or
``localUnsafeMode=off``):

- Record writes stay workspace-staged (auto-select/create workspace > 0).
- File tools stay inside the ``fileadmin/mcp/`` sandbox.

Dev-site tools and MCP resources are filtered from ``tools/list`` and
``resources/list`` on those endpoints. Check ``GetCapabilities`` →
``localMode`` and ``devSiteTools.available``.

Third-party tools
-----------------

Any Symfony DI service tagged ``mcp.tool`` is registered automatically. Services
that implement ``Hn\\McpServer\\MCP\\Tool\\ToolInterface`` are used directly.
Services that do not implement the interface but expose ``getName()`` and
``execute()`` methods are wrapped in ``CompatibleToolAdapter``, which normalizes
their schema and result types. This lets third-party extensions contribute MCP
tools without taking a hard dependency on this extension's interface.

Capability manifest
-------------------

Every tool call also passes through ``CapabilityManifestService::assertToolAllowed()``
inside ``AbstractTool::execute()``. The active manifest lives at
``Configuration/Capabilities.yaml`` and lists the subsystems each tool needs
(``database:read``, ``file:write``, ``render:frontend``, …). Removing a
subsystem disables every tool that requires it; the call returns an
``AccessDeniedException`` rather than executing.

Outbound HTTP from ``UploadFileFromUrl`` and ``RenderRecord`` is gated by the
manifest's ``network.outbound`` policy. Default ships closed at ``[self]`` —
operators opt in to additional hosts per deployment.

CLI mirror
----------

Every bundled tool is also reachable from the TYPO3 CLI, either through a
dedicated ``mcp:<tool-name>`` shortcut or through the universal
``mcp:tool <Name>`` runner. Use ``vendor/bin/typo3 list mcp`` to discover
the active command list. Output modes:

- ``--json`` — machine-readable envelope ``{ok, result}``
- ``--plain`` or ``--no-ansi`` — plain text without decoration
- (default) — pretty colored output

Pass parameters via ``--param key=value`` (repeatable), ``--params <json>``,
or ``--param key=@file.json`` (file must live under the project root). Use
``vendor/bin/typo3 mcp:tool <Name>`` for third-party tools or for scripts that
prefer stable MCP tool names, and
``vendor/bin/typo3 mcp:tool:list --schema=<Name>`` to dump the JSON Schema.
Most shortcut commands can be added with a ``GenericMcpToolCommand`` service
entry in ``Configuration/Services.yaml``; create a custom
``AbstractMcpToolCommand`` subclass only for bespoke options or formatting.

Tool names (MCP ``tools/list``)
===============================

Exact names match the PHP tool classes (e.g. ``ReadTableTool`` → ``ReadTable``).
Use this overview for discoverability (aligned with MCP tool-naming guidance):

.. list-table::
   :header-rows: 1
   :widths: 22 12 66

   * - Tool name
     - Access
     - Summary
   * - ``GetCapabilities``
     - Read
     - Return the active capability manifest + runtime mode (always callable)
   * - ``ListWorkspaces``
     - Read
     - List workspaces; use for optional ``workspace_id`` on record tools
   * - ``GetPageTree``
     - Read
     - Page tree with depth control (workspace overlay)
   * - ``GetPage``
     - Read
     - Resolve page by UID or URL; content context
   * - ``ListTables``
     - Read
     - Tables available to the current user via MCP
   * - ``GetTableSchema``
     - Read
     - TCA fields, types, and relations for one table
   * - ``GetFlexFormSchema``
     - Read
     - FlexForm structure for a plugin/content type
   * - ``ReadTable``
     - Read
     - Query records with filters, limit/offset, language options
   * - ``Search``
     - Read
     - Cross-table LIKE search (per-table cap)
   * - ``WriteTable``
     - Write
     - Create/update/translate/delete in workspace (not live)
   * - ``AttachImage``
     - Write
     - Stage images in the sandbox (URL or ``sys_file``), optional FAL processing, attach to TCA file fields
   * - ``ListStorages``
     - Read
     - List all FAL file storages (UIDs, names, capabilities)
   * - ``BrowseFolder``
     - Read
     - Browse folder contents in any FAL storage (not sandbox-restricted)
   * - ``SearchFile``
     - Read
     - Search FAL for files by name, extension, folder, or MIME type
   * - ``BrowseFiles``
     - Read
     - List MCP file sandbox folders
   * - ``ReadFileMetadata``
     - Read
     - Metadata for a file in the sandbox
   * - ``UploadFile``
     - Write
     - Upload via base64 into sandbox
   * - ``UploadFileFromUrl``
     - Write
     - Fetch URL server-side into sandbox (SSRF-protected)
   * - ``WriteFile``
     - Write
     - Create/replace text file in sandbox
   * - ``SearchMedia``
     - Read
     - Search files across all FAL storage by metadata, type, or dimensions
   * - ``ContentAudit``
     - Read
     - Audit page tree for SEO and content quality issues
   * - ``GetSystemLog``
     - Read
     - Read TYPO3 system log entries for debugging
   * - ``WorkspaceReview``
     - Read
     - Review pending workspace changes with field-level diffs
   * - ``CopyContent``
     - Write
     - Duplicate records preserving relations and file references
   * - ``SafeCli``
     - Execute
     - Run whitelisted TYPO3 CLI commands
   * - ``ApplyShadcnPreset``
     - Execute
     - Apply a shadcn/ui preset code from ``ui.shadcn.com/create`` to an existing frontend project
   * - ``PublishWorkspace``
     - Write
     - Publish pending workspace changes to live (dry-run by default)
   * - ``BulkWrite``
     - Write
     - Execute multiple write operations in a single transaction
   * - ``ImportContent``
     - Read/Write
     - Analyze raw content and propose or create TYPO3 content elements
   * - ``RollbackWorkspace``
     - Write
     - Discard pending workspace changes (dry-run by default)
   * - ``ManageRedirects``
     - Read
     - List URL redirects and explain workspace write limitations
   * - ``ImportFromUrl``
     - Read/Write
     - Fetch URL content and propose or create page with elements
   * - ``CreateSite``
     - Write
     - Create or update TYPO3 site configurations (admin-only)
   * - ``SiteSet``
     - Read/Write
     - Find installed Site Sets and attach/detach them on sites (admin-only)
   * - ``InstallExtension``
     - Execute
     - Install, activate, search, or list loaded TYPO3 extensions (admin-only)
   * - ``SiteSettings``
     - Dev / Admin
     - List/read/update site settings from Site Sets (dev-site only)
   * - ``ListViewHelpers``
     - Dev
     - List Fluid ViewHelpers (dev-site only)
   * - ``GetViewHelperDocumentation``
     - Dev
     - ViewHelper documentation by tag name (dev-site only)
   * - ``CreateLocallang``
     - Dev / Admin
     - Create or extend XLF language files (dev-site only)
   * - ``ListPaidContent``
     - Read
     - List pages gated by the optional x402 paywall extension
   * - ``GetPaidContent``
     - Read
     - Return x402 payment requirements or paid content for one page
   * - ``GetPaymentStats``
     - Read
     - Summarize x402 payment activity and revenue when payment logging exists
   * - ``GetPreviewUrl``
     - Read
     - Build a workspace preview URL for a page or content element
   * - ``RenderRecord``
     - Read
     - Fetch the rendered FE HTML/text of a page in workspace context
       (for visual verification)

Record-backed tools
===================

The following tools use the shared record-tool base class and therefore support
workspace-aware execution:

- ``GetPageTree``
- ``GetPage``
- ``ListTables``
- ``Search``
- ``ReadTable``
- ``GetTableSchema``
- ``GetFlexFormSchema``
- ``ListStorages``
- ``BrowseFolder``
- ``SearchFile``
- ``WriteTable``
- ``AttachImage``
- ``ContentAudit``
- ``GetSystemLog``
- ``WorkspaceReview``
- ``CopyContent``
- ``GetPreviewUrl``
- ``RenderRecord``
- ``PublishWorkspace``
- ``BulkWrite``
- ``ImportContent``
- ``RollbackWorkspace``
- ``ManageRedirects``
- ``ImportFromUrl``
- ``CreateSite``
- ``SiteSet``
- ``SiteSettings`` (dev-site only)

Navigation and discovery
========================

GetPageTree
-----------

Browse the TYPO3 page tree.

:Parameters:
   - ``startPage`` (integer, required): page UID to start from, or ``0`` for
     the root level
   - ``depth`` (integer): tree depth, default ``3``
   - ``language`` (string): ISO language code for translated page titles when
     language support is available
   - ``workspace_id`` (integer): optional workspace override

The result is a readable tree with page URLs, record counts, and plugin storage
hints. Workspace-only pages and draft page edits are included transparently.

GetPage
-------

Resolve a page by URL or UID and return page details plus page content context.

:Parameters:
   - ``uid`` (integer): page UID
   - ``pageId`` (integer): alias for ``uid`` (ergonomic alternative)
   - ``url`` (string): full URL, path, or slug
   - ``language`` (string): ISO language code for translated page and content
     output when language support is available
   - ``languageId`` (integer): deprecated numeric language ID
   - ``workspace_id`` (integer): optional workspace override

Exactly one of ``uid``, ``pageId``, or ``url`` is required.

The result includes page metadata, generated URL, visible records, and
available page translations.

ListTables
----------

List TYPO3 tables that are available through MCP, grouped by extension.

:Parameters:
   - ``workspace_id`` (integer): optional workspace override

The output marks read-only tables and includes workspace-capability context.

Search
------

Search across TYPO3 tables using TCA-derived searchable fields.

:Parameters:
   - ``query`` (string or array): single search string or array of strings.
     Equivalent to ``terms``.
   - ``terms`` (array): alias for ``query``, kept for backwards compatibility.
     At least one of ``query`` / ``terms`` must be provided.
   - ``termLogic`` (string): ``AND`` or ``OR``, default ``OR``
   - ``table`` (string): restrict search to a specific table
   - ``pageId`` (integer): restrict search to one page
   - ``language`` (string): ISO language code when language support is
     available
   - ``limit`` (integer): maximum results per table, default ``50``, max
     ``200``
   - ``workspace_id`` (integer): optional workspace override

Search applies the same workspace transparency rules as the read tools, so
workspace rows and delete placeholders do not leak into client-facing results.
The per-table ``limit`` is enforced, and the response text reports when a table
was truncated so agents can narrow and re-run intentionally.

Schema inspection
=================

GetTableSchema
--------------

Inspect the TCA-derived schema of a TYPO3 table.

:Parameters:
   - ``table`` (string, required): table name
   - ``type`` (string): optional specific record type such as ``text`` for
     ``tt_content``
   - ``workspace_id`` (integer): optional workspace override

The output summarizes table control fields, available record types, and the
fields visible for the selected type.

GetFlexFormSchema
-----------------

Inspect a FlexForm DataStructure by identifier.

:Parameters:
   - ``identifier`` (string, required): FlexForm identifier such as
     ``form_formframework`` or ``*,news_pi1``
   - ``table`` (string): table that contains the FlexForm field, default
     ``tt_content``
   - ``field`` (string): FlexForm field name, default ``pi_flexform``
   - ``recordUid`` (integer): optional compatibility parameter, currently not
     used for resolution
   - ``workspace_id`` (integer): optional workspace override

This tool resolves inline XML, PHP-array DataStructures, and ``FILE:``
references and formats the result as a readable schema summary.

Reading and writing records
===========================

ReadTable
---------

Read records from any accessible TYPO3 table.

:Parameters:
   - ``table`` (string, required): table name
   - ``pid`` (integer): page filter, recommended for page content
   - ``uid`` (integer): single record UID lookup
   - ``filters`` (array): list of ``{field, operator, value}`` objects combined
     with ``AND``. Supported operators: ``eq``, ``neq``, ``lt``, ``lte``,
     ``gt``, ``gte``, ``like``, ``notLike``, ``in``, ``notIn``, ``isNull``,
     ``isNotNull``. Filter values for ``sys_language_uid`` accept ISO codes
     (``"de"``); filters on ``hidden`` accept booleans. System fields
     (``uid``, ``pid``, ``sys_language_uid``, ``hidden``) are always allowed.
   - ``limit`` (integer): maximum number of records, default ``20``
   - ``offset`` (integer): pagination offset
   - ``fields`` (array): explicit field selection
   - ``language`` (string): ISO language code when language support is
     available
   - ``includeTranslationSource`` (boolean): include translation source details
     for translated records when language support is available
   - ``workspace_id`` (integer): optional workspace override

Without a language filter, records from all languages are returned together,
matching TYPO3 list-module behavior more closely than a frontend-style overlay.
Pagination metadata includes ``total``, ``count``, ``limit``, ``offset``,
``nextOffset``, and ``hasMore``.

The legacy ``where`` parameter (a raw SQL string) is intentionally rejected —
callers that still send it get a clear error pointing to ``filters``.

WriteTable
----------

Create, update, translate, move, or delete TYPO3 records.

:Parameters:
   - ``action`` (string, required): ``create``, ``update``, ``move``,
     ``translate``, or ``delete``
   - ``table`` (string, required): table name
   - ``pid`` (integer): parent page for ``create``
   - ``uid`` (integer): record UID for ``update``, ``translate``, ``move``, or
     ``delete``
   - ``data`` (object): field payload for ``create``, ``update``, or
     ``translate``
   - ``position`` (string): create/move position, one of ``top``, ``bottom``,
     ``after:UID``, or ``before:UID``
   - ``translateChildren`` (boolean, ``translate`` action): default ``true``.
     When ``false``, inline children are not auto-localized — useful if you
     plan to translate child records yourself in follow-up calls.
   - ``hidden`` (boolean, ``translate`` action): default ``false``. By default
     translations are created visible; set ``true`` to keep them hidden for
     review.
   - ``allowRootLevelPageCreation`` (boolean, ``create`` action for ``pages``):
     default ``false``. Creating pages at ``pid=0`` is rejected unless this is
     explicitly ``true``. For new websites, use ``CreateSite`` with
     ``parentPageId`` instead so the site root page is created below an existing
     visible page.
   - ``workspace_id`` (integer): optional workspace override

Important behavior:

- writes always happen in workspace context
- language-aware tables accept ISO codes such as ``de`` for
  ``sys_language_uid`` (resolved per-site — e.g. ``hu`` maps to the language
  UID that the owning site assigns to Hungarian, even when another site uses
  the same UID for a different language)
- file fields can receive ``sys_file`` UIDs or objects with UID and metadata,
  which creates ``sys_file_reference`` rows
- update payloads can use structured search-and-replace operations for text
  fields
- translation creates language overlays from default-language source records
- the ``translate`` response includes ``translationUid`` (live UID),
  ``targetLanguage`` (ISO code from the owning site, not a first-wins guess),
  ``siteIdentifier``, ``slug`` (if a slug field exists on the table), and
  ``hidden`` (reflecting the effective value on the new translation)
- if the follow-up field update fails, the freshly created translation row is
  rolled back so callers are never left with an orphaned source-language
  record

Positioning semantics for ``action=create``
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

The ``position`` parameter is simpler than TYPO3's raw ``DataHandler`` API, but
it maps deliberately to TYPO3 behavior:

- ``bottom``:
  create on the requested page and, for sortable tables, place the record after
  the last visible sibling in the active workspace context
- ``top``:
  create on the requested page and place the record before the first visible
  sibling when the table uses sorting
- ``after:UID``:
  resolve the visible target record and use TYPO3's create-after-record
  behavior
- ``before:UID``:
  resolve the target record, find the previous visible sibling, and translate
  the request to a create-after operation; if the target is already first, this
  becomes a top insert

For ``after:UID`` and ``before:UID``, the reference record wins. If the caller
passes a different ``pid`` than the reference record's actual parent page, the
new record is still created next to the reference record. The returned
``pid`` reflects the actual parent page after ``DataHandler`` positioning.

AttachImage
-----------

Attach one or more images to a TCA **file** field on an existing record. The
image is always scoped to the MCP file sandbox first: either pass
``source.sys_file_uid`` for a file already under the sandbox, or
``source.url`` with a public ``https`` URL (same SSRF and size limits as
``UploadFileFromUrl`` — suitable for direct image URLs such as Unsplash).

Optional ``transform`` / ``renditions`` use TYPO3 FAL ``Image.CropScaleMask``
processing (``maxWidth``, ``maxHeight``, ``minWidth``, ``minHeight``, ``width``,
``height``, ``crop``, ``fileExtension``). Each rendition becomes a separate
sandbox file and ``sys_file_reference``. ``mode`` is ``append`` (keep existing
references) or ``replace`` (only new references). ``reference`` sets metadata
(title, alternative, crop, …) on every new reference.

In a non-live workspace, the tool resolves the *version* row primary key for the
``uid`` you pass (live id), including inline parents such as gallery ``items``,
and keeps ``sys_file_reference`` rows attached to that row after DataHandler
processing. See :doc:`../Architecture/InlineRelations` (File references) for
why this matters when images appear missing on staging or after publish.

:Parameters:
   - ``table`` (string, required)
   - ``uid`` (integer, required): live record UID
   - ``field`` (string, required): TCA file field name
   - ``source`` (object, required): ``url`` **or** ``sys_file_uid``
   - ``transform`` (object): single CropScaleMask configuration
   - ``renditions`` (array): list of transform objects; if set, ``transform`` is ignored
   - ``reference`` (object): optional ``sys_file_reference`` metadata
   - ``mode`` (string): ``append`` or ``replace``, default ``append``
   - ``workspace_id`` (integer): optional workspace override

Workspace tool
==============

ListWorkspaces
--------------

List the workspaces available to the current user.

This tool is read-only and does not accept parameters. It shows:

- workspace ID
- title
- access level
- active workspace marker

Clients can pass the returned ``workspace_id`` to record-backed tools when they
need explicit workspace selection. Otherwise the extension auto-selects or
creates a suitable workspace.

File tools
==========

The extension exposes two tiers of file tools:

- **FAL-wide, read-only** — ``ListStorages``, ``BrowseFolder``,
  ``SearchFile``, ``SearchMedia``. These inspect any TYPO3 file storage the
  backend user can access.
- **Sandbox-scoped, read/write** — ``BrowseFiles``, ``ReadFileMetadata``,
  ``WriteFile``, ``UploadFile``, ``UploadFileFromUrl``. These are restricted
  to the configured MCP file sandbox (default ``1:/mcp/``).

ListStorages
------------

List all TYPO3 FAL file storages visible to the current user.

:Parameters:
   - ``includeOffline`` (boolean): include storages that are currently
     offline (default ``false``)

Returns storage UIDs, names, and capability flags (public, writable,
default). Use the returned UID to drive ``BrowseFolder`` / ``SearchFile``
with combined identifiers like ``1:/user_upload/``.

BrowseFolder
------------

Browse folder contents in any file storage the user can access.

:Parameters:
   - ``folder`` (string): combined identifier such as ``1:/user_upload/``;
     omit or use ``/`` for the root of the default storage
   - ``recursive`` (boolean): list nested folders

Useful for auditing where media is stored outside the MCP sandbox. Read-only.

SearchFile
----------

Search FAL for existing files across storages.

:Parameters:
   - ``name`` (string): partial, case-insensitive filename match
   - ``extension`` (string): comma-separated extensions, e.g. ``png,jpg,svg``
   - ``folder`` (string): restrict search to a folder
   - ``mimeType`` (string): filter by MIME type prefix, e.g. ``image/``
   - ``limit`` (integer): max results

Returns file UIDs that can be passed to ``AttachImage`` or ``WriteTable`` when
wiring records up to existing media.

BrowseFiles
-----------

Browse folders inside the MCP file sandbox. In DDEV / ``localUnsafeMode=on``,
combined identifiers can point to any FAL storage/folder the backend user may
access.

:Parameters:
   - ``path`` (string): relative folder path or combined identifier inside the
     sandbox; in local mode, combined identifiers may point outside the sandbox
   - ``recursive`` (boolean): include subfolder listing

Omit ``path`` to inspect the configured sandbox root, current workspace upload
folder, and upload-folder behavior.

ReadFileMetadata
----------------

Read metadata for a file inside the MCP file sandbox. In DDEV /
``localUnsafeMode=on``, combined identifiers can point to files outside the
sandbox.

:Parameters:
   - ``uid`` (integer): file UID
   - ``identifier`` (string): relative file path or combined identifier inside
     the sandbox; in local mode, combined identifiers may point outside the
     sandbox

The result includes core file data, metadata fields, categories, and a summary
of where the file is referenced.

WriteFile
---------

Create or overwrite a text-based file inside the MCP file sandbox, or update
metadata on an existing file. In DDEV / ``localUnsafeMode=on``, combined
identifiers can point outside the sandbox.

:Parameters:
   - ``path`` (string, required): relative path or combined identifier inside
     the sandbox; in local mode, combined identifiers may point outside the
     sandbox
   - ``content`` (string): text content to write
   - ``overwrite`` (boolean): overwrite an existing file
   - ``metadata`` (object): title, description, alternative text, copyright

This tool supports TYPO3 text file extensions such as ``.txt``, ``.html``,
``.css``, ``.js``, ``.json``, ``.xml``, ``.csv``, ``.yaml``, ``.md``,
``.rst``, and others configured in TYPO3.

.. note::

   ``.svg`` is intentionally excluded from the default text-file allowlist
   because SVG can carry inline scripts when served from ``fileadmin/``.
   Operators who need SVG creation must opt in through
   ``$GLOBALS['TYPO3_CONF_VARS']['SYS']['textfile_ext']`` and sanitize SVG
   content before serving it.

.. warning::

   Physical files are not workspace-versioned. Overwriting a file changes the
   underlying file immediately across all workspaces.

UploadFile
----------

Upload a binary or text file into the MCP file sandbox via base64.

:Parameters:
   - ``path`` (string, required): requested target path inside the sandbox
   - ``content_base64`` (string, required): base64 payload or data URL
   - ``metadata`` (object): optional file metadata

Upload behavior:

- the requested folder path is respected
- the stored filename is randomized
- existing files are never overwritten
- uploads can be routed into workspace-specific folders inside the sandbox

UploadFileFromUrl
-----------------

Download a public HTTP or HTTPS URL server-side and store the result in the MCP
file sandbox.

:Parameters:
   - ``url`` (string, required): public file URL
   - ``path`` (string): target path inside the sandbox, derived from the URL if
     omitted
   - ``metadata`` (object): optional file metadata

Security measures include:

- allow-listing only ``http`` and ``https``
- rejecting private and reserved network targets after DNS resolution
- streaming downloads with a 20 MB size limit
- limiting redirects and request timeout
- relying on TYPO3 file validation when the file is stored

Media search
============

SearchMedia
-----------

Search for files across all TYPO3 file storage by metadata, type, or
dimensions.

:Parameters:
   - ``keyword`` (string): search in file name, metadata title, description,
     and alternative text
   - ``mimeType`` (string): MIME type prefix filter, e.g. ``image/``,
     ``application/pdf``
   - ``extension`` (string): file extension filter, e.g. ``jpg``, ``pdf``
   - ``folder`` (string): folder path prefix filter
   - ``minWidth`` (integer): minimum image width in pixels
   - ``minHeight`` (integer): minimum image height in pixels
   - ``createdAfter`` (string): ISO date, only files created after this date
   - ``createdBefore`` (string): ISO date, only files created before this date
   - ``limit`` (integer): maximum results, default ``50``, max ``200``
   - ``offset`` (integer): pagination offset

At least one filter parameter is required. Unlike the sandbox-restricted file
tools, ``SearchMedia`` searches across all FAL storage (read-only).

The result includes file UID, name, identifier, MIME type, dimensions, and
metadata summary. Use ``ReadFileMetadata`` for full details on a specific file.

Content quality and diagnostics
===============================

ContentAudit
------------

Audit a page tree for content quality and SEO issues.

:Parameters:
   - ``rootPageId`` (integer): root page to audit, default ``1``
   - ``checks`` (array): check types to run, default: all available checks
   - ``depth`` (integer): maximum page tree depth, default ``5``, max ``10``
   - ``limit`` (integer): maximum issues per check type, default ``50``
   - ``workspace_id`` (integer): optional workspace override

Available checks:

- ``missing_meta_description``: pages with empty meta description
- ``missing_alt_text``: image file references without alternative text
- ``empty_content``: text content elements with empty body
- ``pages_without_content``: pages that have no content elements
- ``missing_page_title``: pages with no title

The result is grouped by check type with per-issue details (page UID, title,
content UID where applicable) and a summary count per check.

GetSystemLog
------------

Read TYPO3 system log entries for debugging.

:Parameters:
   - ``severity`` (integer): minimum severity level (0–4), default ``0``
   - ``action`` (integer): filter by action type
   - ``component`` (string): filter by log component
   - ``tablename`` (string): filter by affected table name
   - ``userId`` (integer): filter by backend user (admin only)
   - ``since`` (string): ISO datetime, only entries after this time
   - ``until`` (string): ISO datetime, only entries before this time
   - ``limit`` (integer): maximum entries, default ``50``, max ``500``
   - ``offset`` (integer): pagination offset
   - ``workspace_id`` (integer): optional workspace override

Admin users see all entries; non-admin users see only their own log entries.
Severity levels map to PSR-3: 0=info, 1=notice, 2=warning, 3=error, 4=fatal.

Workspace operations
====================

WorkspaceReview
---------------

Review all pending changes in a workspace before publishing.

:Parameters:
   - ``table`` (string): optional table filter
   - ``limit`` (integer): maximum changes, default ``100``, max ``500``
   - ``offset`` (integer): pagination offset
   - ``workspace_id`` (integer): optional workspace override

For each changed record, the result includes the version state (``new``,
``modified``, ``deleted``, ``moved``), the record label, and for modified
records a field-level diff showing live vs. draft values.

This tool closes the draft → review → publish workflow by letting the AI
inspect what will be published.

Record duplication
==================

CopyContent
-----------

Copy/duplicate a record to the same or different page.

:Parameters:
   - ``table`` (string, required): table name
   - ``uid`` (integer, required): source record UID
   - ``targetPid`` (integer, required): destination page ID
   - ``overrides`` (object): optional field values to override in the copy
   - ``workspace_id`` (integer): optional workspace override

The copy is created through TYPO3's ``DataHandler`` copy command, which
preserves all field values, file references, and relations automatically.
The copy is workspace-safe. Overrides are applied after the copy is created.

System maintenance
==================

SafeCli
-------

Execute a whitelisted TYPO3 CLI command.

:Parameters:
   - ``command`` (string, required): command name from the allowed list
   - ``arguments`` (array): optional command arguments, validated per command

Allowed commands:

- ``cache:flush`` (option: ``--group``)
- ``cache:warmup``
- ``referenceindex:update``
- ``extension:list``
- ``site:list``
- ``site:show``

Arguments are validated against a per-command allowlist, and shell injection
characters are rejected. Each command has an individual timeout. The result
includes stdout, stderr, exit code, and execution time.

ApplyShadcnPreset
-----------------

Apply a shadcn/ui preset to an existing frontend project via
``shadcn apply --preset``. Use this when a preset is copied from
``https://ui.shadcn.com/create`` and should change the current project theme,
fonts, icons, and related shadcn files.

:Parameters:
   - ``preset`` (string, required): preset code such as ``b0`` or
     ``bkqYkPSa0``, or a full
     ``https://ui.shadcn.com/create?preset=...`` URL
   - ``only`` (string or array): optional partial apply; allowed values are
     ``theme`` and ``font``
   - ``cwd`` (string): optional project-root-relative frontend directory for
     monorepos
   - ``packageManager`` (string): ``auto`` (default), ``npx``, ``pnpm``,
     ``yarn``, or ``bun``

The tool is admin-only because it rewrites local project files. It runs
non-interactively with ``--yes`` and returns stdout, stderr, exit code, working
directory, selected package runner, and execution time.

This tool is intentionally a frontend project mutator, not a TYPO3 template
set generator. Use it against the consuming sitepackage or frontend workspace
that owns the design system. Desiderio-specific Fluid templates, Visual Editor
content-area markup, CSS tokens and shadcn component recipes should live in
Desiderio or another sitepackage, not in EXT:mcp_server.

Workspace publishing
====================

PublishWorkspace
----------------

Publish pending workspace changes to live.

:Parameters:
   - ``table`` (string): optional single-table filter
   - ``tables`` (array): optional list of tables to publish (combined with
     ``table`` if both are set)
   - ``onlyTranslations`` (boolean): when ``true``, only publish records whose
     ``sys_language_uid`` is greater than zero — useful to ship translations
     separately from source-language edits
   - ``dryRun`` (boolean): if true (default), preview what would be published
     without executing. Set to false to actually publish.
   - ``workspace_id`` (integer): optional workspace override

By default this tool runs in **dry-run mode** and returns a preview of what
would be published (tables, record counts, UIDs). The AI must explicitly set
``dryRun=false`` to publish. Publishing is irreversible — changes become live
immediately.

Use ``WorkspaceReview`` first to inspect field-level diffs, then
``PublishWorkspace`` with ``dryRun=true`` to confirm the scope, then
``dryRun=false`` to execute.

Batch operations
================

BulkWrite
---------

Execute multiple write operations in a single DataHandler transaction.

:Parameters:
   - ``operations`` (array, required): list of operation objects, each with:

     - ``action`` (string, required): ``create``, ``update``, or ``delete``
     - ``table`` (string, required): table name
     - ``uid`` (integer): record UID (required for update and delete)
     - ``pid`` (integer): page ID (required for create)
     - ``allowRootLevelPageCreation`` (boolean): ``create`` with
       ``table=pages`` only. Defaults to ``false``; ``pid=0`` page creation is
       rejected unless this is explicitly ``true``. For new websites, use
       ``CreateSite`` with ``parentPageId`` instead.
     - ``data`` (object): flat field values for create or update (no inline
       children)

   - ``workspace_id`` (integer): optional workspace override

Maximum 50 operations per call. All operations execute atomically in a single
DataHandler invocation. The result includes per-operation success/failure
status and new UIDs for creates.

BulkWrite explicitly **does not** support inline child records inside ``data``
(nested child arrays, ``sys_file_reference`` objects, nested containers).
Passing such payloads returns a structured validation error pointing to
``WriteTable``, which preprocesses inline relations correctly. Use BulkWrite
for flat field updates (unhiding translations, bulk SEO edits, bulk delete);
use ``WriteTable`` for anything that needs positioning, translation,
search-and-replace, or nested children.

Content import
==============

ImportContent
-------------

Analyze raw content (text, Markdown, or HTML) and either propose TYPO3
content elements for review or create them directly.

:Parameters:
   - ``content`` (string, required): raw content to import
   - ``targetPid`` (integer, required): target page ID
   - ``mode`` (string): ``analyze`` (default) or ``execute``
   - ``format`` (string): format hint — ``auto`` (default), ``markdown``,
     ``html``, or ``text``
   - ``colPos`` (integer): column position, default ``0``
   - ``workspace_id`` (integer): optional workspace override

The tool detects the content format, splits it into logical sections (headings,
paragraphs, tables, code blocks, images), and maps each section to the
best-fitting CType from what is actually available to the current user.

In ``analyze`` mode, the result is a JSON proposal — an array of element
objects with ``CType``, ``header``, ``bodytext``, ``header_layout``, and a
human-readable ``summary``. No records are created in this mode. The chatbot
can review and adjust the proposal, then call ``BulkWrite`` or rerun
``ImportContent`` in ``execute`` mode.

In ``execute`` mode, the tool creates the content elements directly via
DataHandler instead of returning a proposal. The result includes the UIDs
of the created records.

Supported format features:

- **Markdown**: headings, paragraphs, code fences, lists, images, horizontal
  rules
- **HTML**: block-level elements (headings, paragraphs, tables, pre/code,
  lists, images)
- **Plain text**: paragraph splitting on double newlines, heading detection by
  heuristic

CType mapping is dynamic: the tool queries TCA for all available CTypes, builds
field profiles (bodytext, header, image, assets), and scores each CType against
the section's needs. Works with core types, extension types, and custom content
blocks automatically.

Workspace rollback
==================

RollbackWorkspace
-----------------

Discard pending workspace changes.

:Parameters:
   - ``table`` (string): optional table filter — only discard changes for this
     table
   - ``uid`` (integer): optional record UID — discard only this record's
     changes; requires ``table``
   - ``dryRun`` (boolean): if true (default), preview what would be discarded.
     Set to false to actually discard.
   - ``workspace_id`` (integer): optional workspace override

This tool is the counterpart to ``PublishWorkspace``. Use it when an import or
bulk operation produced unwanted results and you want to undo the workspace
changes instead of publishing them. Discarding is irreversible.

URL redirects
=============

ManageRedirects
---------------

Inspect TYPO3 URL redirects (``sys_redirect``).

:Parameters:
   - ``action`` (string, required): ``list``, ``create``, or ``delete``
   - ``source_host`` (string): host filter for list, or source host for create
   - ``source_path`` (string): path filter (LIKE) for list, or source path for
     create (must start with ``/``)
   - ``target`` (string): redirect target URL or page path (required for create)
   - ``target_statuscode`` (integer): HTTP status code, default ``301``. Allowed:
     301, 302, 303, 307
   - ``force_https`` (boolean): redirect to HTTPS, default false
   - ``respect_query_parameters`` (boolean): include source query parameters in
     redirect matching for create, default false
   - ``uid`` (integer): redirect UID (required for delete)
   - ``limit`` (integer): max results for list, default 50
   - ``offset`` (integer): pagination offset for list
   - ``workspace_id`` (integer): optional workspace override

If the redirects surface is not available on the current TYPO3 instance, the
tool returns configuration guidance instead of a raw table-access failure.

If the redirects extension is available, ``list`` works normally. On standard
TYPO3 installs ``sys_redirect`` is not workspace-capable, so ``create`` and
``delete`` return an explicit workspace-safety error instead of editing live
redirect rows. If an instance provides workspace-capable redirects, the same
tool can create and delete through the workspace-safe path.

Content import from URL
=======================

ImportFromUrl
-------------

Fetch a web page and propose or create TYPO3 content from it.

:Parameters:
   - ``url`` (string, required): URL to fetch
   - ``targetPid`` (integer, required): parent page ID
   - ``mode`` (string): ``analyze`` (default) or ``execute``
   - ``colPos`` (integer): column position, default ``0``
   - ``pageType`` (integer): doktype for the new page, default ``1``
   - ``workspace_id`` (integer): optional workspace override

In ``analyze`` mode, returns a proposal with extracted title, slug, content
sections, and image URLs. In ``execute`` mode, creates the page and content
elements directly via DataHandler.

SSRF protection: only ``http``/``https`` URLs are allowed, private/reserved IP
ranges are rejected after DNS resolution.

Site management
===============

CreateSite
----------

Create or update TYPO3 site configurations.

:Parameters:
   - ``action`` (string, required): ``create``, ``update``, ``addLanguage``, or
     ``replaceLanguages``
   - ``identifier`` (string, required): site identifier (alphanumeric + dash)
   - ``rootPageId`` (integer): existing root page UID for ``create``. If the
     root page does not exist yet, omit this and pass ``parentPageId`` instead.
   - ``parentPageId`` (integer): existing visible parent page under which
     ``CreateSite`` creates a new site root page when ``rootPageId`` is omitted.
     This must be a positive page UID. ``parentPageId=0`` is rejected for this
     website creation flow so a new "Home" page is not created outside the
     mounted site tree.
   - ``rootPageTitle`` (string): title for the new root page when
     ``parentPageId`` is used. Defaults to a title derived from ``identifier``.
   - ``rootPageSlug`` (string): slug for the new root page when
     ``parentPageId`` is used. Defaults to ``/`` plus ``identifier``.
   - ``base`` (string): base URL (required for create)
   - ``dependencies`` (array): Site Set names to attach, e.g.
     ``["vendor/site-package"]``. Supported on ``create`` and ``update``.
     If ``create`` has no Site Set, no root-page ``sys_template``, and no
     installed theme/site-package-like Site Set, CreateSite writes a minimal
     site-level ``setup.typoscript`` fallback in TYPO3's active site
     configuration path.
   - ``sets`` (array): alias for ``dependencies`` (some templates expect this
     name). Merged with ``dependencies``.
   - ``settings`` (object): top-level ``settings`` dictionary merged into the
     site config. Supported on ``create`` and ``update``.
   - ``config`` (object, ``update`` only): arbitrary top-level keys to merge
     into the site YAML (``routeEnhancers``, ``errorHandling``, ...).
     Structural keys (``rootPageId``, ``base``, ``languages``) are protected
     and cannot be changed via ``update``.
   - ``defaultLanguage`` (object): default language config (title, locale,
     iso-639-1, optional ``navigationTitle`` and ``flag`` override). Required
     for ``replaceLanguages``.
   - ``languages`` (array): additional languages for ``create`` or
     ``replaceLanguages``, each with optional ``navigationTitle`` and ``flag``
     override
   - ``language`` (object): language to add (required for addLanguage, optional
     ``flag`` override)
   - ``workspace_id`` (integer): optional workspace override

Admin-only. Site configurations are YAML files, not workspace-versioned.
Changes take effect immediately.

If ``flag`` is omitted, CreateSite derives the TYPO3 flag identifier from the
language ISO code (for example ``en`` -> ``us``, ``de`` -> ``de``).

``update`` merges arbitrary top-level keys into an existing site config while
preserving everything else. Use it to attach a Site Set to a site that was
created without one:

.. code-block:: json

   {
     "action": "update",
     "identifier": "launch-2026",
     "dependencies": ["webconsulting/desiderio-preset-corporate"],
     "settings": { "theme": "dark" }
   }

``replaceLanguages`` preserves unrelated site configuration keys like
``settings``, ``routeEnhancers``, and ``dependencies`` while replacing only the
``languages`` section.

If the resulting configuration has no rendering definition (neither a
``dependencies`` entry, a site-level ``setup.typoscript``, nor a
``sys_template`` record on the root page), the response includes a ``warning``
pointing to ``action=update`` with a Site Set.

``create``, ``update``, ``addLanguage``, and ``replaceLanguages`` all reset
the internal ISO⇄UID mapping cache so subsequent translate calls see the new
language layout without restarting the MCP session.

SiteSet
-------

Find installed TYPO3 Site Sets and add or remove one Site Set on an existing
site.

:Parameters:
   - ``action`` (string, required): ``find``, ``add``, or ``remove``
   - ``query`` (string): optional search term for ``find``. Matches Site Set
     name, label, and dependencies.
   - ``includeHidden`` (boolean): include hidden Site Sets in ``find``.
     Defaults to false.
   - ``identifier`` (string): site identifier (required for ``add`` and
     ``remove``)
   - ``siteSet`` (string): Site Set name, e.g. ``typo3/email`` (required for
     ``add`` and ``remove``)
   - ``workspace_id`` (integer): optional workspace override

Admin-only. The assignment is written to the site YAML ``dependencies`` list,
which TYPO3 uses for Site Set resolution. Site configuration files are not
workspace-versioned, so ``add`` and ``remove`` take effect immediately.

``add`` validates that the Site Set exists in TYPO3's Site Set registry and is
idempotent when the set is already attached. ``remove`` can detach a name even
if the extension providing the Site Set is no longer installed, which lets an
operator clean up stale dependencies.

CLI shortcut:

.. code-block:: bash

   vendor/bin/typo3 mcp:site-set --action=find --query=email --json
   vendor/bin/typo3 mcp:site-set --action=add --identifier=main --siteSet=typo3/email
   vendor/bin/typo3 mcp:site-set --action=remove --identifier=main --siteSet=typo3/email

Extension management
====================

InstallExtension
----------------

Install, activate, or search TYPO3 extensions.

:Parameters:
   - ``action`` (string, required): ``require``, ``activate``, or ``search``
   - ``package`` (string): Composer package name (required for require)
   - ``key`` (string): extension key (required for activate)
   - ``query`` (string): search terms (required for search)

Admin-only. Package names must match Composer naming conventions. Extension keys
must be lowercase alphanumeric with underscores. Shell injection characters are
rejected.

Optional x402 monetization
==========================

The following tools are only meaningful when the optional x402 paywall
extension surface is present on the TYPO3 instance. When that surface is
missing, the tools return configuration guidance instead of raw SQL failures.

ListPaidContent
---------------

List pages that are gated by the optional x402 paywall fields on ``pages``.

:Parameters:
   - ``limit`` (integer): maximum number of results, default ``50``, max ``200``
   - ``offset`` (integer): pagination offset
   - ``parentPageUid`` (integer): optional parent page filter

GetPaidContent
--------------

Return either payment requirements or paid content for a page managed by the
optional x402 paywall extension.

:Parameters:
   - ``pageUid`` (integer, required): page UID to inspect
   - ``paymentProof`` (string): optional x402 payment signature payload

If the page is gated and ``paymentProof`` is omitted, the tool returns the
payment requirement payload. If the page is not gated, it returns page content
directly.

GetPaymentStats
---------------

Summarize x402 payment statistics when the optional payment-log table exists.

:Parameters:
   - ``period`` (string): ``today``, ``7days``, ``30days`` (default), or ``all``
   - ``groupBy`` (string): ``page`` (default), ``day``, or ``network``

Verification tools
==================

GetPreviewUrl
-------------

Build a workspace preview URL for a page or content element.

:Parameters:
   - ``table`` (string, required): ``pages`` or ``tt_content``
   - ``uid`` (integer, required): live UID of the record
   - ``language`` (string, optional): ISO code; defaults to record's own language

The preview URL is signed by TYPO3's ``PreviewUriBuilder`` so it can be
opened without a backend login. For ``tt_content`` rows the URL is the
parent page's preview URL with ``#c<uid>`` appended.

Use this right after a write to drop a "see what I just changed" link into
chat for a stakeholder.

RenderRecord
------------

Fetch the rendered frontend HTML for a page in workspace context.

:Parameters:
   - ``pageId`` (integer, required): live page UID
   - ``contentUid`` (integer, optional): when set, the response is reduced
     to the rendered HTML of that single ``tt_content`` element
   - ``mode`` (string): ``html`` (default), ``text`` (strips tags), or
     ``preview`` (URL only, no fetch)
   - ``language`` (string): ISO code
   - ``maxLength`` (integer): cap on response size in characters
     (default 50 000, max 200 000)

Closes the verification loop for an LLM editor: write a record with
``WriteTable``, then ask ``RenderRecord`` whether the result actually
shows up the way it should. Outbound HTTP is gated by the capability
manifest's ``network.outbound`` policy (default ``self`` — only the
TYPO3 instance's own site bases). Redirects are not followed (single
302 to a private IP would bypass the host check), and TLS verification
is enforced unless ``localUnsafeMode`` is enabled (DDEV self-signed
certs).

GetCapabilities
---------------

Return the active capability manifest plus runtime mode (DDEV/local-mode
detection results, enforcement on/off).

No parameters. Always callable — bypasses the manifest gate so a fresh
client can introspect what is and isn't allowed before attempting other
calls.

Useful as the first call of an MCP session: the LLM learns which tools
are available, which subsystems are declared, whether live writes are
unlocked (DDEV/localUnsafeMode), whether dev-site tools are active, and
whether outbound HTTP is open.

Dev-site tools
==============

SiteSettings
------------

List Site Set setting definitions and read or update values in the per-site
``settings.yaml`` file. Validation uses TYPO3's settings type registry (enums,
types, readonly flags from attached Site Sets).

:Parameters:
   - ``action`` (string, required): ``listDefinitions``, ``get``, or ``update``
   - ``identifier`` (string, required): site identifier
   - ``settings`` (object): key/value map for ``update``

Admin-only. Dev-site only. Changes take effect immediately (not workspace-versioned).

ListViewHelpers
---------------

List Fluid ViewHelpers (tag name + XML namespace) from the Composer project.
Dev-site only. Read-only.

GetViewHelperDocumentation
--------------------------

Return markdown documentation for one ViewHelper tag (from ``ListViewHelpers``).

:Parameters:
   - ``tagName`` (string, required): e.g. the tag returned by ``ListViewHelpers``

CreateLocallang
-------------

Create or extend an XLF file under ``Resources/Private/Language/`` in a TYPO3
extension. Existing translation units with the same ``id`` are updated.

:Parameters:
   - ``extensionKey`` (string, required)
   - ``fileName`` (string, required): must end with ``.xlf``
   - ``transUnits`` (array, required): ``[{id, source, target?}, ...]``
   - ``extensionBasePath`` (string): default ``packages`` for non-loaded extensions

Admin-only. Dev-site only.

MCP resources (dev-site)
------------------------

When dev-site mode is active, the MCP server exposes read-only TCA resources:

- ``typo3-mcp://tca`` — overview of tables accessible to the current backend user
- ``typo3-mcp://tca/{tableName}`` — schema summary plus permission-filtered field list

Use ``resources/list``, ``resources/templates/list``, and ``resources/read`` in
MCP clients that support contextual resources.

CLI mirror:

.. code-block:: bash

   vendor/bin/typo3 mcp:tca-resource --json
   vendor/bin/typo3 mcp:tca-resource --table=pages --json
   vendor/bin/typo3 mcp:site-settings --action=listDefinitions --identifier=main --json
   vendor/bin/typo3 mcp:list-viewhelpers --json
   vendor/bin/typo3 mcp:get-viewhelper-documentation --tagName=f:for --json
   vendor/bin/typo3 mcp:create-locallang --extensionKey=my_ext --fileName=locallang.xlf \
     --params '{"transUnits":[{"id":"label","source":"Label"}]}' --json
   vendor/bin/typo3 mcp:install-extension --action=list --json

Editor skills
-------------

Install bundled editor workflow skills (``typo3-content-edit``,
``typo3-translate-page``) into ``.claude/skills/``:

.. code-block:: bash

   vendor/bin/typo3 mcp:install-editor-skills

File safety model
=================

.. warning::

   TYPO3 physical files are not workspace-versioned.

The MCP file sandbox improves safety, but it does not change TYPO3 core file
semantics:

- uploaded files exist immediately
- overwritten text files change immediately
- workspace safety applies to records and file references, not to the physical
  file itself
