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
   SecurityAudit

Design decisions
================

Workspace-first
   Every write operation goes through a TYPO3 workspace. Live data is never
   directly modified. This gives editors full control over what gets published.

Transparent workspaces
   The workspace concept is invisible to the MCP client. Tools automatically
   select an appropriate workspace. The client sees records as if workspaces
   don't exist -- live and workspace data are merged transparently.

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

Implementation layers
=====================

The repository is split into a few deliberate layers:

- ``Classes/Http/`` for the remote MCP endpoint and OAuth/discovery endpoints.
  The MCP endpoint calls the SDK's ``HttpServerRunner`` directly and forwards
  all protocol headers (including ``Mcp-Session-Id``) into the PSR-7 response.
- ``Classes/Command/`` for the local stdio server and maintenance commands
- ``Classes/MCP/`` for the server factory, tool registry, tool classes, and the
  ``CompatibleToolAdapter`` that wraps third-party tagged tools to the native
  ``ToolInterface``
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
- File access is restricted to the MCP file sandbox instead of unrestricted
  ``fileadmin`` paths
- Uploads use randomized stored filenames to reduce predictable file exposure
