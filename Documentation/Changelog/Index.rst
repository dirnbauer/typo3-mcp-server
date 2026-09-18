.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

The complete release history lives in :file:`CHANGELOG.md` at the repository
root (Keep a Changelog format). This page summarizes the current release.

0.8.0 - 2026-09-18
==================

Behaviour-preserving overhaul of the fork-only code paths, level 8 static
analysis without a baseline, and a rewritten manual:

- ``FileUploadService::storeUpload()`` is the single write path for
  ``UploadFile``, ``UploadFileFromUrl`` and ``/mcp_upload``; oversized
  payloads raise ``UploadTooLargeException`` (HTTP 413).
- ``AbstractTool`` reads the ``#[AdminOnly]`` / ``#[DevSiteOnly]`` attributes
  itself and offers ``createJsonResult()`` to every tool; the tool registry
  filters dev-site tools without reflection into adapter internals.
- ``SolrIndexQueue`` discovers tasks from ``tx_scheduler_task`` and spawns a
  subprocess only for the validated ``scheduler:run``. Its ``list`` result no
  longer carries a ``schedulerList`` block.
- ``mcp:test`` was removed; ``mcp:tool <Name>`` is the single generic runner.
- The capability manifest is read from its bundled path only and policy is
  read from ``x-mcp`` only.
- Adapted fixes from open upstream pull requests: nested inline file
  relations, shared inline child tables scoped by parent table, field
  visibility at the record's real page, circular page-move rejection,
  isolated online-media helper failures, configurable ``sessionTimeout``.

Earlier releases
================

- **0.7.x** - abilities projected as MCP tools through ``McpProjection``, the
  ``sg_apicore`` integration removed, the ``mcp`` ability category
  registered.
- **0.6.x** - TYPO3 14.3.6 security floor, subdirectory routing with RFC
  8414/9728 discovery, forced-task ``SolrIndexQueue`` runs.

See :file:`CHANGELOG.md` for the full entries.
