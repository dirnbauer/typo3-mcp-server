.. include:: /Includes.rst.txt

=====
Tools
=====

The MCP server exposes a focused tool set for TYPO3 navigation, schema
inspection, record operations, workspaces, and file handling.

General notes
=============

- Record tools are workspace-aware and accept ``workspace_id`` when applicable.
- File tools are restricted to the configured MCP file harness.
- Schema tools exist so MCP clients can inspect before they write.

Navigation and discovery
========================

GetPageTree
-----------

Browse the TYPO3 page tree.

:Parameters:
   - ``startPage`` (integer): starting page UID
   - ``depth`` (integer): Tree depth (default: 3)
   - ``language`` (string): ISO language code for translated page titles

GetPage
-------

Resolve a page by URL or UID and return page details plus content context.

:Parameters:
   - ``uid`` (integer): page UID
   - ``url`` (string): full URL, path, or slug
   - ``language`` (string): ISO language code for translated content

ListTables
----------

List TYPO3 tables that are available through MCP, grouped by extension.

ReadTable
---------

Read records from any accessible TYPO3 table.

:Parameters:
   - ``table`` (string, required): table name
   - ``uid`` (integer): single record UID
   - ``pid`` (integer): page UID filter
   - ``where`` (string): restricted filter expression
   - ``language`` (string): ISO language code
   - ``limit`` (integer): record limit
   - ``offset`` (integer): pagination offset
   - ``fields`` (array): explicit field selection
   - ``workspace_id`` (integer): optional workspace override

Search
------

Search across TYPO3 content and records.

:Parameters:
   - ``terms`` (array, required): search terms
   - ``termLogic`` (string): ``AND`` or ``OR``
   - ``table`` (string): restrict to a specific table
   - ``pageId`` (integer): restrict to a page
   - ``language`` (string): ISO language code
   - ``limit`` (integer): maximum results per table

Schema inspection
=================

GetTableSchema
--------------

Inspect the TCA-derived schema of a TYPO3 table.

:Parameters:
   - ``table`` (string, required): table name
   - ``workspace_id`` (integer): optional workspace override

GetFlexFormSchema
-----------------

Inspect a FlexForm schema for a specific record.

:Parameters:
   - ``table`` (string, required): table name
   - ``uid`` (integer, required): record UID
   - ``field`` (string): FlexForm field name
   - ``workspace_id`` (integer): optional workspace override

Writing tools
=============

WriteTable
----------

Create, update, translate, or delete TYPO3 records.

:Parameters:
   - ``action`` (string, required): ``create``, ``update``, ``translate``,
     or ``delete``
   - ``table`` (string, required): table name
   - ``uid`` (integer): record UID for update, delete, or translate
   - ``pid`` (integer): page UID for create
   - ``data`` (object): field payload
   - ``position`` (string): optional positioning for new records
   - ``workspace_id`` (integer): optional workspace override

Language-related fields accept ISO codes where supported, for example ``de``
instead of numeric language UIDs.

.. important::

   Record writes happen in workspace context. The tools are designed so MCP
   clients do not have to understand TYPO3 version rows or workspace overlays.

Workspace tool
==============

ListWorkspaces
--------------

List the workspaces available to the current user, including the currently
active one and the access level for each workspace.

Use the returned ``workspace_id`` with record tools when you need explicit
workspace targeting.

File tools
==========

BrowseFiles
-----------

Browse folders inside the MCP file harness.

:Parameters:
   - ``path`` (string): relative path or combined identifier inside the
     harness
   - ``recursive`` (boolean): include subfolders

Omit ``path`` to inspect the harness root and current upload folder behavior.

.. important::

   File tools do not browse unrestricted ``fileadmin`` paths. They are limited
   to the configured MCP file harness.

ReadFileMetadata
----------------

Read file metadata for a file inside the MCP file harness.

:Parameters:
   - ``uid`` (integer): file UID
   - ``identifier`` (string): relative path or combined identifier inside the
     harness

Returns title, description, alternative text, copyright, dimensions, and usage
references when available.

WriteFile
---------

Create or overwrite text-based files inside the MCP file harness, or update
metadata on existing files.

:Parameters:
   - ``path`` (string, required): relative path or combined identifier inside
     the harness
   - ``content`` (string): text content to write
   - ``overwrite`` (boolean): overwrite existing file
   - ``metadata`` (object): title, description, alternative, copyright

Supported text extensions follow TYPO3's configured text file extensions.

UploadFile
----------

Upload binary or text files into the MCP file harness.

:Parameters:
   - ``path`` (string, required): requested target path inside the harness
   - ``content_base64`` (string, required): base64 payload or data URL
   - ``metadata`` (object): optional file metadata

Upload behavior:

- the requested folder path is respected
- the stored file name is randomized for safer handling
- existing files are not overwritten
- when configured, uploads are stored inside workspace-specific subfolders

File safety model
=================

.. warning::

   TYPO3 physical files are not workspace-versioned.

The MCP file harness improves safety, but it does not change TYPO3 core file
semantics:

- uploaded files exist immediately
- overwritten text files change immediately
- workspace safety applies to records and references, not the physical file
