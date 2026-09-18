.. include:: /Includes.rst.txt

.. _usage:

=====
Usage
=====

This page shows what an MCP client can do once it is connected (see
:doc:`../Installation/Index`). The complete parameter reference is
:doc:`../Tools/Index`.

.. _usage-example-session:

Example session
===============

A typical editorial session against ``https://your-site/mcp``:

1. ``GetCapabilities`` - which subsystems are declared, whether local mode is
   on, and what the connected backend user may do.
2. ``GetPageTree`` with ``depth: 2`` - find the page to work on.
3. ``GetTableSchema`` for ``tt_content`` - see the fields and allowed values
   for the element you want to create.
4. ``WriteTable`` with ``action: "create"`` - the record lands in a TYPO3
   workspace (strict mode) or live (trusted local mode).
5. ``GetPreviewUrl`` or ``RenderRecord`` - verify the result without leaving
   the chat.
6. ``WorkspaceReview`` then ``PublishWorkspace`` (dry-run first) - release the
   change, or ``RollbackWorkspace`` to discard it.

.. _usage-capabilities:

Capabilities at a glance
========================

The extension declares its native tools in
:file:`Configuration/Capabilities.yaml`; optional extensions can add tagged
tools at runtime and the bundled Abilities registry appears as ``ability_*``
tools.

- **Discovery and schema** - ``GetCapabilities``, ``ListTables``,
  ``GetTableSchema``, ``GetFlexFormSchema``
- **Navigation and search** - ``GetPageTree``, ``GetPage`` (``uid``,
  ``pageId`` or ``url``), ``Search``
- **Records** - ``ReadTable`` (structured ``filters``), ``WriteTable``,
  ``BulkWrite``, ``CopyContent``, ``AttachImage``
- **Verification** - ``GetPreviewUrl``, ``RenderRecord``
- **Content import** - ``ImportContent``, ``ImportFromUrl``
- **Workspaces** - ``ListWorkspaces``, ``WorkspaceReview``,
  ``PublishWorkspace``, ``RollbackWorkspace``
- **Files (sandboxed)** - ``BrowseFolder``, ``BrowseFiles``, ``WriteFile``,
  ``UploadFile``, ``UploadFileFromUrl``, ``ReadFileMetadata``, ``SearchFile``,
  ``SearchMedia``, ``ListStorages``
- **Diagnostics** - ``ContentAudit``, ``GetSystemLog``, ``ManageRedirects``
- **Admin / operations** - ``CreateSite``, ``SiteSet``, ``SafeCli``,
  ``SolrIndexQueue``
- **Dev-site only** (DDEV / ``localUnsafeMode``) - ``ApplicationInfo``,
  ``TypoScript``, ``PageTsConfig``, ``MiddlewareStack``, ``ListEvents``,
  ``ContentBlocks``, ``LastError``, ``SiteSettings``, ``ListViewHelpers``,
  ``GetViewHelperDocumentation``, ``CreateLocallang``, ``InstallExtension``,
  ``ApplyShadcnPreset`` and the ``typo3-mcp:///tca`` resources
- **Optional x402 monetization** - ``ListPaidContent``, ``GetPaidContent``,
  ``GetPaymentStats`` (fail closed without the paywall extension)

.. _usage-files:

Files and images
================

An assistant can bring files into TYPO3 in four ways:

- ``UploadFileFromUrl`` downloads a public URL server-side; YouTube and Vimeo
  links become TYPO3 online media assets.
- ``UploadFile`` with ``content_base64`` for small or generated files.
- ``UploadFile`` without a payload returns a single-use pre-signed URL; the
  client ``PUT``\ s the raw bytes to ``/mcp_upload`` so binary data never
  passes through the model context.
- ``AttachImage`` links an existing or freshly fetched file to a record
  through a workspace-staged ``sys_file_reference``.

Uploads are create-only: stored names are randomized inside the sandbox,
identical content returns the existing file, executable and
server-configuration names are refused, and ``maxFileSizeMb`` caps every
path. Physical files are not workspace-versioned; only the reference is.

.. _usage-translate:

Translating a page in one call
==============================

.. code-block:: json

   {
     "action": "translate",
     "table": "pages",
     "uid": 474,
     "data": { "sys_language_uid": "hu", "title": "Esemény", "slug": "/esemeny" }
   }

ISO codes are accepted wherever TYPO3 expects a language UID. Translations
are visible by default; pass ``hidden: true`` to keep them in review, and
``translateChildren: false`` to skip inline children.

.. _usage-create-site:

Adding a site configuration
===========================

``CreateSite`` accepts a live root page UID, a base URL, Site Set
dependencies and settings. On ``create`` it also provisions a dedicated
backend group ``Editors: <root page title>`` mounted at the new root, makes it
the owner group of the root page, optionally adds the named ``editors``, and
extends page-tree-restricted workspaces to the new root. Site configuration is
YAML and not workspace-versioned.

.. _usage-cli:

Every tool from the shell
=========================

.. code-block:: bash

   vendor/bin/typo3 mcp:tool:list                       # discover tools
   vendor/bin/typo3 mcp:read-table --table tt_content --pid 1 --json
   vendor/bin/typo3 mcp:tool ReadTable --param table=pages --json
   vendor/bin/typo3 mcp:prompt:get typo3-translate-page --request='Translate page 42 to German'

``--json`` returns a ``{ok, result}`` envelope, ``--plain`` / ``--no-ansi``
strip decoration, ``--param key=@file.json`` reads a payload from the
project root. See :ref:`installation-cli-mirror`.
