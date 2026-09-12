.. include:: /Includes.rst.txt

.. _abilities-integration:

==========================================
Abilities registry as MCP tools
==========================================

.. _abilities-integration-purpose:

Purpose
=======

`webconsulting/typo3-abilities <https://github.com/dirnbauer/typo3-abilities>`__
is one typed, permissioned registry of what a TYPO3 installation can do. Every
ability declares its input and output JSON Schemas, required scopes, risk tier,
side effects and the surfaces it may appear on. CLI commands, the REST
projection at ``/abilities/v1`` and the MCP tools described here are
projections of that registry, never separate implementations.

The package is a production dependency of this extension and installs with it.

.. _abilities-integration-bridge:

How abilities become MCP tools
==============================

Since Abilities 1.0 the registry exposes a protocol-neutral projection,
``Webconsulting\Abilities\Projection\Mcp\McpProjection``, and no longer knows
any MCP SDK symbol. This extension owns the bridge:

``Integration\Abilities\AbilityToolBridge``
   A ``ToolProviderInterface`` service tagged ``mcp.tool_provider``. It asks
   ``McpProjection::descriptors()`` for every ability exposed to the ``mcp``
   surface and returns one ``AbilityTool`` per descriptor.

``Integration\Abilities\AbilityTool``
   An ``AbstractTool`` whose schema is the descriptor's name, description,
   input schema and annotations, and whose ``execute()`` calls
   ``McpProjection::execute()``. The ``AbilityResult`` is encoded as JSON text
   content, with ``isError`` set when the result is not ok.

``MCP\ToolRegistry``
   Collects ``mcp.tool`` services eagerly and ``mcp.tool_provider`` services
   lazily, on first catalog access. Laziness is required: this extension's own
   catalog abilities read the registry they are listed in, which a
   constructor-time resolution would turn into a circular reference.

Names follow the registry: ability ``system/site-info`` becomes the MCP tool
``ability_system_site-info``. Annotations (``readOnlyHint``,
``destructiveHint``, ``idempotentHint``, ``openWorldHint``) are derived from
the registry metadata, so the catalog cannot disagree with governance.

.. _abilities-integration-context:

Execution context
=================

MCP is a trusted abilities surface. The HTTP endpoint or the CLI bootstrap has
already authenticated the backend user and hydrated its permissions, so the
bridge passes ``ExecutionContext::mcp($backendUserUid)``: scope checks are
skipped while the abilities policy, the ability's own permission check, schema
validation and execution traces all still run. Without an authenticated
backend user the tool refuses to execute.

.. _abilities-integration-policy:

Capability policy
=================

Native tools are declared by name in ``capabilities.x-mcp.tools``. Bridged
abilities are gated by the side effects they already declare in the registry,
expressed in the manifest's own subsystem vocabulary:

- a read-only ability requires no subsystem;
- ``database:write`` and friends must be effective subsystems, prerequisite
  chains included;
- ``network:outbound`` requires at least one ``network.outbound`` host rule.

An explicit ``x-mcp.tools`` or ``x-mcp.external_tools`` entry for the tool name
pins a stricter requirement and wins over the derived one. Setting
``x-mcp.integrations.abilities.mcp_bridge`` to ``false`` removes every bridged
ability from the catalog.

.. _abilities-integration-catalog:

Abilities this extension registers
==================================

``typo3-mcp/list-tools``
   Lists the effective MCP catalog and JSON Schemas. Scope:
   ``mcp:tools:read``. Risk: low.

``typo3-mcp/describe-tool``
   Describes one tool contract. Scope: ``mcp:tools:read``. Risk: low.

``typo3-mcp/execute-tool``
   Executes one native tool through the existing registry. Scope:
   ``mcp:tools:execute``. Risk: critical and destructive. **CLI only** — it is
   exposed neither to REST (the trace recorder persists full inputs) nor to
   MCP, where projecting it into the catalog it executes would duplicate every
   native tool behind a second, less specific name.

``typo3-mcp/list-skills`` and ``typo3-mcp/get-skill``
   Expose the bundled editor workflows. Scope: ``mcp:skills:read``. Risk: low.

All of them require a real TYPO3 backend-user context and delegate to the same
native registries, so workspace selection, backend permissions, schema checks,
manifest policy and file sandboxing are unchanged.

.. _abilities-integration-verify:

Verify the projection
=====================

.. code-block:: bash
   :caption: Registry and its MCP projection

   ddev exec vendor/bin/typo3 abilities:list
   ddev exec vendor/bin/typo3 mcp:tool:list --plain | grep ability_
   ddev exec vendor/bin/typo3 mcp:tool ability_system_site-info --json

.. _abilities-integration-rest:

REST
====

The REST projection ships with the Abilities package itself and is served at
``/abilities/v1`` (extension setting ``restBasePath``), authenticated with
abilities tokens or a same-origin backend session. It is not part of this
extension; see the package documentation. Earlier fork releases routed that
projection through a separate API framework; that integration was removed in
0.7.0.
