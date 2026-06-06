.. include:: /Includes.rst.txt

.. _intended-behavior:

=================
Intended behavior
=================

This page is the product-level specification for the maintained TYPO3 MCP
Server line. The goal is not only to describe which tools exist, but also how
they are expected to behave when an MCP client uses them correctly.

Core contract
=============

The extension is intended to behave like this:

- MCP clients discover the available surface through ``tools/list`` instead of
  assuming a fixed contract forever
- tool names stay TYPO3-oriented (``ReadTable``, ``GetPageTree``, ``WriteTable``)
  even when parameters or descriptions evolve between releases
- successful tool results are usually returned as JSON encoded into MCP text
  content, because the bundled PHP SDK does not expose structured
  ``outputSchema`` results in the same way as some other MCP stacks
- validation and recoverable workflow mistakes should return actionable tool
  errors instead of generic internal failures

Record behavior
===============

Workspace-first writes
----------------------

Record-backed modifications are expected to stay behind TYPO3 workspaces in
strict/production mode:

- direct live edits are not the normal MCP write path on production endpoints
- the optional ``workspace_id`` parameter lets a client choose a specific draft
  workspace explicitly
- when ``workspace_id`` is omitted in strict mode, the extension keeps the
  current non-live workspace if possible, otherwise selects or creates a
  writable one

In DDEV / local mode (``localUnsafeMode`` resolving to ``on``), omitted
``workspace_id`` defaults to live (``0``) so local development can edit
published content directly. Per-user User TSconfig can disable that even on
DDEV. Pass an explicit draft ``workspace_id`` to stage changes locally.
- returned record identifiers stay live-facing even when TYPO3 stores an
  internal workspace version row underneath

Read transparency
-----------------

Read tools are expected to hide TYPO3 workspace internals:

- delete placeholders should not leak into normal client-facing reads
- reads should prefer the visible draft state in the active workspace context
- clients should not need to know ``t3ver_*`` internals to understand results

Type and schema behavior
------------------------

The MCP server is intended to stay TCA-first:

- ``GetTableSchema`` explains fields, types, palettes, visibility, and
  validation based on TYPO3 configuration
- ``GetFlexFormSchema`` explains FlexForm structures that a client may need
  before writing plugin or content-block data
- field visibility respects permissions and TSconfig instead of exposing every
  raw database column

File behavior
=============

The file tools intentionally do **not** promise workspace versioning for
physical files:

- ``BrowseFiles``, ``ReadFileMetadata``, ``WriteFile``, ``UploadFile``, and
  ``UploadFileFromUrl`` only operate inside the MCP file sandbox
- uploading or overwriting a physical file takes effect immediately on disk
- workspace safety still applies to records and ``sys_file_reference`` rows, not
  to the file itself
- ``AttachImage`` is intended to bridge those worlds by staging or transforming
  sandbox files first and then creating workspace-versioned references on record
  fields

Import, review, and execution
=============================

The import tools are intended to support review-first editorial workflows:

- ``ImportContent`` can analyze text, Markdown, or HTML and propose content
  elements, or create them directly in execute mode
- ``ImportFromUrl`` can fetch a public page, extract likely main content, and
  either return a proposal or create the page plus elements directly
- ``WorkspaceReview`` should help the client or editor inspect pending changes
  before publishing
- ``PublishWorkspace`` and ``RollbackWorkspace`` default to dry-run style
  previews so irreversible steps require explicit confirmation

Optional capability families
============================

Some tools are intentionally optional because they depend on TYPO3 packages or
instance configuration that may not exist everywhere:

- ``ManageRedirects`` requires the ``sys_redirect`` table to be available; list
  access works when it is available, while create/delete only run on instances
  where ``sys_redirect`` is workspace-capable
- ``ListPaidContent``, ``GetPaidContent``, and ``GetPaymentStats`` require the
  optional x402 paywall extension surface; when that surface is missing they
  should return configuration guidance instead of raw SQL errors
- ``CreateSite`` changes YAML site configuration immediately and therefore
  remains admin-only
- ``InstallExtension`` and ``SafeCli`` remain intentionally narrow and validated
  instead of acting like general shell access

Verification expectations
=========================

This repository intends the specification, implementation, and tests to move
together:

- functional tests should cover behavior that is documented here
- when the intended behavior changes, the relevant documentation and tests
  should change in the same pull request
- LLM-oriented tests are a secondary validation layer and do not replace
  deterministic unit or functional coverage
