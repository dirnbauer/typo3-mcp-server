.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

The complete release history lives in :file:`CHANGELOG.md` at the repository
root (Keep a Changelog format). This page summarizes the current release.

0.9.2 - 2026-09-23
==================

- Rich text with ``t3://`` links saves over the CLI commands and the stdio
  server: the write tools publish a request for the site of the written
  record (fallback to the first site, reported in ``siteContext``). Over
  HTTP the tools see the endpoint's request as a backend request, which fixes
  updates of live file references on nested inline children in a workspace.
- FlexForm updates merge into the stored settings; ``null`` removes a field.
- :file:`ext_emconf.php` is gone; the metadata lives in :file:`composer.json`.

0.9.1 - 2026-09-23
==================

Test-only fix: the E2E test for the copy elements is scoped to the module, so
the hidden copy element of the docheader's shortcut dropdown no longer
matches first.

0.9.0 - 2026-09-23
==================

A rebuilt backend module, the relevant open upstream fixes, PHPStan level 8
over all own PHP and current dependencies:

- The :guilabel:`User > MCP Server` module follows TYPO3 v14 core patterns:
  docheader action, core tabs (connect a client, access tokens, connection
  check, tools), collapsible client panels, copy elements, infobox empty
  states, no own stylesheet, English and German labels, JavaScript labels
  from ``~labels/mcp_server.mod``.
- Fixes adapted from open upstream pull requests: FlexForm sheets and dotted
  field names (#131), the PSR-7 request for rich text with ``t3://`` links
  (#129), idempotent workspace deletes (#68), strict ReadTable filter values
  (#29), a readable ``WWW-Authenticate`` header for browser clients; deleting
  a record with a workspace draft deletes it.
- PHPStan level 8 analyses code, tests, build scripts and configuration
  files; PHP 8.4 idioms; PHPUnit 13, Playwright 1.63 on Node.js 24.

Earlier releases
================

- **0.8.0** - one upload write path, tool attributes on ``AbstractTool``,
  ``SolrIndexQueue`` without a subprocess list, level 8 static analysis of
  the extension code, adapted upstream fixes (nested inline files, shared
  inline tables, field visibility at the record's page, page-move cycles,
  isolated online-media helpers, ``sessionTimeout``).
- **0.7.x** - abilities projected as MCP tools through ``McpProjection``, the
  ``sg_apicore`` integration removed, the ``mcp`` ability category
  registered.
- **0.6.x** - TYPO3 14.3.6 security floor, subdirectory routing with RFC
  8414/9728 discovery, forced-task ``SolrIndexQueue`` runs.

See :file:`CHANGELOG.md` for the full entries.
