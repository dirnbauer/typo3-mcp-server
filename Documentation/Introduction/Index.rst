.. include:: /Includes.rst.txt

============
Introduction
============

What does it do?
================

The MCP Server extension exposes TYPO3's content management capabilities
through the `Model Context Protocol <https://modelcontextprotocol.io/>`__.
This allows AI assistants (Claude, ChatGPT, and others) to read and edit
TYPO3 content without requiring technical knowledge of the TYPO3 backend.

Every change goes through a TYPO3 workspace, so nothing is published until
an editor reviews and approves the changes.

How it works
============

1. An MCP client (AI assistant) connects to the TYPO3 MCP endpoint
2. The client authenticates via OAuth 2.1 with PKCE
3. The client discovers available tools (read pages, write content, etc.)
4. All write operations happen in a workspace
5. An editor publishes approved changes from the workspace

Supported TYPO3 versions
=========================

- TYPO3 v13.4 LTS
- TYPO3 v14.x

PHP 8.2 or higher is required.
