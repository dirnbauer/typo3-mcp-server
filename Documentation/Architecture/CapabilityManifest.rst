.. include:: /Includes.rst.txt

.. _capability-manifest:

===================
Capability manifest
===================

The MCP server ships ``Configuration/Capabilities.yaml`` — a declarative
manifest of which subsystems the extension touches and which subsystems
each tool requires. The model is adapted from the article
`TYPO3 extension security — capability manifests
<https://www.webconsulting.at/blog/typo3-extension-security-emdash-capability-manifests>`__.

Why
===

PHP cannot sandbox extensions at runtime; every extension runs with full
process privilege. Defense-in-depth comes from **declaration plus
enforcement at well-known choke points**:

- An extension declares what it does.
- The framework refuses to let it do anything else.
- An operator can inspect the declaration before installing.

For the MCP server, the relevant choke points are:

- The tool dispatcher (``AbstractTool::execute()``) — refuses tools whose
  required subsystems aren't in the manifest.
- The outbound HTTP path (``UploadFileFromUrl``, ``RenderRecord``) —
  refuses target hosts not in ``network.outbound``.

Manifest layout
===============

.. code-block:: yaml

   capabilities:
     version: '1.0'
     extension: mcp_server

     subsystems:
       - database:read
       - database:write
       - file:write
       - render:frontend
       # ...

     network:
       outbound:
         - self                # all configured TYPO3 site bases
         # - 'images.unsplash.com'
         # - '*.example.com'

     database:
       own_tables: [tx_mcpserver_*]
       reads: ['*']
       writes: ['*']

     tools:
       ReadTable:         [database:read]
       WriteTable:        [database:write]
       UploadFileFromUrl: [file:write]
       RenderRecord:      [database:read, render:frontend]
       # ...

     risk:
       level: high
       justification: |
         MCP exposes structured CRUD on every workspace-capable TCA
         table plus file uploads. ...

Subsystem catalog
=================

.. list-table::
   :header-rows: 1
   :widths: 22 78

   * - Subsystem
     - Meaning
   * - ``database:read``
     - Reads from any workspace-capable TCA table, plus extras listed in
       ``additionalReadOnlyTables``.
   * - ``database:write``
     - DataHandler writes against TCA tables. Workspace-staged unless
       ``localUnsafeMode`` lifts that, and gated by TYPO3 user permissions.
   * - ``database:schema``
     - Returns TCA / FlexForm schemas. Read-only metadata.
   * - ``workspace:read``
     - Lists workspaces and pending changes.
   * - ``workspace:write``
     - Publishes or rolls back workspace changes via DataHandler.
   * - ``file:read``
     - Browses file storages, reads metadata.
   * - ``file:write``
     - Uploads, writes, or imports files.
   * - ``cli:safe``
     - Runs an allowlisted set of TYPO3 CLI commands (``cache:flush``,
       ``install:fixfolderstructure``, …). Not a shell.
   * - ``extension:install``
     - ``InstallExtensionTool`` (admin-only). Disabled by removing this
       entry from the manifest.
   * - ``site:write``
     - ``CreateSite``, ``ManageRedirects``. Admin-only.
   * - ``render:frontend``
     - Outbound HTTP to a TYPO3 site base for rendered HTML
       (``RenderRecord``). Network policy still applies.
   * - ``log:read``
     - ``GetSystemLog``.
   * - ``x402:payments``
     - Optional paid-content / payment-stats tools.

Enforcement points
==================

``AbstractTool::execute()``
   Calls ``CapabilityManifestService::assertToolAllowed($name)`` before
   any user-controlled code path. If the tool's required subsystems
   aren't all in the manifest, an ``AccessDeniedException`` is thrown and
   converted to an MCP error result.

``UploadFileFromUrlTool::validateUrl()``
   Calls ``assertHostAllowed($host)`` before resolving the hostname.
   The hostname-to-IP-range SSRF check still applies on top — the
   manifest gate is the first line, the IP filter is the second.

``RenderRecordTool::fetchUrl()``
   Same ``assertHostAllowed()`` gate, plus ``CURLOPT_FOLLOWLOCATION``
   disabled so a single 302 cannot bypass the host check.

Bypassing the manifest
======================

Set extension setting ``enforceCapabilityManifest = 0`` to skip both
checks. Use only for debugging — this opens every registered tool plus
arbitrary outbound HTTP. The ``GetCapabilities`` tool surfaces the
current enforcement state so operators can see when the gate is off.

Using the manifest as a hardening tool
======================================

Three common scenarios:

**Read-only MCP**
  Remove ``database:write``, ``file:write``, ``workspace:write``,
  ``site:write``, and ``extension:install`` from
  ``capabilities.subsystems``. The corresponding tools refuse all calls
  even though they remain registered.

**Public-web image uploads only from a fixed CDN**
  Replace ``network.outbound: [self]`` with
  ``[self, 'images.unsplash.com', 'cdn.mybrand.com']``.
  ``UploadFileFromUrl`` accepts those hosts; everything else is rejected
  before DNS lookup. SSRF private-IP filter still runs after manifest
  approval.

**Disable a single tool without changing code**
  Add an unused subsystem like ``database:write-via-WriteTable`` to
  ``WriteTable``'s requirement list and don't declare it. The tool stops
  working until the subsystem is added back. (Lighter-weight: set the
  tool's ``[database:write]`` to ``[__disabled]`` — same effect.)

Inspecting the active manifest
==============================

.. code-block:: bash

   ddev exec ./vendor/bin/typo3 mcp:get-capabilities --json

The output includes ``manifest`` (full YAML decoded), ``enforced``
(true/false), and ``localMode`` (DDEV / Development context detection
results, plus the resolved ``allows_live_writes`` /
``allows_unrestricted_files`` flags).

Differences from the original blog proposal
===========================================

The article proposes the manifest as an extension-discoverability
mechanism with static-analysis enforcement (``typo3 capability:audit``,
``typo3 capability:policy-check``). This extension implements a
**runtime** enforcement variant: the manifest is consulted at call time,
not only at audit time. Both approaches are complementary —
static-analysis tooling can still inspect the manifest before deployment,
while the runtime gate catches drift between what the manifest declares
and what the code actually attempts.
