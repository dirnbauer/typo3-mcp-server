.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

0.6.2 - 2026-08-28
==================

- Fixed repeated ``SolrIndexQueue`` runs by forcing only the explicitly
  selected and validated EXT:solr scheduler task.

0.6.1 - 2026-08-27
==================

- Raised the TYPO3 security floor to 14.3.6.
- Aligned the declared PHP floor with the required Abilities integration at
  PHP 8.4, removing an impossible Composer platform combination.
- Ported upstream subdirectory routing and completed it with RFC 8414/9728
  well-known discovery at the origin root for path-based installations.
- Verified that the fork already contains the upstream CORS response fix,
  RFC 7591 dynamic client registration, backend-user preference preservation,
  and WriteTable language/translation corrections; those implementations stay
  in place instead of duplicating the upstream code.

.. _changelog-current:

Current modernization
=====================

The current unreleased line modernizes the extension for TYPO3 v14, PHP 8.3,
and the locked MCP ``2026-07-28`` release candidate while retaining stable
client compatibility.

.. toctree::
   :maxdepth: 1

   Modernization2026
