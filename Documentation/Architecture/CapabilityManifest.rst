.. include:: /Includes.rst.txt

.. _capability-manifest:

===================
Capability manifest
===================

.. _capability-manifest-purpose:

Purpose and status
==================

``Configuration/Capabilities.yaml`` declares the extension's broad reach and
the finer policy applied to every MCP tool. It is based on the
`TYPO3 extension capability-manifest article
<https://www.webconsulting.at/blog/typo3-extension-security-emdash-capability-manifests>`__.

The public part remains compatible with the proposal's version 1.0 schema.
The `v1.0 reference package
<https://github.com/dirnbauer/typo3-capability-manifest/tree/v1.0.2>`__ was
archived on 2026-07-07 and is **not** an installed validator for this project.
``CapabilityManifestService`` and the project's consistency tests provide
local runtime and build-time enforcement.

PHP cannot isolate one TYPO3 extension from another at process level. This
manifest is defense in depth: it gives operators a reviewable declaration and
places explicit checks at tool dispatch and outbound-network choke points.

.. _capability-manifest-layout:

Public and vendor-specific data
===============================

The public section uses only coarse, schema-compatible values:

.. code-block:: yaml
   :caption: Public capability declaration, abbreviated

   capabilities:
     version: '1.0'
     extension: mcp_server
     subsystems:
       - database:read
       - database:write
       - database:schema
       - cache:write
       - file:read
       - file:write
       - backend:module
       - scheduler:task
       - cli:command
       - site:middleware
       - auth:provider
     database:
       own_tables:
         - tx_mcpserver_oauth_clients
         - tx_mcpserver_oauth_refresh_replay
         - tx_mcpserver_oauth_codes
         - tx_mcpserver_access_tokens
       reads: '*'
       writes: '*'
     network:
       outbound:
         - host: self
           purpose: Render workspace previews
           protocol: https
     filesystem:
       paths: ['1:/mcp/', 'Resources/Private/Skills/', './']
       writes: true
     events:
       listens: ['Hn\\McpServer\\Event\\BeforeRecordReadEvent']
       dispatches: ['Hn\\McpServer\\Event\\BeforeRecordWriteEvent']
     risk:
       level: high

Runtime-only concepts live in the vendor key ``x-mcp`` instead of extending
the public subsystem enum:

.. code-block:: yaml
   :caption: MCP runtime policy, abbreviated

   capabilities:
     x-mcp:
       protocol:
         stable_version: '2025-11-25'
         preview_version: '2026-07-28'
         compatibility: dual-era
       runtime_subsystems:
         - workspace:read
         - workspace:write
         - render:frontend
         - project:write
         - network:package-manager
       requires:
         database:write: [database:read]
         workspace:write: [workspace:read, database:write]
       tools:
         ReadTable: [database:read]
         WriteTable: [database:write]
       commands:
         mcp:read-table: {kind: tool, tool: ReadTable}
       skills:
         typo3-content-edit:
           user_invocable: true

The complete file is authoritative. Abbreviated examples are not intended to
be copied over it.

.. _capability-manifest-prerequisites:

Prerequisite chains
===================

A runtime subsystem is effective only when it and its complete prerequisite
chain are declared. For example:

- ``database:write`` requires ``database:read``.
- ``file:write`` requires ``file:read`` and ``database:write``.
- ``workspace:write`` requires ``workspace:read`` and ``database:write``.
- ``site:write`` requires ``database:write``.
- ``render:frontend`` requires ``database:read``.
- ``extension:install`` requires ``database:write``.
- ``cli:safe`` requires ``database:read``.
- ``cache:write``, ``scheduler:task``, and ``project:write`` require
  ``database:write`` so removing the database-write capability really produces
  a read-only MCP surface.
- ``network:package-manager`` requires ``project:write``;
  ``network:scheduler`` requires ``scheduler:task``.

Removing ``database:write`` therefore disables all dependent write families,
even if those family names are still listed.

.. _capability-manifest-enforcement:

Enforcement points
==================

``AbstractTool::execute()``
   Calls ``assertToolAllowed()`` before the concrete tool runs. A registered
   tool missing from ``x-mcp.tools`` fails closed with read and write
   requirements. A declared tool whose requirements are ineffective returns
   an actionable access-denied result.

Direct outbound HTTP
   ``UploadFileFromUrl``, ``ImportFromUrl``, ``RenderRecord``, and the optional
   x402 facilitator verification and settlement used by ``GetPaidContent`` call
   ``assertUrlAllowed()`` before opening a socket. This validates both the URL
   scheme and an exact, wildcard, or ``self`` host rule. The default permits
   only HTTPS requests to configured TYPO3 site hosts. Full A/AAAA public-IP
   validation, DNS pinning, disabled redirects, and bounded response bodies add
   independent layers. The x402 path is reached only when its compatible
   optional adapter and facilitator configuration are available; verification
   failures keep paid content locked.

Subprocess-owned network effects
   ``InstallExtension`` and ``ApplyShadcnPreset`` invoke Composer or a
   JavaScript package runner. Those subprocesses cannot be DNS-pinned by the
   PHP HTTP guard, so both tools are admin-only, dev-site-only, and require the
   explicit ``network:package-manager`` subsystem. ``SolrIndexQueue`` can run
   one preconfigured, UID-validated TYPO3 scheduler task; it declares
   ``scheduler:task`` and ``network:scheduler`` because that task may contact
   its configured Solr service. These are narrow, visible exceptions to the
   direct-HTTP ``network.outbound`` allowlist, not arbitrary URL parameters.

Consistency tests
   Unit tests compare Symfony ``mcp:*`` commands, prerequisites, event and
   filesystem footprint, owned SQL tables, and manifest inventory. A
   functional test boots TYPO3 and compares the real ``ToolRegistry`` against
   native and explicitly allowlisted external tools. Drift fails CI.

The ``GetCapabilities`` tool exposes the decoded manifest, enforcement state,
protocol metadata, commands, skills, and resolved local-mode policy.

.. _capability-manifest-local-mode:

Local-mode exception
====================

DDEV or ``localUnsafeMode=on`` can relax workspace-only writes, the file
sandbox, non-workspace-table writes, and outbound allowlisting for developer
ergonomics. It does **not** disable authentication, backend permissions, or
the per-tool subsystem check.

Production should set ``localUnsafeMode=off`` or
``mcpServer.strictSandbox=1`` when defense in depth must not depend on
environment detection. See :doc:`../Configuration/LiveEditsOnDevelopment`.

.. _capability-manifest-hardening:

Hardening examples
==================

Read-only server
   Remove ``database:write`` and dependent write subsystems. Their tools stay
   discoverable but refuse execution, making policy failure visible.

Fixed outbound hosts
   Keep ``self`` and add structured host entries only for required direct HTTP
   services. Do not use ``*`` in production. Disable the dev-site package
   tools and the Solr scheduler projection separately when subprocess-owned
   network effects are outside the deployment policy.

Single disabled tool
   Remove its real requirement or assign an intentionally undeclared runtime
   subsystem in ``x-mcp.tools``. Record the local policy change so upgrades do
   not restore it accidentally.

Inspect the active result:

.. code-block:: bash
   :caption: Inspect manifest and resolved runtime policy

   ddev exec vendor/bin/typo3 mcp:get-capabilities --json

``enforceCapabilityManifest=0`` bypasses tool and outbound enforcement and is
only appropriate for temporary local diagnosis. It does not remove TYPO3
permissions, but it defeats an important layer and should never be a routine
production setting.

.. _capability-manifest-relationships:

Relationship to Schema and Abilities APIs
=========================================

The manifest describes what the extension may reach. TYPO3's Schema API
describes table semantics. The bundled Abilities registry describes typed
operations and governs their projections. They intentionally share side-
effect vocabulary, but one is not serialized into the other.

Skills and CLI commands can be inventoried under ``x-mcp`` because they are
part of this extension's public surface. Skills remain prompts/resources, not
permission grants; read abilities may list or return their documents
without executing them. See :doc:`CapabilitiesAndAbilities`.
