.. include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

What this extension does
========================

**Thank you** to `hauptsacheNet <https://github.com/hauptsacheNet>`__ and Marco
Pfeiffer for the original TYPO3 MCP Server. This documentation describes a
maintained line of that work, including **updates and additional features**;
some of the newer surface is **experimental** and may change—see the project
``README.md`` for rollout guidance.

TYPO3 MCP Server exposes TYPO3 through the
`Model Context Protocol <https://modelcontextprotocol.io/>`__ so AI assistants
can work with structured TYPO3 data instead of scraping backend HTML.

It is built for editor-facing use cases such as:

- creating or updating content records
- translating content
- searching large sites
- inspecting TCA and FlexForm schemas before writing
- browsing and managing files inside a dedicated MCP file sandbox

The extension is not a shortcut around TYPO3. It keeps TYPO3 in control.

Operator setup (endpoint URL, OAuth clients, tokens) is documented under
:doc:`../Configuration/Index` and exposed in the backend module
**User → MCP Server**.

For the explicit product-level specification of how the maintained line is
intended to behave, see :doc:`IntendedBehavior`.

For a detailed explanation of what this fork adds compared with the original
upstream line, see :doc:`ForkChanges`.

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

.. note::

   **Local stdio** (this maintained line): host security is **not** the same as
   TYPO3 permissions. See :ref:`installation-local-cli` in
   :doc:`../Installation/Index` and **Local stdio and the host OS boundary** in
   ``TECHNICAL_OVERVIEW.md`` at the repository root.

Core principles
===============

Workspace-first writes
----------------------

On **production**, record changes are staged in TYPO3 workspaces. Live content
is not directly edited through MCP until a human publishes.

On **DDEV / local development**, MCP now defaults to editing the live copy of
your local site (see :doc:`../Configuration/LiveEditsOnDevelopment` for a
plain-language explanation of this major change and how to turn it off per
user).

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
configurable MCP file sandbox, which defaults to ``fileadmin/mcp/``.

Permission-aware access
-----------------------

The extension runs with the authenticated backend user's permissions. MCP
should never see more than that backend user could manage manually.

Capability-manifest enforcement
-------------------------------

Every tool declares which subsystems it needs (``database:read``,
``file:write``, ``render:frontend``, …). The shipped
``Configuration/Capabilities.yaml`` enumerates them and maps every tool
to its requirements. ``AbstractTool::execute()`` rejects calls whose
required subsystems aren't declared, and outbound HTTP for
``UploadFileFromUrl`` / ``RenderRecord`` is gated by
``network.outbound`` (default: ``self`` only).

Hardening means deleting lines from the manifest. Removing
``database:write`` makes the MCP read-only; keeping the default
``network.outbound: [self]`` or replacing an opened policy with
``[self, 'images.unsplash.com']`` locks outbound HTTP to intentional
targets.

DDEV / local-development mode
-----------------------------

Detection of DDEV environment variables or the TYPO3 Development
application context relaxes the workspace-only-writes, non-workspace-table,
file-sandbox, and outbound-network safety nets so a developer's laptop is
ergonomic to use. Override via the ``localUnsafeMode`` extension setting
(``auto``/``on``/``off``). Authentication, backend-user permissions, and
per-tool subsystem checks stay enforced regardless.

Important limitations
=====================

.. important::

   Physical files in TYPO3 are not workspace-versioned.

That means:

- uploading a file stores it immediately
- overwriting a file changes the physical file immediately
- workspace safety applies to records and references, not to the physical file
  itself

The extension mitigates this with a dedicated file sandbox and optional
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

Acknowledgements
================

**Thanks again** to the original authors at hauptsacheNet and to Marco Pfeiffer
for the open foundation this extension extends. Ongoing **updates and
experimental features** are documented alongside stable behavior; pin Composer
versions and validate upgrades in a non-production environment first.

.. toctree::
   :maxdepth: 1
   :hidden:

   ForkChanges
   IntendedBehavior
