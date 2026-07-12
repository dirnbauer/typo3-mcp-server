.. include:: /Includes.rst.txt

.. _configuration-reference:

============================
Extension settings reference
============================

.. _configuration-reference-tables:

Table visibility
================

.. confval:: additionalReadOnlyTables
   :name: ext-mcp-server-additionalReadOnlyTables
   :type: string
   :default: sys_file
   :required: false

   Comma-separated non-workspace TCA tables that MCP may read but never write.

.. confval:: additionalStandaloneTables
   :name: ext-mcp-server-additionalStandaloneTables
   :type: string
   :default: sys_file_metadata
   :required: false

   Hidden TCA tables exposed as explicit read targets instead of embedded
   child-only tables.

.. _configuration-reference-files:

Files
=====

.. confval:: fileSandboxRoot
   :name: ext-mcp-server-fileSandboxRoot
   :type: string
   :default: 1:/mcp/
   :required: false

   FAL combined folder identifier that contains all strict-mode file
   operations. Do not set it to the full ``fileadmin`` root in production.

.. confval:: workspaceUploadSubfolders
   :name: ext-mcp-server-workspaceUploadSubfolders
   :type: boolean
   :default: true
   :required: false

   Stores uploads below workspace-specific folders such as
   ``1:/mcp/workspaces/ws-3/``. Physical files still take effect immediately.

.. _configuration-reference-security:

HTTP and security
=================

.. confval:: allowedOrigins
   :name: ext-mcp-server-allowedOrigins
   :type: string
   :default: ''
   :required: false

   Comma-separated exact HTTP(S) browser origins in addition to same-origin.
   Entries must include scheme and host, plus the port when non-default. Paths,
   wildcards, ``null``, and partial-host matches are not accepted.

.. confval:: enableMcpAuthHeaderDiagnostic
   :name: ext-mcp-server-enableMcpAuthHeaderDiagnostic
   :type: boolean
   :default: false
   :required: false

   Enables the minimal ``?test=auth`` proxy diagnostic. It reports only
   whether TYPO3 received the authorization header. Enable it temporarily when
   diagnosing a reverse proxy and disable it afterward.

.. confval:: localUnsafeMode
   :name: ext-mcp-server-localUnsafeMode
   :type: options[auto, on, off]
   :default: auto
   :required: false

   Relaxes workspace-only writes, non-workspace-table restrictions, the file
   sandbox, outbound allowlisting, and private-IP checks in trusted local
   development.

   ``auto`` enables this for detected DDEV or TYPO3 Development contexts.
   ``on`` forces it and is unsafe outside a trusted local project. ``off``
   always keeps strict behavior.

   User TSconfig ``options.mcpServer.localUnsafeMode`` overrides this setting.
   The feature flag or User TSconfig value ``mcpServer.strictSandbox=1`` has
   priority and forces strict behavior. See :doc:`LiveEditsOnDevelopment`.

.. confval:: enforceCapabilityManifest
   :name: ext-mcp-server-enforceCapabilityManifest
   :type: boolean
   :default: true
   :required: false

   Rejects tools whose required subsystem chain is not effective and rejects
   outbound hosts outside manifest policy. Turn it off only for temporary
   local diagnosis.

.. _configuration-reference-schema:

Tool schema size
================

.. confval:: schemaDetail
   :name: ext-mcp-server-schemaDetail
   :type: options[concise, full]
   :default: concise
   :required: false

   ``concise`` shortens verbose tool descriptions in ``tools/list`` to save
   model context. ``full`` returns complete descriptions. JSON Schema
   constraints, annotations, and the full ``GetCapabilities`` output remain
   available in both modes.

.. _configuration-reference-removed:

Removed legacy setting
======================

``allowMcpTokenInQueryString`` is no longer supported. The endpoint ignores
an obsolete value and rejects URL tokens. Fix the web server or proxy so it
forwards ``Authorization`` instead of restoring an unsafe fallback.
