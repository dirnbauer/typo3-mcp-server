.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

Unreleased - 2026-09-12
=======================

- Merged the upstream ``hauptsacheNet/typo3-mcp-server`` main branch
  (``v0.6.2`` line, 38 commits) into the fork on top of the TYPO3 v14 rewrite.
- Folded upstream's upload hardening into the fork's sandboxed ``UploadFile``
  and ``UploadFileFromUrl`` tools: executable and server-configuration file
  names are refused, identical content is deduplicated (also after TYPO3
  rewrote it), web pages are rejected with an actionable message, and the
  size limit is configurable via ``maxFileSizeMb``.
- Added the pre-signed upload flow: ``UploadFile`` without a payload returns a
  single-use token and the ``/mcp_upload`` endpoint stores the client's raw
  upload as the bound backend user.
- ``UploadFileFromUrl`` turns YouTube/Vimeo URLs into online media assets.
- Added ``mcp:oauth create`` for static bearer tokens (``--ttl-days``).
- ``UploadFile``'s CORS preflight now allows ``PUT`` and
  ``Content-Disposition``; ``SiteInformationService`` keeps non-default ports.
- Kept the fork's own implementations where upstream shipped parallel fixes:
  dynamic client registration, subdirectory routing, uc preservation and
  impersonation, credential-safe logging, stateless SDK v2 transport, select
  item record context, and the language/translate corrections.
- Did not re-add the TER/classic deployment path (``Build/deploy-classic.sh``,
  bundled SDK) or upstream's confidential-client OAuth schema.

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
