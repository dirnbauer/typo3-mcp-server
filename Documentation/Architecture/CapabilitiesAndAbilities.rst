.. include:: /Includes.rst.txt

.. _capabilities-and-abilities:

==========================
Capabilities and abilities
==========================

.. _capabilities-terminology:

Four different meanings
=======================

This project uses “capability” in four related but non-interchangeable ways.

MCP server capabilities
   Protocol data returned by ``initialize`` or ``server/discover``. It tells a
   client that this server implements tools, prompts, or resources. It is not
   a TYPO3 permission system.

TYPO3 Schema API capabilities
   Semantic facts on a TCA schema, queried through ``TcaSchemaFactory`` and
   ``TcaSchemaCapability``. They answer questions such as whether a table is
   workspace-aware, language-aware, sortable, or read-only.

Extension capability manifest
   A static, operator-readable declaration of the extension's database,
   filesystem, network, backend, authentication, and CLI footprint. This
   extension also enforces tool and outbound-host policy from it at runtime.

TYPO3 Abilities API
   An optional registry of executable operations. An ability has typed input
   and output, scopes, risk, side effects, permission logic, and allowed
   projections such as CLI or REST.

None replaces the others. The Schema API describes TYPO3 data semantics, the
manifest declares extension reach, Abilities governs reusable operations, and
MCP capabilities negotiate the wire surface.

.. _capabilities-schema-api:

TYPO3 v14 Schema API
====================

``TableAccessService`` is the semantic facade used by record tools. It obtains
schemas from TYPO3's ``TcaSchemaFactory`` and asks their capabilities instead
of repeatedly interpreting raw ``$GLOBALS['TCA'][<table>]['ctrl']`` keys.

The facade covers workspace and language support, root-level placement,
admin-only and read-only tables, labels, type and sorting fields, timestamps,
hidden fields, translation fields, and system columns. Web mounts and backend
permissions remain explicit checks; a schema capability is a data-model fact,
not authorization.

This aligns the server with the `TYPO3 Schema API
<https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/13.2/Feature-104002-SchemaAPI.html>`__
and avoids v14-deprecated ``BackendUtility`` metadata helpers.

.. _capabilities-manifest-layer:

Capability manifest
===================

The public part of ``Configuration/Capabilities.yaml`` follows version 1.0 of
the `TYPO3 extension capability-manifest proposal
<https://www.webconsulting.at/blog/typo3-extension-security-emdash-capability-manifests>`__.
It uses the proposal's coarse vocabulary:

- ``subsystems`` for database, files, backend module, CLI, middleware, and
  authentication.
- ``database``, ``network``, ``filesystem``, and ``permissions`` for concrete
  reach.
- ``risk`` for operator review.

MCP-specific detail does not invent values in the public subsystem enum. It
lives under the vendor-extension key ``capabilities.x-mcp``:

- stable and preview protocol revisions and transports;
- optional Abilities and ``sg_apicore`` integrations;
- runtime-only subsystems and prerequisite chains;
- the exact MCP tool-to-subsystem map;
- the exact ``mcp:*`` Symfony command inventory;
- bundled skills and the tools each workflow may use.

This answers whether the proposal can represent this extension: **yes** for
the coarse install-time declaration, with ``x-mcp`` for MCP-specific runtime
inventory. :doc:`CapabilityManifest` documents enforcement and hardening.

.. _capabilities-abilities-layer:

Abilities API
=============

When ``webconsulting/typo3-abilities`` is installed, this extension registers
five abilities:

``typo3-mcp/list-tools``
   Lists the effective MCP catalog and JSON Schemas. Scope:
   ``mcp:tools:read``. Risk: low.

``typo3-mcp/describe-tool``
   Describes one tool contract. Scope: ``mcp:tools:read``. Risk: low.

``typo3-mcp/execute-tool``
   Executes one tool through the existing registry. Scope:
   ``mcp:tools:execute``. Risk: critical and destructive, because the selected
   tool may write database rows, files, or use outbound HTTP. This ability is
   CLI-only; remote execution uses native MCP. The upstream Abilities trace
   recorder stores full inputs, so exposing arbitrary tool arguments over REST
   could persist payment proofs, file payloads, or secrets.

``typo3-mcp/list-skills``
   Lists the bundled editor workflows and their prompt/resource projections.
   Scope: ``mcp:skills:read``. Risk: low.

``typo3-mcp/get-skill``
   Returns one bundled workflow in Agent Skills Markdown format. Scope:
   ``mcp:skills:read``. Risk: low.

All five require a real TYPO3 backend-user context. Tool-catalog execution
delegates to ``McpToolCatalogService`` and then to the native tool. Skill
abilities delegate to ``SkillRegistry``. Workspace selection, backend
permissions, schema checks, manifest policy, file sandboxing, and result
normalization therefore remain identical where a native tool executes.

All catalog abilities are exposed to CLI and the four read-only abilities are
also exposed to REST. They are not projected back into MCP. Projecting
``execute-tool`` into the catalog it executes would create a recursive
duplicate surface. Native MCP tools remain authoritative.

.. _capabilities-skills:

Can skills be capabilities?
===========================

Skills are workflow documents, not executable protocol capabilities. Treating
arbitrary Markdown as a permission grant would be unsafe and would not be MCP
interoperable.

The server therefore uses four explicit projections:

- The manifest inventories each skill, its source, whether a user may invoke
  it, and the native tools it expects.
- MCP resources expose the original Markdown below
  ``typo3-mcp:///skills``.
- MCP prompts expose user-invocable skills through ``prompts/list`` and
  ``prompts/get``. Hosts may render these prompt names as slash commands.
- The optional read-only ``list-skills`` and ``get-skill`` abilities expose
  skill metadata and Markdown to governed CLI/REST consumers. They do not
  execute the workflow or grant its referenced tools.

The same prompts are visible from TYPO3 CLI:

.. code-block:: bash
   :caption: Discover and render slash-command workflows

   vendor/bin/typo3 mcp:prompt:list
   vendor/bin/typo3 mcp:prompt:get typo3-content-edit \
       --request='Update page 42' --context='Use the review workspace'

``mcp:prompt:list`` prints names with a leading ``/`` for human discovery. The
slash is presentation; the MCP prompt name remains
``typo3-content-edit``. Shell automation continues to use ``mcp:*`` Symfony
command names.

.. _capabilities-cli:

CLI as a declared projection
============================

Every bundled MCP tool has a dedicated ``mcp:<name>`` command, and
``mcp:tool`` can dispatch any registered tool. Infrastructure commands cover
server transport, diagnostics, OAuth management, tool discovery, prompts,
resources, and skill installation.

The command inventory in ``x-mcp.commands`` is checked against Symfony's
``console.command`` tags in tests. Adding a command without declaring it, or
declaring a stale command, fails the manifest-consistency test.

.. _capabilities-boundaries:

Security boundaries
===================

- A manifest declaration does not bypass TYPO3 permissions.
- An Abilities scope does not bypass the native tool's manifest requirement.
- A schema capability does not grant access to a table or page mount.
- A prompt or skill does not execute until the client calls a permitted tool.
- REST bearer scopes are additive requirements, not replacements for the
  backend user's identity and permissions.

The result is layered defense: protocol negotiation, authenticated actor,
ability policy where installed, manifest policy, TYPO3 permissions, and the
concrete tool's validation all have to agree.
