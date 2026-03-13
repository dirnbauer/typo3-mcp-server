.. include:: /Includes.rst.txt

============
Architecture
============

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

File handling
=============

Physical files in TYPO3 (FAL) are **not** workspace-versioned:

- ``sys_file`` records are read-only through MCP
- ``sys_file_metadata`` records can be read
- ``sys_file_reference`` records are workspace-versioned and can be created
  to attach existing files to content elements
- Folder-based file collections are not workspace-safe; prefer static collections

Security
========

See :doc:`SecurityAudit` for the full security audit report.

Key security measures:

- Access tokens are SHA-256 hashed before database storage
- PKCE is enforced for OAuth authorization flows
- All database queries use parameterized QueryBuilder
- Exception details are logged server-side, not returned to clients
- ``DataHandler->admin = true`` is scoped to workspace creation only
