.. include:: /Includes.rst.txt

.. _architecture:

============
Architecture
============

.. toctree::
   :maxdepth: 1
   :hidden:

   ImplementationOverview
   WorkspaceTransparency
   LanguageOverlays
   InlineRelations
   CapabilityManifest
   CapabilitiesAndAbilities
   ProtocolMigration
   SecurityAudit

Design decisions
================

Workspace-first
   In strict/production mode, record writes go through a TYPO3 draft workspace
   by default. In trusted local mode, an omitted ``workspace_id`` deliberately
   selects live workspace ``0``; pass a draft ID greater than ``0`` to stage
   locally. This keeps production endpoints review-first while making local
   behavior explicit.

Transparent workspaces
   Internal version-row details are invisible to the MCP client. In strict mode
   tools select or create an appropriate draft; in local mode they select live
   unless the client supplies a draft ID. Client-visible UIDs remain stable and
   live/workspace data are merged transparently.

TCA-driven access
   Table and field access is derived from TCA configuration, not hardcoded
   lists. When a new extension is installed, its tables become available
   automatically.

Language overlay
   Language overlays use TYPO3's ``PageRepository`` API. Workspace overlays
   use a custom implementation for transparency. See
   :doc:`LanguageOverlays` for details.

MCP tool ergonomics
   Tool schemas, annotations, pagination hints, and error shaping follow MCP
   best-practice guidance (see the public ``mcp-builder`` skill). Details:
   :doc:`ImplementationOverview` (“MCP ergonomics”) and the tools overview
   :doc:`../Tools/Index`.

Dual-era protocol
   Stable MCP clients use the ``2025-11-25`` handshake and sessions, while
   release-candidate ``2026-07-28`` clients use stateless requests and
   ``server/discover``. See :doc:`ProtocolMigration`.

Typed capabilities
   TYPO3 Schema API facts, the extension capability manifest, the bundled
   Abilities registry, and MCP protocol capabilities are separate layers. See
   :doc:`CapabilitiesAndAbilities`.

Implementation layers
=====================

The repository is split into a few deliberate layers:

- ``Classes/Http/`` for the remote MCP endpoint and OAuth/discovery endpoints.
  The endpoint calls the SDK's ``HttpServerRunner`` directly. Legacy requests
  receive session headers; ``2026-07-28`` requests remain sessionless.
- ``Classes/Command/`` for the local stdio server and maintenance commands
- ``Classes/MCP/`` for the server factory, tool registry, tool classes, and the
  ``CompatibleToolAdapter`` that wraps third-party tagged tools to the native
  ``ToolInterface``, plus prompt, resource, and skill registries
- ``Classes/Integration/`` for conditional Abilities projections
- ``Classes/Service/`` for shared workspace, TCA, language, file, OAuth, and
  site services
- ``Classes/Utility/`` and ``Classes/Database/Query/Restriction/`` for
  formatting and workspace-specific query behavior

See :doc:`ImplementationOverview` for the request flow and the role of the
main services.

File handling and sandboxing
============================

Physical files in TYPO3 (FAL) are **not** workspace-versioned:

- ``sys_file`` records are read-only through MCP
- ``sys_file_metadata`` records can be read
- ``sys_file_reference`` records are workspace-versioned and can be created
  to attach existing files to content elements
- Folder-based file collections are not workspace-safe; prefer static collections

To reduce risk, MCP file tools are restricted to a configurable file sandbox.

Default sandbox root:

.. code-block:: text

   1:/mcp/

Optional workspace upload folders:

.. code-block:: text

   1:/mcp/workspaces/ws-<id>/

This does not change TYPO3's physical file semantics. It only limits where MCP
is allowed to work and helps separate draft-oriented uploads from other file
areas.

Security
========

See :doc:`SecurityAudit` for the security notes and accepted risks.

Key security measures:

- Access tokens are SHA-256 hashed before database storage
- PKCE is enforced for OAuth authorization flows
- All database queries use parameterized QueryBuilder
- Exception details are logged server-side, not returned to clients
- ``DataHandler->admin = true`` is scoped to workspace creation only
- In strict mode, file writes are restricted to the MCP file sandbox instead of
  unrestricted ``fileadmin`` paths; backend file mounts apply in every mode
- Uploads use randomized stored filenames to reduce predictable file exposure
- Capability manifest gates every tool call and, outside trusted local mode,
  each outbound HTTP path; see :doc:`CapabilityManifest`.
- DDEV / local-mode detection relaxes the workspace-staging,
  non-workspace-table, file-sandbox, and outbound-network safety nets —
  never authentication, backend-user permissions, or per-tool subsystem
  checks.
