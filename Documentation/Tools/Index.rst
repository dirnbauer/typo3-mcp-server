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
- File tools are always restricted to the configured MCP file harness.
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
     - List MCP file harness folders
   * - ``ReadFileMetadata``
     - Read
     - Metadata for a file in the harness
   * - ``UploadFile``
     - Write
     - Upload via base64 into harness
   * - ``UploadFileFromUrl``
     - Write
     - Fetch URL server-side into harness (SSRF-protected)
   * - ``WriteFile``
     - Write
     - Create/replace text file in harness

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
hints.

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

Browse folders inside the MCP file harness.

:Parameters:
   - ``path`` (string): relative folder path or combined identifier inside the
     harness
   - ``recursive`` (boolean): include subfolder listing

Omit ``path`` to inspect the configured harness root, current workspace upload
folder, and upload-folder behavior.

ReadFileMetadata
----------------

Read metadata for a file inside the MCP file harness.

:Parameters:
   - ``uid`` (integer): file UID
   - ``identifier`` (string): relative file path or combined identifier inside
     the harness

The result includes core file data, metadata fields, categories, and a summary
of where the file is referenced.

WriteFile
---------

Create or overwrite a text-based file inside the MCP file harness, or update
metadata on an existing file.

:Parameters:
   - ``path`` (string, required): relative path or combined identifier inside
     the harness
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

Upload a binary or text file into the MCP file harness via base64.

:Parameters:
   - ``path`` (string, required): requested target path inside the harness
   - ``content_base64`` (string, required): base64 payload or data URL
   - ``metadata`` (object): optional file metadata

Upload behavior:

- the requested folder path is respected
- the stored filename is randomized
- existing files are never overwritten
- uploads can be routed into workspace-specific folders inside the harness

UploadFileFromUrl
-----------------

Download a public HTTP or HTTPS URL server-side and store the result in the MCP
file harness.

:Parameters:
   - ``url`` (string, required): public file URL
   - ``path`` (string): target path inside the harness, derived from the URL if
     omitted
   - ``metadata`` (object): optional file metadata

Security measures include:

- allow-listing only ``http`` and ``https``
- rejecting private and reserved network targets after DNS resolution
- streaming downloads with a 20 MB size limit
- limiting redirects and request timeout
- relying on TYPO3 file validation when the file is stored

File safety model
=================

.. warning::

   TYPO3 physical files are not workspace-versioned.

The MCP file harness improves safety, but it does not change TYPO3 core file
semantics:

- uploaded files exist immediately
- overwritten text files change immediately
- workspace safety applies to records and file references, not to the physical
  file itself
