.. include:: /Includes.rst.txt

.. _start:

==========
MCP Server
==========

:Extension key:
   mcp_server

:Package name:
   hn/typo3-mcp-server

:Version:
   |release|

:Language:
   en

:Author:
   Marco Pfeiffer

:License:
   This document is published under the
   `GNU General Public License v2.0 or later <https://www.gnu.org/licenses/gpl-2.0.html>`__

:Rendered:
   |today|

----

A TYPO3 extension that provides a
`Model Context Protocol (MCP) <https://modelcontextprotocol.io/>`__
server for AI-assisted TYPO3 work.

The extension gives MCP clients structured access to TYPO3 pages, records,
schemas, workspaces, and selected file operations without bypassing TYPO3's
editorial model.

Highlights
==========

- Read and write TYPO3 content through MCP tools
- Keep record changes in TYPO3 workspaces
- Support translations and language-aware content access
- Inspect table schemas and FlexForms before writing
- Browse, upload, and manage files inside a dedicated MCP file harness
- Connect remote MCP clients using OAuth 2.1 with PKCE

.. note::

   Physical files are not workspace-versioned in TYPO3. The extension reduces
   file-related risk through a configurable MCP file harness and optional
   workspace-specific upload folders, but uploaded files still exist
   immediately once stored.

Documentation map
=================

Start here depending on your role:

- :doc:`Introduction/Index` for the product overview and safety model
- :doc:`Installation/Index` for Composer installation and first setup
- :doc:`Configuration/Index` for module, OAuth, workspace, and file harness
  configuration
- :doc:`Tools/Index` for the complete MCP tool reference
- :doc:`Architecture/Index` for design decisions and implementation details

Further reading
===============

- :file:`README.md` for the GitHub-facing project overview
- :file:`TECHNICAL_OVERVIEW.md` for the long-form architecture and scenarios

.. toctree::
   :maxdepth: 2

   Introduction/Index
   Installation/Index
   Configuration/Index
   Tools/Index
   Architecture/Index
