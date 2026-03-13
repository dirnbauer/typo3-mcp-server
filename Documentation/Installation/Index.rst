.. include:: /Includes.rst.txt

============
Installation
============

Requirements
============

- TYPO3 v13.4 or v14.x
- PHP 8.2+
- The TYPO3 Workspaces system extension (``typo3/cms-workspaces``)

Composer installation
=====================

.. code-block:: bash

   composer require hn/typo3-mcp-server

After installation, activate the extension in the TYPO3 Extension Manager
or via CLI:

.. code-block:: bash

   vendor/bin/typo3 extension:activate mcp_server

First steps
===========

1. Go to the backend module :guilabel:`User > MCP Server`
2. Register an OAuth client for your AI assistant
3. Connect the AI assistant using the provided endpoint URL
