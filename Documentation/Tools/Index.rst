.. include:: /Includes.rst.txt

=====
Tools
=====

The MCP server exposes the following tools to AI clients.

Reading tools
=============

GetPageTree
-----------

Returns the TYPO3 page tree structure starting from a root page.

:Parameters:
   - ``startPage`` (integer): Starting page UID (default: site root)
   - ``depth`` (integer): Tree depth (default: 3)
   - ``language`` (string): ISO language code for translated page titles

GetPage
-------

Returns full page content including content elements and their data.

:Parameters:
   - ``uid`` (integer): Page UID
   - ``url`` (string): Full URL, path, or slug as an alternative to ``uid``
   - ``language`` (string): ISO language code for translated page content

ReadTable
---------

Read records from any accessible TYPO3 table with filtering and pagination.

:Parameters:
   - ``table`` (string, required): Table name
   - ``uid`` (integer): Single record UID
   - ``pid`` (integer): Filter by page ID
   - ``where`` (string): Restricted filter expression using literal comparisons,
     ``AND`` / ``OR``, ``LIKE``, ``IN (...)``, and ``IS NULL`` checks
   - ``language`` (string): ISO language code (e.g. ``de``, ``fr``)
   - ``limit`` (integer): Max records (default: 20)
   - ``offset`` (integer): Pagination offset
   - ``fields`` (array): Specific fields to return
   - ``workspace_id`` (integer, optional): Override the default workspace selection

ListTables
----------

List all tables accessible via MCP, grouped by extension.

GetTableSchema
--------------

Returns the TCA schema for a table including field definitions.

:Parameters:
   - ``table`` (string, required): Table name
   - ``workspace_id`` (integer, optional): Workspace to use

GetFlexFormSchema
-----------------

Returns the FlexForm schema for a specific content element plugin.

:Parameters:
   - ``table`` (string, required): Table name
   - ``uid`` (integer, required): Record UID
   - ``field`` (string): FlexForm field name
   - ``workspace_id`` (integer, optional): Workspace to use

SearchTool
----------

Full-text search across TYPO3 content.

:Parameters:
   - ``terms`` (array, required): Search terms
   - ``termLogic`` (string): ``AND`` or ``OR`` for combining terms
   - ``table`` (string): Limit search to a specific table
   - ``pageId`` (integer): Limit results to a specific page
   - ``language`` (string): ISO language code for language-specific content
   - ``limit`` (integer): Max results per table

Writing tools
=============

WriteTable
----------

Create, update, translate, or delete records.

:Parameters:
   - ``action`` (string, required): ``create``, ``update``, ``translate``, or ``delete``
   - ``table`` (string, required): Table name
   - ``uid`` (integer): Record UID (required for update/delete/translate)
   - ``pid`` (integer): Page ID (required for create)
   - ``data`` (object): Field values
   - ``position`` (string): Positioning for new records (``top``, ``bottom``, ``after:UID``)
   - ``workspace_id`` (integer, optional): Workspace to use

All writes happen in workspace context. Language fields accept ISO codes
(e.g. ``"de"`` instead of numeric language UIDs).

Workspace tools
===============

ListWorkspaces
--------------

List all workspaces accessible to the current user. Shows which workspace
is currently active and the access level for each. Use the returned
``workspace_id`` with record tools when you need to override the strong
default workspace selection.

File tools
==========

BrowseFiles
-----------

Browse file storages and folders (fileadmin).

:Parameters:
   - ``path`` (string): Folder path (e.g. ``1:/user_upload/``)
   - ``recursive`` (boolean): Include subfolders (default: false)

Omit ``path`` to list all available storages.

.. important::

   Physical files are **not** versioned in workspaces. Uploading or
   overwriting a file affects all workspaces immediately.

ReadFileMetadata
----------------

Read detailed metadata for a file.

:Parameters:
   - ``uid`` (integer): File UID
   - ``identifier`` (string): Combined identifier (e.g. ``1:/photo.jpg``)

Returns title, description, alt text, categories, dimensions, and usage references.
