.. include:: /Includes.rst.txt

.. _mcp-basics:

====================
MCP basics with PHP
====================

.. _mcp-basics-purpose:

What MCP is
===========

The `Model Context Protocol
<https://modelcontextprotocol.io/docs/getting-started/intro>`__ (MCP) is a
JSON-RPC protocol through which an AI application can discover and invoke
capabilities supplied by another process. It separates the model-facing
application from the TYPO3-specific implementation.

The main roles are:

Host
   The AI application, for example Codex, Cursor, or Claude.

Client
   The MCP component inside the host. It maintains the protocol connection to
   one server and turns server contracts into model-visible features.

Server
   The process that publishes tools, resources, and prompts. This extension is
   the server; TYPO3 remains responsible for data access and permissions.

MCP does not grant permissions by itself. Authentication identifies a TYPO3
backend user, and TYPO3 permissions, workspaces, TCA, the file sandbox, and the
capability manifest decide what that user may do.

.. _mcp-basics-primitives:

Tools, resources, and prompts
=============================

Tools
   Actions the model may choose to call, such as ``ReadTable`` or
   ``WriteTable``. Each tool has an input JSON Schema and returns text,
   structured JSON, or both.

Resources
   Readable context addressed by a URI. This server publishes bundled skills
   below ``typo3-mcp:///skills`` and, in local mode, TCA resources below
   ``typo3-mcp:///tca``.

Prompts
   User-invoked workflow templates. Hosts often display them as slash
   commands. The bundled ``typo3-content-edit`` and
   ``typo3-translate-page`` skills are projected through ``prompts/list`` and
   ``prompts/get``.

The distinction matters: tools perform operations, resources provide context,
and prompts guide a multi-step workflow. An Agent Skill is not a new MCP
capability type; this server maps a skill onto standard prompts and resources.

.. _mcp-basics-transports:

Transport and lifecycle
=======================

This extension supports two transports:

- ``stdio`` starts ``vendor/bin/typo3 mcp:server`` as a child process. JSON-RPC
  travels over standard input and output. Use it only with trusted local hosts.
- Streamable HTTP accepts authenticated requests at ``/mcp``. Access tokens
  must be sent in the ``Authorization: Bearer`` header.

The server is dual-era. Stable clients can negotiate MCP ``2025-11-25`` with
the ``initialize`` handshake and a session. Preview clients can use the locked
``2026-07-28`` release candidate with ``server/discover`` and independent,
stateless requests. See :doc:`../Architecture/ProtocolMigration` for the exact
differences.

.. _mcp-basics-call-flow:

A typical TYPO3 call
====================

1. The client discovers the server and its tools.
2. The model selects a tool and supplies arguments that match its JSON Schema.
3. The extension authenticates the backend user and checks the manifest.
4. A record tool switches to a writable TYPO3 workspace where required.
5. TYPO3 core APIs perform the read or write.
6. The server returns human-readable content and, for JSON results,
   ``structuredContent`` for modern clients.

Clients should discover contracts instead of hard-coding them. Language,
permissions, installed extensions, and local-mode policy can all change the
effective surface.

.. _mcp-basics-minimal-php:

A minimal PHP server
====================

The following standalone example explains the PHP mechanics. It is not a
replacement for this TYPO3 extension, whose factory adds authentication,
workspaces, permissions, prompts, resources, and runtime policy.

Install the same SDK generation used by this project:

.. code-block:: bash
   :caption: Install the PHP MCP SDK release candidate

   composer require logiscape/mcp-sdk-php:2.0.0-beta3

Save this file next to that project's ``vendor/`` directory:

.. literalinclude:: _codesnippets/_MinimalMcpServer.php
   :caption: server.php
   :language: php

Run ``php server.php`` and the SDK selects stdio. Under a web SAPI,
``run()`` selects HTTP. The callback arguments are derived from PHP parameter
types, while ``outputSchema`` declares the machine-readable result contract.
The v2 SDK serves stable and ``2026-07-28`` clients from the same code.

.. _mcp-basics-php-typo3:

How the TYPO3 server differs
============================

This extension registers services tagged ``mcp.tool`` instead of placing
callbacks in one script. ``McpServerFactory`` builds the catalog,
``ToolRegistry`` rejects duplicate names, and ``ToolResultNormalizer`` retains
legacy text while adding structured JSON where possible.

Tool code delegates to TYPO3 APIs:

- ``DataHandler`` performs record writes.
- ``TcaSchemaFactory`` and TCA schema capabilities describe table semantics.
- ``PageRepository`` handles language overlays.
- FAL handles files and references.
- TYPO3 backend users, page mounts, table permissions, and workspaces remain
  authoritative.

The same application service is reachable through MCP, TYPO3 ``mcp:*``
commands, the bundled Abilities registry, and its opt-in REST projection. A projection must not
reimplement the underlying write behavior.

.. _mcp-basics-safety:

Safety rules for clients
========================

- Discover tools and schemas at runtime.
- Treat tool descriptions as contracts, not permission grants.
- Review workspace changes before publishing.
- Keep bearer tokens out of URLs, logs, prompts, and client configuration
  committed to source control.
- Remember that physical file writes take effect immediately because TYPO3
  does not workspace-version files.
- Keep stable-protocol fallback enabled until a target host proves that it
  speaks the ``2026-07-28`` revision.

.. _mcp-basics-next:

Next steps
==========

- :doc:`../Installation/Index` explains installation and client connection.
- :doc:`../Tools/Index` documents the TYPO3 tool surface.
- :doc:`../Architecture/CapabilitiesAndAbilities` separates the four meanings
  of “capability” used in this project.
