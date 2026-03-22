.. include:: /Includes.rst.txt

.. _installation:

============
Installation
============

.. _installation-requirements:

Requirements
============

- TYPO3 v14
- PHP 8.2+
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

First backend check
===================

Open the backend module and verify that you can see:

- the MCP endpoint URL
- the main connection setup tabs
- the remote client setup tabs
- the active token management area
- endpoint status indicators for MCP and OAuth discovery URLs

Backend module setup
====================

The backend module is designed around the most common connection flow first.

At the top of the page you get:

- the remote MCP server URL
- a copy button for the server URL
- endpoint health checks for the MCP and OAuth discovery URLs

The client chooser then provides focused setup steps for:

- Claude Desktop
- n8n
- Manus
- MCP Inspector
- other MCP clients

Separate top-level tabs also cover:

- Remote MCP Setup
- Local Setup (mcp-remote)
- Local Setup (TYPO3 CLI)

The common path is:

1. Open :guilabel:`User > MCP Server`.
2. Copy the server URL.
3. Choose your client tab.
4. Follow the short client-specific setup.
5. Complete OAuth in TYPO3, or create a direct-access token for clients such as
   n8n or Manus.

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

Local TYPO3 CLI server
-----------------------

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

After installation
==================

Continue with:

- :doc:`../Configuration/Index` to configure the file sandbox and understand
  workspace behavior
- :doc:`../Tools/Index` to review the available MCP tools
