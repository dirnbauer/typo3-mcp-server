.. include:: /Includes.rst.txt

.. _troubleshooting:

===============
Troubleshooting
===============

This page collects the most common problems when connecting an MCP client to
a TYPO3 site running this extension, and what to check first.

If the symptom you see is not listed here, open the backend module under
:guilabel:`User > MCP Server`. It performs live health checks against the
MCP and OAuth discovery endpoints and will surface the underlying issue in
most cases.

.. contents::
   :local:
   :depth: 1

The MCP endpoint is not reachable
=================================

Symptom: the backend-module health check for ``/mcp`` is red, or the client
receives a TYPO3 404 page.

Check:

- Confirm the correct **site base URL**. The module shows the endpoint URL
  it renders to clients; if that URL is wrong, fix the TYPO3 **site
  configuration** (``Site Management > Sites``).
- Confirm that the TYPO3 **middleware stack** is intact. A custom
  ``RequestMiddlewares.php`` override that removes the ``mcp_server`` entry
  will disable the endpoint silently.
- If you sit behind a reverse proxy / CDN, verify that requests to
  ``/mcp`` and ``/.well-known/oauth-*`` are forwarded without being
  rewritten or cached.

The OAuth discovery URLs return 404
===================================

Symptom: the health checks next to ``.well-known/oauth-authorization-server``
or ``.well-known/oauth-protected-resource`` are red.

Check:

- These paths are served by the same middleware as ``/mcp``. If ``/mcp``
  works but ``/.well-known/*`` does not, a reverse proxy rewrite rule is
  usually the cause.
- Some hosting stacks block dot-prefixed paths (``.well-known``) by default;
  explicitly allow that prefix.

"Authorization header missing" after login
==========================================

Symptom: the client completes the OAuth login but every tool call returns
401, and the backend module shows the :guilabel:`Authorization header` warning.

Root cause: the reverse proxy or web server strips the ``Authorization``
header before TYPO3 sees it.

Fixes:

- **Apache**: add the following rewrite rule so ``Authorization`` is mapped
  to ``HTTP_AUTHORIZATION``:

  .. code-block:: apache

     RewriteEngine On
     RewriteCond %{HTTP:Authorization} ^(.*)
     RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]

- **Nginx**: ensure ``fastcgi_pass_header Authorization;`` is set in the
  PHP location block.
- **HTTP Basic Auth in front of TYPO3**: the outer Basic Auth replaces the
  ``Authorization`` header. Remove Basic Auth on ``/mcp`` and
  ``/mcp_oauth/*`` and ``/.well-known/*``, or terminate Basic Auth before
  the MCP endpoint.
- **Proxy / CDN**: verify that the proxy forwards the ``Authorization``
  header unchanged.

As an emergency workaround you can temporarily enable
:confval:`allowMcpTokenInQueryString <ext-mcp-server-allowMcpTokenInQueryString>`
and pass ``?token=…`` on ``/mcp``. This leaks tokens into logs and should
not stay enabled in production.

"No writable workspace" when writing records
============================================

Symptom: record-writing tools return an error about workspaces.

Check:

- The authenticated backend user must either be in a workspace already or
  be allowed to create one.
- The extension automatically selects the first writable workspace, or
  creates an ``MCP`` workspace if permissions allow. If neither is possible,
  assign the user to a workspace via :guilabel:`Workspaces` in the TYPO3
  backend.
- Admin users always have access; non-admins require explicit group
  membership.

Tool calls return "Table not allowed"
=====================================

Symptom: reading or writing a table fails with a
``Table not allowed`` / ``Field not allowed`` error, even though the user
has access to that table in the TYPO3 backend.

Check:

- MCP applies an additional table/field gate on top of TYPO3 permissions
  (``TableAccessService``). Tables without workspace capability or with
  sensitive fields may be filtered intentionally.
- Use ``ListTables`` and ``GetTableSchema`` to see what the client is
  allowed to touch.
- Third-party extensions can extend or filter the MCP-visible field list
  by listening for ``ModifyAvailableFieldsEvent``.

Cursor / Claude / n8n ask to authorize again every time
=======================================================

Symptom: the client re-runs the OAuth flow on every connection.

Check:

- TYPO3 session cookies must be stored by the client. Cursor and the
  ``mcp-remote`` proxy do this out of the box; custom clients may need
  additional config.
- Verify the site runs over **HTTPS**. Some clients refuse to persist
  tokens issued from ``http://``.
- Confirm that the MCP client reuses the existing token; some clients
  default to a fresh token per launch.

Local ``vendor/bin/typo3 mcp:server`` connects but tools fail
=============================================================

Symptom: the stdio server starts but every tool returns a permission error.

Check:

- ``mcp:server`` requires **admin** privileges by design
  (``McpServerCommand::ensureAdminRights()``). Run it as an admin user.
- Use ``mcp:test`` to call a single tool with JSON args to isolate the
  problem.

File tools refuse my path
=========================

Symptom: ``BrowseFiles``, ``UploadFile`` or ``WriteFile`` returns an error
about the path being outside the sandbox.

Check:

- File tools are restricted to
  :confval:`fileSandboxRoot <ext-mcp-server-fileSandboxRoot>` (default
  ``1:/mcp/`` → ``fileadmin/mcp/``). That is intentional.
- To widen the scope, change the sandbox root in the extension
  configuration. Never point the sandbox at the full ``fileadmin`` — MCP
  file writes are **not** workspace-versioned.

``PublishWorkspace`` published nothing
======================================

Symptom: ``PublishWorkspace`` ran but live content did not change.

Check:

- The tool defaults to **dry-run** for safety. Pass ``"dryRun": false`` to
  actually publish.
- Verify with ``WorkspaceReview`` that the workspace actually contains
  pending changes and that filtering (``onlyTables``) matches what you
  expect.

A tool returns "tool ... is missing subsystems"
================================================

Symptom: an MCP call returns ``AccessDenied: tool "Foo" (manifest is
missing subsystems: file:write)``.

Cause: the capability manifest at ``Configuration/Capabilities.yaml`` no
longer declares the subsystems that tool requires. Either an admin
hardened the manifest (intentionally) or a recent ``mcp_server`` upgrade
added a new tool whose subsystem isn't yet in the local manifest.

Fix:

- Inspect the active manifest: ``vendor/bin/typo3 mcp:get-capabilities --json``.
- Add the missing entry under ``capabilities.subsystems`` if you want the
  tool enabled.
- Or set the extension setting ``enforceCapabilityManifest = 0`` to bypass
  the manifest entirely (debugging only — opens every registered tool).

A network-using tool returns "outbound request not in manifest"
===============================================================

Symptom: ``UploadFileFromUrl`` or ``RenderRecord`` returns
``AccessDenied: outbound request to "..." (not in capability manifest
network.outbound)``.

Cause: the manifest's ``network.outbound`` list does not include that
host. Default ships at ``[self]`` only.

Fix: edit ``Configuration/Capabilities.yaml`` and add the host (or
``*.example.com`` wildcard) under ``network.outbound``. Use ``*`` to allow
any host (the IP-range SSRF check still rejects private addresses).

Live writes are rejected even though I expect them to work
==========================================================

Symptom: ``WriteTable`` rejects ``workspace_id: 0`` with
``AccessDenied: live workspace (set localUnsafeMode=on or run inside
DDEV)`` even though you're on a developer machine.

Cause: the server detected a Production-style application context and the
``localUnsafeMode`` setting is at ``auto`` or ``off``.

Fix: either set ``TYPO3_CONTEXT=Development`` (or one of its derivatives)
in your environment, set ``IS_DDEV_PROJECT=true``, or pin
``localUnsafeMode = on`` in extension settings. Verify via
``vendor/bin/typo3 mcp:get-capabilities --json`` — the ``localMode``
section reports what's detected.

The backend module tabs do nothing
==================================

Symptom: clicking client-setup tabs in :guilabel:`User > MCP Server` does
not switch the content.

Check:

- Clear the browser cache. The custom tab implementation lives in
  ``Resources/Public/JavaScript/mcp-module.js`` and may be cached
  aggressively by a CDN.
- Run :guilabel:`Maintenance > Flush TYPO3 and PHP Cache` — the
  JS module uses a hashed identifier that needs a fresh asset manifest.

Still stuck?
============

If something is reproducibly broken, open an issue on the fork repository
with:

- the TYPO3 version,
- the extension version (``composer show hn/typo3-mcp-server``),
- the MCP client + version,
- the exact request/response or tool-call payload,
- anything visible in ``System > Log`` at the time of the error.

See also
========

- :ref:`Configuration <configuration>` — extension settings and sandbox
  behavior.
- :doc:`../Architecture/SecurityAudit` — what the current security posture
  is designed to cover.
