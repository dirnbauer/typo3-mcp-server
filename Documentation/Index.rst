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

.. note::

   **Thanks** to `hauptsacheNet <https://github.com/hauptsacheNet>`__ and Marco
   Pfeiffer for the original TYPO3 MCP Server. This manual documents an
   actively maintained line that **updates and extends** that work; some newer
   capabilities are **experimental** and may evolve between releases—validate
   in staging before production use.

The extension gives MCP clients structured access to TYPO3 pages, records,
schemas, workspaces, and selected file operations without bypassing TYPO3's
editorial model.

Highlights
==========

- Read and write TYPO3 content through MCP tools
- Keep record changes in TYPO3 workspaces
- Support translations and language-aware content access
- Inspect table schemas and FlexForms before writing
- Browse, upload, and manage files inside a dedicated MCP file sandbox
- Harden tool execution with a capability manifest and outbound host policy
- Mirror the MCP tool surface through TYPO3 CLI commands
- Connect remote MCP clients using OAuth 2.1 with PKCE

The public tool surface adapts to the current TYPO3 instance. For example,
language parameters are only exposed when multiple site languages exist, and
record tools automatically switch to an appropriate workspace unless a client
explicitly requests a ``workspace_id``.

.. note::

   Physical files are not workspace-versioned in TYPO3. The extension reduces
   file-related risk through a configurable MCP file sandbox and optional
   workspace-specific upload folders, but uploaded files still exist
   immediately once stored.

Documentation map
=================

Start here depending on your role:

- :doc:`Introduction/Index` for the product overview and safety model
- :doc:`Introduction/ForkChanges` for the maintained fork changes
- :doc:`Introduction/IntendedBehavior` for the explicit intended-behavior spec
- :doc:`Installation/Index` for Composer installation and first setup
- :doc:`Configuration/Index` for module, OAuth, workspace, and file sandbox
  configuration
- :doc:`Tools/Index` for the complete MCP tool reference
- :doc:`Testing/Index` for the E2E test suite and CI test workflow
- :doc:`Architecture/Index` for design decisions, implementation layers, and
  deeper architecture notes
- :doc:`Troubleshooting/Index` when something is not working

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
   Testing/Index
   Architecture/Index
   Troubleshooting/Index
