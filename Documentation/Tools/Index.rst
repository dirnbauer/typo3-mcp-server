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
- File tools are always restricted to the configured MCP file sandbox.
- Several tools return human-readable text, while record and file write tools
  typically return JSON encoded into MCP text content.

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
     - Read/Write
     - List, create, or delete URL redirects (sys_redirect)
   * - ``ImportFromUrl``
     - Read/Write
     - Fetch URL content and propose or create page with elements
   * - ``CreateSite``
     - Write
     - Create or update TYPO3 site configurations (admin-only)
   * - ``InstallExtension``
     - Execute
     - Install, activate, or search TYPO3 extensions (admin-only)

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
- ``WriteTable``
- ``ContentAudit``
- ``GetSystemLog``
- ``WorkspaceReview``
- ``CopyContent``
- ``PublishWorkspace``
- ``BulkWrite``
- ``ImportContent``
- ``RollbackWorkspace``
- ``ManageRedirects``
- ``ImportFromUrl``
- ``CreateSite``

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
   - ``url`` (string): full URL, path, or slug
   - ``language`` (string): ISO language code for translated page and content
     output when language support is available
   - ``languageId`` (integer): deprecated numeric language ID
   - ``workspace_id`` (integer): optional workspace override

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
   - ``terms`` (array, required): search terms
   - ``termLogic`` (string): ``AND`` or ``OR``, default ``OR``
   - ``table`` (string): restrict search to a specific table
   - ``pageId`` (integer): restrict search to one page
   - ``language`` (string): ISO language code when language support is
     available
   - ``limit`` (integer): maximum results per table, default ``50``
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
   - ``where`` (string): restricted filter expression
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

WriteTable
----------

Create, update, translate, or delete TYPO3 records.

:Parameters:
   - ``action`` (string, required): ``create``, ``update``, ``translate``, or
     ``delete``
   - ``table`` (string, required): table name
   - ``pid`` (integer): parent page for ``create``
   - ``uid`` (integer): record UID for ``update``, ``translate``, or
     ``delete``
   - ``data`` (object): field payload for ``create``, ``update``, or
     ``translate``
   - ``position`` (string): create position, one of ``top``, ``bottom``,
     ``after:UID``, or ``before:UID``
   - ``workspace_id`` (integer): optional workspace override

Important behavior:

- writes always happen in workspace context
- language-aware tables accept ISO codes such as ``de`` for
  ``sys_language_uid``
- file fields can receive ``sys_file`` UIDs or objects with UID and metadata,
  which creates ``sys_file_reference`` rows
- update payloads can use structured search-and-replace operations for text
  fields
- translation creates language overlays from default-language source records

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

If the requested ``pid`` and the resolved position target belong to different
parent pages, the tool returns an error.

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

BrowseFiles
-----------

Browse folders inside the MCP file sandbox.

:Parameters:
   - ``path`` (string): relative folder path or combined identifier inside the
     sandbox
   - ``recursive`` (boolean): include subfolder listing

Omit ``path`` to inspect the configured sandbox root, current workspace upload
folder, and upload-folder behavior.

ReadFileMetadata
----------------

Read metadata for a file inside the MCP file sandbox.

:Parameters:
   - ``uid`` (integer): file UID
   - ``identifier`` (string): relative file path or combined identifier inside
     the sandbox

The result includes core file data, metadata fields, categories, and a summary
of where the file is referenced.

WriteFile
---------

Create or overwrite a text-based file inside the MCP file sandbox, or update
metadata on an existing file.

:Parameters:
   - ``path`` (string, required): relative path or combined identifier inside
     the sandbox
   - ``content`` (string): text content to write
   - ``overwrite`` (boolean): overwrite an existing file
   - ``metadata`` (object): title, description, alternative text, copyright

This tool supports TYPO3 text file extensions such as ``.txt``, ``.html``,
``.css``, ``.js``, ``.json``, ``.xml``, ``.csv``, ``.yaml``, ``.md``, and
others configured in TYPO3.

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

Workspace publishing
====================

PublishWorkspace
----------------

Publish pending workspace changes to live.

:Parameters:
   - ``table`` (string): optional table filter — only publish changes for this
     table
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
     - ``data`` (object): field values for create or update

   - ``workspace_id`` (integer): optional workspace override

Maximum 50 operations per call. All operations execute atomically in a single
DataHandler invocation. The result includes per-operation success/failure
status and new UIDs for creates.

For complex single-record operations (positioning, translation,
search-and-replace), use ``WriteTable`` instead.

Content import
==============

ImportContent
-------------

Analyze raw content (text, Markdown, or HTML) and propose TYPO3 content
elements without creating them.

:Parameters:
   - ``content`` (string, required): raw content to import
   - ``targetPid`` (integer, required): target page ID
   - ``format`` (string): format hint — ``auto`` (default), ``markdown``,
     ``html``, or ``text``
   - ``colPos`` (integer): column position, default ``0``
   - ``workspace_id`` (integer): optional workspace override

The tool detects the content format, splits it into logical sections (headings,
paragraphs, tables, code blocks, images), and maps each section to the
best-fitting CType from what is actually available to the current user.

The result is a JSON proposal — an array of element objects with ``CType``,
``header``, ``bodytext``, ``header_layout``, and a human-readable ``summary``.
No records are created. The chatbot reviews and adjusts the proposal, then
calls ``BulkWrite`` to create all elements at once.

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
   - ``uid`` (integer): optional record UID — discard only this record's changes
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

Manage TYPO3 URL redirects (``sys_redirect``).

:Parameters:
   - ``action`` (string, required): ``list``, ``create``, or ``delete``
   - ``source_host`` (string): host filter for list, or source host for create
   - ``source_path`` (string): path filter (LIKE) for list, or source path for
     create (must start with ``/``)
   - ``target`` (string): redirect target URL or page path (required for create)
   - ``target_statuscode`` (integer): HTTP status code, default ``301``. Allowed:
     301, 302, 303, 307
   - ``force_https`` (boolean): redirect to HTTPS, default false
   - ``uid`` (integer): redirect UID (required for delete)
   - ``limit`` (integer): max results for list, default 50
   - ``offset`` (integer): pagination offset for list
   - ``workspace_id`` (integer): optional workspace override

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
   - ``action`` (string, required): ``create`` or ``addLanguage``
   - ``identifier`` (string, required): site identifier (alphanumeric + dash)
   - ``rootPageId`` (integer): root page UID (required for create)
   - ``base`` (string): base URL (required for create)
   - ``defaultLanguage`` (object): default language config (title, locale,
     iso-639-1)
   - ``languages`` (array): additional languages for create
   - ``language`` (object): language to add (required for addLanguage)
   - ``workspace_id`` (integer): optional workspace override

Admin-only. Site configurations are YAML files, not workspace-versioned.
Changes take effect immediately.

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
