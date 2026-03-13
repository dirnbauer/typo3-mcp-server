.. include:: /Includes.rst.txt

=============
Configuration
=============

Backend module
==============

The MCP Server module is available under :guilabel:`User > MCP Server`
in the TYPO3 backend. From there you can:

- View the MCP endpoint URL
- Register OAuth clients
- Manage access tokens
- Test MCP tools directly

OAuth setup
===========

The extension supports OAuth 2.1 with PKCE for authentication. Dynamic
client registration is available at the registration endpoint.

Access tokens are stored as SHA-256 hashes in the database and expire
after 30 days.

Workspace behavior
==================

All write operations happen in a TYPO3 workspace:

- If the user already has an active workspace, it is used
- Otherwise, the first writable workspace is selected
- If no workspace exists, one is created automatically
- Tools accept an optional ``workspace_id`` parameter to target a specific workspace
- Use the ``list_workspaces`` tool to discover available workspaces

.. important::

   Live data is never directly edited. The workspace acts as a staging area.
   Changes must be published by an editor through the TYPO3 backend.
