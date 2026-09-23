.. include:: /Includes.rst.txt

.. _installation:

============
Installation
============

.. _installation-requirements:

Requirements
============

- TYPO3 ``^14.3``
- PHP 8.4 or 8.5
- TYPO3 backend access for the editors who will use MCP
- TYPO3 Workspaces extension, installed as a dependency

Recommended prerequisites
=========================

Before you connect an MCP client, make sure TYPO3 is already configured with:

- at least one backend user who can access the relevant page tree
- table permissions for the records you want MCP to manage
- a writable workspace, or permission for the extension to create one
- a reachable base URL for remote clients

Composer installation
=====================

Install the extension with Composer:

.. code-block:: bash
   :caption: Install the extension with Composer

   composer require hn/typo3-mcp-server

Activate the extension:

.. code-block:: bash
   :caption: Activate the extension

   vendor/bin/typo3 extension:activate mcp_server

The backend module will then be available under :guilabel:`User > MCP Server`.

The project uses ``logiscape/mcp-sdk-php`` v2. The repository's
``composer.lock`` records its tested SDK version; an installation consuming
this extension resolves its own lock file. Test both supported protocol
versions before updating the SDK; see :doc:`../Testing/ProtocolCompatibility`.

First backend check
===================

Open :guilabel:`User > MCP Server`. The module has four tabs:

:guilabel:`Connect a client`
   The MCP endpoint URL with a copy button, and collapsible setup steps for
   Claude (remote, OAuth), Cursor and Codex (local stdio). When a connection
   check fails, the tab starts with a pointer to it.
:guilabel:`Access tokens`
   The tokens of *your* backend user: name, creation, last use and expiry,
   with revoke and revoke-all. :guilabel:`Create access token` in the
   docheader mints a token for clients that cannot run OAuth; the plaintext
   is shown exactly once.
:guilabel:`Connection check`
   Server-side checks of the site URL, the MCP endpoint, both OAuth discovery
   documents, the Authorization header, workspaces, tools, the local CLI,
   internet reachability and your tokens. Each failing check says what to do
   and how to verify it; :guilabel:`Run checks again` repeats them.
:guilabel:`Tools`
   Every tool a connected client is offered, with a filter and badges for
   read-only tools, tools that change data, administrator-only and
   development-only tools.

The module is available in English and German and follows the backend's light
and dark mode.

The common path is:

1. Open :guilabel:`User > MCP Server`.
2. Copy the server URL.
3. Open the panel of your client and follow its steps.
4. Complete OAuth in TYPO3, or create an access token for a client that
   cannot sign in itself.

.. _installation-connection-options:

Connection options
==================

Remote MCP over OAuth
---------------------

This is the recommended setup for remote MCP clients.

1. Open :guilabel:`User > MCP Server`.
2. Copy the server URL shown in the module.
3. Add the server URL to your MCP client.
4. Complete the OAuth flow in TYPO3 when the client requests access.

The module includes setup instructions for multiple client types.

The endpoint serves MCP ``2025-11-25`` and the ``2026-07-28`` release
candidate. Current Codex, Cursor, and Claude product documentation does not
provide a dependable dated revision matrix, so leave stable fallback enabled
and test the installed client version. See
:doc:`../Architecture/ProtocolMigration`.

.. _installation-local-cli:

Local TYPO3 CLI server
----------------------

For local development or shell-based MCP clients, use the TYPO3 CLI command.

Example MCP client configuration:

.. code-block:: json
   :caption: Example local stdio MCP client configuration

   {
     "mcpServers": {
       "my-typo3-site": {
         "command": "php",
         "args": ["vendor/bin/typo3", "mcp:server"]
       }
     }
   }

This is convenient for development, but it uses a different trust model than
the remote OAuth endpoint.

.. caution::

   **Local stdio and the host (guidance for this maintained distribution):**
   The CLI server runs as the **operating-system user** that starts it. TYPO3
   enforces editorial and table permissions for MCP tools, but it does **not**
   sandbox the underlying machine. If your MCP client also exposes a shell or
   terminal—or you launch the server via ``bash``, ``sh``, or other
   wrappers—the effective risk includes **arbitrary host commands** at that
   user’s privilege level (files, environment secrets, system changes beyond
   TYPO3). Use this setup only on **trusted local or non-production** systems,
   with least-privilege OS accounts and without mixing it with production
   secrets or unrestricted terminal access.

   The request path and the services behind it are described in
   :doc:`../Architecture/ImplementationOverview`.

.. _installation-cli-mirror:

CLI mirror (every tool, every shell)
------------------------------------

Every bundled MCP tool is reachable from the TYPO3 CLI, so shell scripts, CI
pipelines, and ``ddev exec`` can drive the same surface as the MCP endpoint.
List what's available:

.. code-block:: bash
   :caption: Discover MCP tool commands

   vendor/bin/typo3 list mcp

Run any registered tool by name:

.. code-block:: bash
   :caption: Generic runner

   vendor/bin/typo3 mcp:tool ReadTable --param table=pages --param pid=1 --json
   vendor/bin/typo3 mcp:tool:list --schema=ReadTable
   vendor/bin/typo3 mcp:prompt:list
   vendor/bin/typo3 mcp:prompt:get typo3-content-edit --request='Edit page 42'

Or use one of the shipped per-tool shortcuts:

.. code-block:: bash
   :caption: Per-tool shortcuts

   vendor/bin/typo3 mcp:read-table --table tt_content --pid 1
   vendor/bin/typo3 mcp:write-table --action create --table pages --pid 1 --param data='{"title": "X"}'
   vendor/bin/typo3 mcp:get-capabilities --json

Output modes:

- ``--json`` — machine envelope ``{ok, result}``
- ``--plain`` or ``--no-ansi`` — plain text without decoration
- (default) — pretty colored output

Use ``--param key=@payload.json`` to pass JSON from a file (constrained to
the TYPO3 project root). Most dedicated shortcuts are
``GenericMcpToolCommand`` service entries in ``Configuration/Services.yaml``;
create a custom ``AbstractMcpToolCommand`` subclass only when a shortcut needs
bespoke options or output formatting.

Bundled Abilities registry
--------------------------

``webconsulting/typo3-abilities`` is a production dependency and installs with
this extension. After ``extension:setup`` its abilities are available through
``abilities:*`` CLI commands and appear in the MCP catalog as ``ability_*``
tools. Until the package is published through Packagist, downstream TYPO3 root
projects must declare its VCS repository before requiring this extension;
Composer deliberately does not inherit repositories from dependencies. See
:doc:`../Integration/Abilities`.

After installation
==================

Continue with:

- :doc:`../Configuration/Index` to configure the file sandbox, capability
  manifest, local-mode toggle, and workspace behavior
- :doc:`../Tools/Index` to review the available MCP tools
- :doc:`../Architecture/CapabilityManifest` to understand the
  declaration-and-enforcement security model
- :doc:`../Testing/ProtocolCompatibility` to verify both MCP eras on the
  installed TYPO3 instance
