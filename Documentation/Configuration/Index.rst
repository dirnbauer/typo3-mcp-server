.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

Backend module
==============

The MCP Server module is available under :guilabel:`User > MCP Server` in the
TYPO3 backend.

The module is the main control center for editors and integrators. From there
you can:

- View the MCP endpoint URL
- Review endpoint reachability checks for the MCP and OAuth discovery URLs
- Review remote and local client setup instructions
- Create and revoke access tokens for supported client types
- See workspace-related warnings when no workspace is configured

OAuth setup
===========

The extension supports OAuth 2.1 with PKCE for authentication. Dynamic
client discovery is exposed through the well-known endpoints used by MCP
clients.

Access tokens are:

- stored as hashes in the database
- scoped to the backend user that created them
- revocable through the backend module

Workspace behavior
==================

All record tools are workspace-aware.

Default behavior:

- if the user is already in a non-live workspace, that workspace is kept
- otherwise the first writable workspace is selected
- if needed, the extension can create an MCP workspace for the user

Explicit behavior:

- tools that operate on records accept ``workspace_id``
- clients can use ``ListWorkspaces`` to inspect available workspaces
- clients only need the public workspace ID, not TYPO3's internal versioning
  details

.. important::

   Live records are not directly edited through the record tools.

File harness configuration
==========================

The extension uses a dedicated file harness so MCP file tools do not receive
unrestricted ``fileadmin`` access.

By default, the harness root is:

.. code-block:: text

   1:/mcp/

This usually maps to:

.. code-block:: text

   fileadmin/mcp/

Extension configuration values
==============================

.. confval:: fileHarnessRoot
   :name: ext-mcp-server-fileHarnessRoot
   :type: string
   :default: '1:/mcp/'
   :required: false

   Combined folder identifier that defines the MCP file harness root.

   All MCP file tools are restricted to this root. Use a combined identifier,
   for example ``1:/mcp/`` or ``1:/ai-content/``.

.. confval:: workspaceUploadSubfolders
   :name: ext-mcp-server-workspaceUploadSubfolders
   :type: boolean
   :default: true
   :required: false

   When enabled, ``UploadFile`` stores uploads below workspace-specific
   subfolders inside the harness root.

   Example:

   .. code-block:: text

      1:/mcp/workspaces/ws-3/images/

Why the harness matters
=======================

The harness improves safety and maintainability:

- AI-generated files stay inside a known directory
- cleanup is easier
- file operations become auditable and predictable
- workspace upload folders reduce collisions between draft and live-oriented
  assets

File safety notes
=================

.. warning::

   TYPO3 does not workspace-version physical files.

This remains true even with the harness:

- uploaded files exist immediately once stored
- text file overwrites change the physical file immediately
- record references and metadata workflows can still be staged in TYPO3
  workspaces

Use the harness and workspace upload folders to reduce risk, not to simulate
physical file versioning.

Configuration checklist
=======================

Use this checklist when rolling out the extension:

- confirm the TYPO3 base URL is correct for remote MCP clients
- verify backend user permissions and page mounts
- verify workspace access
- review the configured file harness root
- decide whether workspace upload subfolders should stay enabled
- test remote OAuth login with your target MCP client
