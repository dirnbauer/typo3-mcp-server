.. include:: /Includes.rst.txt

======================
Workspace transparency
======================

Overview
========

This document describes how the MCP server hides TYPO3 workspace complexity
from MCP clients. The goal is to expose a simplified content model while still
keeping all writes safely inside TYPO3 workspaces.

Background
==========

TYPO3 workspaces provide isolated editing areas before content is published to
 live. The system uses:

- Live records with ``t3ver_wsid = 0``
- Workspace versions of live records
- Version states for create, update, move, and delete handling
- Overlay processing after database queries

TYPO3's default backend approach combines SQL restrictions with
``BackendUtility::workspaceOL()`` post-processing. That works well for human
editors who understand workspace states, but it is not transparent enough for
an MCP API.

Requirements
============

The MCP server needs:

- Transparent operations without exposing workspace mechanics
- Stable record identities for the same logical record
- Automatic workspace handling in every tool
- Deleted records to disappear from results immediately

Implementation
==============

Delete placeholders
-------------------

The custom ``WorkspaceDeletePlaceholderRestriction`` excludes live records that
have a delete placeholder in the active workspace. This prevents deleted
content from leaking into read results.

UID resolution
--------------

The implementation uses two complementary mechanisms:

- ``getLiveUid()`` maps workspace records back to the client-visible live UID
- ``resolveToWorkspaceUid()`` finds the workspace version when a client refers
  to a live UID for updates or deletes

This keeps record identities stable even when TYPO3 internally works with
workspace versions.

Query-time workspace handling
-----------------------------

``ReadTableTool`` resolves both the direct ``uid`` and matching ``t3ver_oid``
records in a workspace. Combined with the delete-placeholder restriction, this
allows clients to address records by their live UID while TYPO3 still reads and
writes the correct workspace version.

Search result normalization
---------------------------

``SearchTool`` applies the same transparency rules by:

- Filtering delete placeholders
- Returning live UIDs instead of internal workspace UIDs
- De-duplicating matching live and workspace versions

Rationale
=========

Using TYPO3's standard overlay flow alone would leave too much logic in
post-processing. The current approach keeps workspace handling closer to the
query, which improves consistency and avoids exposing workspace artifacts to
clients.

Benefits
========

- Workspace handling is transparent to MCP clients
- All tools behave consistently
- Fewer incorrect intermediary results need post-processing
- Delete handling is more predictable

Limitations
===========

- The approach intentionally differs from standard TYPO3 backend patterns
- Query construction is more complex than pure live-workspace querying
- Some special cases, such as move handling, still need explicit care

Testing
=======

The implementation is covered by functional tests around workspace edge cases,
write flows, and search behavior.
