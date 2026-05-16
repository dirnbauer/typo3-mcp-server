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

For token-authenticated HTTP requests, the extension also initializes an
anonymous in-memory TYPO3 backend user session for the lifetime of the request.
This keeps ``DataHandler`` update paths that touch session state working
correctly, without creating a persistent backend login.

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

File sandbox configuration
==========================

The extension uses a dedicated file sandbox so MCP file tools do not receive
unrestricted ``fileadmin`` access.

By default, the sandbox root is:

.. code-block:: text

   1:/mcp/

This usually maps to:

.. code-block:: text

   fileadmin/mcp/

Extension configuration values
==============================

.. confval:: fileSandboxRoot
   :name: ext-mcp-server-fileSandboxRoot
   :type: string
   :default: '1:/mcp/'
   :required: false

   Combined folder identifier that defines the MCP file sandbox root.

   All MCP file tools are restricted to this root. Use a combined identifier,
   for example ``1:/mcp/`` or ``1:/ai-content/``.

.. confval:: workspaceUploadSubfolders
   :name: ext-mcp-server-workspaceUploadSubfolders
   :type: boolean
   :default: true
   :required: false

   When enabled, ``UploadFile`` stores uploads below workspace-specific
   subfolders inside the sandbox root.

   Example:

   .. code-block:: text

      1:/mcp/workspaces/ws-3/images/

.. confval:: allowMcpTokenInQueryString
   :name: ext-mcp-server-allowMcpTokenInQueryString
   :type: boolean
   :default: false
   :required: false

   When enabled, the ``/mcp`` endpoint accepts a bearer token in a
   ``?token=…`` query string instead of the ``Authorization: Bearer``
   header.

   .. warning::

      Disabled by default on purpose. Query-string tokens leak into reverse
      proxy logs, access logs, browser history, and referrer headers. Only
      turn this on for legacy clients that cannot send the Authorization
      header, and rotate tokens regularly.

.. confval:: enableMcpAuthHeaderDiagnostic
   :name: ext-mcp-server-enableMcpAuthHeaderDiagnostic
   :type: boolean
   :default: true
   :required: false

   Controls the lightweight ``?test=auth`` diagnostic used by the backend
   module to verify whether a reverse proxy strips the ``Authorization``
   header.

   Disable this on hardened environments that should not expose any
   diagnostic probe to the outside world. The backend module falls back to
   hiding the associated health-check indicator when disabled.

.. confval:: localUnsafeMode
   :name: ext-mcp-server-localUnsafeMode
   :type: options[auto, on, off]
   :default: auto
   :required: false

   Local-development escape hatch. When enabled (``on``, or ``auto`` with a
   DDEV / Development context detected) the workspace-only-writes,
   file-sandbox, and outbound-HTTP safety nets relax:

   - record writes accept ``workspace_id: 0`` (live writes)
   - record writes can target writable non-workspace-capable TCA tables
   - file tools reach any storage / folder, not only ``fileSandboxRoot``
   - outbound HTTP (``UploadFileFromUrl``, ``RenderRecord``) bypasses
     the manifest's ``network.outbound`` allowlist AND the SSRF
     private-IP filter — so DDEV's ``*.ddev.site`` (private IPs) and
     fetches from public CDNs (Unsplash, Pixabay, …) both work without
     editing ``Capabilities.yaml``

   Detection logic looks at ``IS_DDEV_PROJECT``, ``DDEV_PROJECT``,
   ``DDEV_HOSTNAME``, ``DDEV_TLD`` env vars and the TYPO3 application
   context.

   .. warning::

      Setting this to ``on`` outside a trusted local environment removes
      the most important MCP safety nets. Production sites should leave
      this at ``auto`` (the default) — there should be no DDEV vars and no
      Development context in production, so ``auto`` resolves to ``off``
      automatically.

   Authentication (OAuth + backend session) and the capability manifest
   stay enforced even with this on; only the workspace-staging and
   file-sandbox checks are skipped.

   User TSconfig can override the extension setting, which makes TYPO3
   conditions usable for this policy:

   .. code-block:: typoscript

      [applicationContext == "Development/DDEV"]
      options.mcpServer.localUnsafeMode = on
      [else]
      options.mcpServer.localUnsafeMode = off
      [end]

   To force the production safety nets even in DDEV, set either the TYPO3
   feature flag ``mcpServer.strictSandbox``:

   .. code-block:: php

      $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['mcpServer.strictSandbox'] = true;

   Or set User TSconfig:

   .. code-block:: typoscript

      options.mcpServer.strictSandbox = 1

   Strict sandbox mode has priority over ``localUnsafeMode`` and DDEV
   auto-detection. File tools stay inside ``fileSandboxRoot`` and record
   writes stay in TYPO3 workspaces.

.. confval:: enforceCapabilityManifest
   :name: ext-mcp-server-enforceCapabilityManifest
   :type: boolean
   :default: true
   :required: false

   Reject MCP tool calls whose required subsystems are not declared in
   ``Configuration/Capabilities.yaml``. Also enforces the
   ``network.outbound`` allowlist for ``UploadFileFromUrl`` and
   ``RenderRecord``.

   Disable only for local debugging — turning this off opens every
   registered tool plus arbitrary outbound HTTP.

Capability manifest
===================

The shipped ``Configuration/Capabilities.yaml`` declares the subsystems
this MCP exposes (``database:read``, ``database:write``, ``file:write``,
``render:frontend``, …) and maps each tool to the subsystems it requires.
At call time, ``AbstractTool::execute()`` rejects tools whose required
subsystems aren't declared, and ``CapabilityManifestService::assertHostAllowed()``
rejects outbound HTTP to hosts not in ``network.outbound``.

Inspect the active manifest from the CLI:

.. code-block:: bash

   ddev exec ./vendor/bin/typo3 mcp:get-capabilities --json

To **harden** an installation:

- Remove a subsystem (e.g. ``file:write``) from ``capabilities.subsystems``
  to disable every tool that depends on it.
- Replace ``network.outbound: [self]`` with an explicit allowlist of
  domains your editors actually need (``images.unsplash.com``,
  ``*.example.com``, …).
- Remove ``database:write`` to make the MCP read-only.

To **soften** for development:

- Add ``- '*'`` under ``network.outbound`` to allow public-web image
  uploads (the IP-range SSRF check still blocks private addresses).
- Set ``enforceCapabilityManifest = 0`` to bypass the gate entirely.

Why the sandbox matters
=======================

The sandbox improves safety and maintainability:

- AI-generated files stay inside a known directory
- cleanup is easier
- file operations become auditable and predictable
- workspace upload folders reduce collisions between draft and live-oriented
  assets

File safety notes
=================

.. warning::

   TYPO3 does not workspace-version physical files.

This remains true even with the sandbox:

- uploaded files exist immediately once stored
- text file overwrites change the physical file immediately
- record references and metadata workflows can still be staged in TYPO3
  workspaces

Use the sandbox and workspace upload folders to reduce risk, not to simulate
physical file versioning.

Configuration checklist
=======================

Use this checklist when rolling out the extension:

- confirm the TYPO3 base URL is correct for remote MCP clients
- verify backend user permissions and page mounts
- verify workspace access
- review the configured file sandbox root
- decide whether workspace upload subfolders should stay enabled
- test remote OAuth login with your target MCP client
