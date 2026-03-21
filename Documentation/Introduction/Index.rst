.. include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

What this extension does
========================

TYPO3 MCP Server exposes TYPO3 through the
`Model Context Protocol <https://modelcontextprotocol.io/>`__ so AI assistants
can work with structured TYPO3 data instead of scraping backend HTML.

It is built for editor-facing use cases such as:

- creating or updating content records
- translating content
- searching large sites
- inspecting TCA and FlexForm schemas before writing
- browsing and managing files inside a dedicated MCP file harness

The extension is not a shortcut around TYPO3. It keeps TYPO3 in control.

..  figure:: /Images/FeatureTourPoster.png
    :alt: Feature tour poster for TYPO3 MCP Server
    :class: with-shadow

    Feature tour poster for TYPO3 MCP Server. The rendered video is included in
    :file:`Documentation/Media/typo3-mcp-server-feature-tour.mp4`.

Why TYPO3 needs MCP
===================

TYPO3 backends are designed for people working in forms, lists, and trees.
LLMs need:

- predictable tool calls
- structured responses
- schema descriptions
- safe write paths

MCP provides that interface, and TYPO3 MCP Server maps it back to TYPO3
concepts such as page trees, TCA, workspaces, language overlays, and
DataHandler-based writes.

How it works
============

1. An MCP client connects to TYPO3 over HTTP or stdio.
2. The client authenticates using OAuth, or runs locally through the TYPO3 CLI.
3. The client discovers available tools and their schemas.
4. Read operations return TYPO3 content, structure, and metadata.
5. Record writes happen in workspace context.
6. Editors review and publish workspace changes in TYPO3.

Core principles
===============

Workspace-first writes
----------------------

Record changes are staged in TYPO3 workspaces. Live content is not directly
edited through MCP.

TCA-first modeling
------------------

The extension uses TCA as the source of truth for schemas and validation
behavior wherever possible.

Transparent workspace handling
------------------------------

MCP clients do not need to know TYPO3 workspace internals. The extension
selects or creates a suitable workspace and keeps returned identifiers stable.

File sandboxing
---------------

File tools do not get unrestricted ``fileadmin`` access. They operate inside a
configurable MCP file harness, which defaults to ``fileadmin/mcp/``.

Permission-aware access
-----------------------

The extension runs with the authenticated backend user's permissions. MCP
should never see more than that backend user could manage manually.

Important limitations
=====================

.. important::

   Physical files in TYPO3 are not workspace-versioned.

That means:

- uploading a file stores it immediately
- overwriting a file changes the physical file immediately
- workspace safety applies to records and references, not to the physical file
  itself

The extension mitigates this with a dedicated file harness and optional
workspace-specific upload folders, but it does not pretend TYPO3 files are
versioned when they are not.

Supported versions
==================

- TYPO3 v14
- PHP 8.2 or higher

The extension is aligned with TYPO3 v14 and will keep adapting as v14 and MCP
clients evolve: tool names, parameters, and behavior **may change** between
releases when that improves safety or LLM ergonomics (see also the project
``README.md``).
