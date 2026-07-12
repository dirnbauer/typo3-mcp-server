.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

.. _configuration-backend-module:

Backend module
==============

Open :guilabel:`User > MCP Server` to inspect the endpoint URL, OAuth
discovery health, client setup, token management, and workspace warnings.

Remote requests are bound to the authenticated TYPO3 backend user. The
extension creates only an in-memory request session for TYPO3 internals; it
does not turn the bearer token into a persistent backend login.

.. _configuration-authentication:

Authentication and origins
==========================

Remote MCP uses OAuth 2.1 with PKCE. Access and refresh tokens are stored as
SHA-256 hashes and can be revoked from the backend module.

Bearer tokens are accepted **only** from the ``Authorization`` header. A
``?token=`` query parameter is always rejected because URLs leak into access
logs, browser history, referrers, monitoring, and screenshots.

If a browser supplies ``Origin``, the endpoint accepts the exact TYPO3 origin
or an exact origin configured in ``allowedOrigins``. Malformed, ``null``, and
unlisted origins receive 403 before token validation. Non-browser clients may
omit ``Origin``.

.. _configuration-workspaces:

Workspace policy
================

In strict or production mode, record writes stay in a non-live TYPO3
workspace. The extension retains the current writable workspace, selects one,
or creates an MCP workspace where the user has permission.

In DDEV or another explicitly trusted local environment,
``localUnsafeMode`` may default omitted ``workspace_id`` values to live and
relax the file, table, and network safety gates. Authentication, TYPO3
permissions, and manifest tool requirements remain active.

Read :doc:`LiveEditsOnDevelopment` before changing this policy. Production
operators who require an unconditional guard should set
``localUnsafeMode=off`` or ``mcpServer.strictSandbox=1``.

.. _configuration-file-sandbox:

File sandbox
============

The default ``fileSandboxRoot`` is ``1:/mcp/``, normally
``fileadmin/mcp/``. File write tools cannot leave it in strict mode.

Physical files are not workspace-versioned. Uploading or overwriting a file
takes effect immediately even when its references are staged in a workspace.
Optional workspace subfolders reduce collisions but do not change that fact.

.. _configuration-manifest:

Manifest policy
===============

``Configuration/Capabilities.yaml`` declares broad extension capabilities and
the exact tools, commands, skills, integrations, and runtime requirements
under ``x-mcp``. Every native tool call checks this policy. Outbound tools also
check the configured host list before DNS and SSRF validation.

.. code-block:: bash
   :caption: Inspect the active capability and local-mode state

   vendor/bin/typo3 mcp:get-capabilities --json

See :doc:`../Architecture/CapabilityManifest` for hardening examples and
:doc:`../Architecture/CapabilitiesAndAbilities` for the distinction between
the manifest, TYPO3 Schema API, MCP capabilities, and Abilities.

.. _configuration-reference-link:

Settings reference
==================

All extension settings are documented in :doc:`Reference`. The most important
production defaults are:

- ``allowedOrigins`` empty: same-origin browser access only.
- ``enableMcpAuthHeaderDiagnostic`` off.
- ``localUnsafeMode`` auto: strict outside detected development contexts.
- ``enforceCapabilityManifest`` on.
- ``fileSandboxRoot`` set to ``1:/mcp/``.
- ``schemaDetail`` set to concise.

.. _configuration-checklist:

Rollout checklist
=================

- Confirm the TYPO3 site base and trusted-host configuration.
- Verify backend table permissions, page mounts, and workspace access with a
  non-admin test user.
- Review the file sandbox and outbound host list.
- Keep browser origins empty unless a named frontend requires CORS.
- Test OAuth discovery and a real tool call through the target client.
- Test both stable and release-candidate wire tracks when changing the SDK.
- Install Abilities and ``sg_apicore`` only when their additional REST/CLI
  projection is required.

.. toctree::
   :maxdepth: 1

   Reference
   LiveEditsOnDevelopment
