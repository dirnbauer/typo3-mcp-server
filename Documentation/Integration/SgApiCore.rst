.. include:: /Includes.rst.txt

.. _sg-apicore-integration:

=======================================
Abilities REST API with ``sg_apicore``
=======================================

.. _sg-apicore-purpose:

Purpose and boundary
====================

The TYPO3 v14 fork of `sg_apicore
<https://github.com/dirnbauer/sg_apicore>`__ is currently installed from
``dev-main``. Its package and extension metadata declare version ``14.1.0``,
PHP ``^8.3``, and TYPO3 ``^14.3``. The fork provides routing, OpenAPI, scoped
tokens, tenant context, rate limiting, request IDs, and redacted API logs.

Together with `webconsulting/typo3-abilities
<https://github.com/dirnbauer/typo3-abilities>`__, it exposes this server's
tool catalog and bundled skill documents to non-MCP HTTP automation.

The native ``/mcp`` endpoint remains owned by this extension. The registered
``abilities`` API sets ``mcpEnabled`` to false, and an outer allowlist
middleware rejects API Core's generic ``/mcp`` route independently of its
global MCP setting. There is therefore no second MCP endpoint or duplicate
native tool projection.

.. _sg-apicore-installation:

Installation and activation
===========================

Both integrations are production requirements of this extension. This source
checkout declares the maintained v14 VCS repositories in its root
``composer.json``. Composer does not inherit repositories from dependency
packages, so a downstream TYPO3 root project must declare them before
requiring this extension until both forks are published through Packagist:

.. code-block:: bash
   :caption: Configure the downstream root project

   composer config --json repositories.sg-apicore \
       '{"type":"vcs","url":"https://github.com/dirnbauer/sg_apicore","canonical":true,"only":["sgalinski/sg-apicore"]}'
   composer config --json repositories.typo3-abilities \
       '{"type":"vcs","url":"https://github.com/dirnbauer/typo3-abilities","canonical":true,"only":["webconsulting/typo3-abilities"]}'

These two repositories are the authoritative sources for the integration
package names. The project marks them canonical and filters each repository to
its exact package name; it never resolves these integrations from an upstream
GitLab repository or another fork.

After Composer installation, complete TYPO3 extension setup:

.. code-block:: bash
   :caption: Set up the bundled TYPO3 v14 integrations

   vendor/bin/typo3 extension:setup

The Abilities registry and CLI projection are then active. The HTTP API stays
disabled by default. Enable ``activateAbilitiesApi`` deliberately after token,
scope, tenant, and CORS policy have been reviewed.

.. _sg-apicore-registration:

Registered API policy
=====================

When sg_apicore's ``activateAbilitiesApi`` setting is enabled,
``ext_localconf.php`` registers
API ID ``abilities``, version ``1``, with these defaults:

- Ability list, describe, and run routes authenticate a backend-user-bound
  opaque bearer token.
- Browser CORS is default-deny; no cross-origin browser is allowed unless the
  operator configures it deliberately.
- Rate limiting allows 60 requests per 60 seconds with burst 10.
- Tenant resolution binds the request and token to the TYPO3 site context.
- Request IDs support log correlation.
- Known secret keys and authorization values are redacted from logs.
- Native MCP projection is disabled for this API.
- A path allowlist exposes only ability list/describe/run and the two
  documentation routes. Generic API Core auth, demo, health, and MCP routes
  return 404 under this API ID.

.. warning::

   API Core's OpenAPI controller deliberately marks
   ``/api/abilities/v1/docs.json`` and ``/api/abilities/v1/docs/ui`` as public.
   A bearer token does **not** protect those two documentation routes. Treat
   ability names, descriptions, JSON Schemas, and examples as public metadata;
   never embed credentials, private URLs, customer data, or other secrets in
   them. The ability list, describe, and run routes remain token-protected.

``AbilitiesApiPolicyEnforcer`` owns this policy. It honors
``activateAbilitiesApi``, runs during extension bootstrap, again in an outer
frontend middleware before API Core's CORS/auth pipeline, and before console
commands. This makes the result independent of which extension's
``ext_localconf.php`` registers ``abilities`` last. Only exact HTTP(S) origins
from ``abilitiesApiCorsOrigins`` survive normalization; wildcards,
credentials, paths, queries, and fragments fail closed.

``AbilitiesOpenApiAugmenter`` removes non-allowlisted API Core routes from the
generated document. It also adds one exact run operation and named input/output
component schema per ``typo3-mcp/*`` ability, plus the sorted
``x-typo3-abilities`` registry metadata. The OpenAPI contract therefore comes
from the same live Abilities registry as CLI and REST execution.

The token initializes its referenced TYPO3 backend user, including groups and
an optional workspace. The abilities then perform their own real-backend-user
check before reaching the native MCP tool.

.. _sg-apicore-token:

Create a least-privilege token
==============================

Open :guilabel:`System > API Core` and create an opaque token for API
``abilities`` and the intended tenant. Bind it to the backend user whose page
mounts and table permissions should apply.

Grant only the required scopes:

``abilities:read``
   Required by the REST list and describe endpoints.

``mcp:tools:read``
   Required by ``typo3-mcp/list-tools`` and
   ``typo3-mcp/describe-tool``.

``mcp:tools:execute``
   Reserved for ``typo3-mcp/execute-tool`` on trusted CLI. The generic ability
   is not REST-exposed because the upstream trace store records complete
   inputs. Remote tool execution uses the native authenticated MCP endpoint.

``mcp:skills:read``
   Required by ``typo3-mcp/list-skills`` and ``typo3-mcp/get-skill``.

Never place the token in a URL. Send it only as an
``Authorization: Bearer`` header and store it in a secret manager.

.. _sg-apicore-endpoints:

Discovery and execution
=======================

The API publishes:

.. code-block:: text
   :caption: Abilities API routes

   GET  /api/abilities/v1/abilities                         bearer required
   GET  /api/abilities/v1/abilities/{namespace}/{name}      bearer required
   POST /api/abilities/v1/abilities/{namespace}/{name}/run  bearer required
   GET  /api/abilities/v1/docs.json                         public
   GET  /api/abilities/v1/docs/ui                           public

Every other route below ``/api/abilities/v1`` is rejected. In particular,
``/auth/login``, ``/auth/refresh``, ``/health``, ``/example/*``, and ``/mcp``
are not part of this API even though sg_apicore owns generic controllers with
those route attributes.

Example discovery:

.. code-block:: bash
   :caption: List REST-exposed abilities

   curl --fail-with-body \
       -H "Authorization: Bearer ${ABILITIES_TOKEN}" \
       https://example.test/api/abilities/v1/abilities

Example read-only catalog execution through the Abilities API:

.. code-block:: bash
   :caption: List native tool contracts through the Abilities pipeline

   curl --fail-with-body -X POST \
       -H "Authorization: Bearer ${ABILITIES_TOKEN}" \
       -H 'Content-Type: application/json' \
       --data '{}' \
       https://example.test/api/abilities/v1/abilities/\
typo3-mcp/list-tools/run

The response is the Abilities envelope containing the effective native tool
catalog. Use ``/mcp`` for authenticated remote tool execution.

.. _sg-apicore-cli:

CLI verification
================

The Abilities package also exposes the registry directly to trusted TYPO3 CLI
operators:

.. code-block:: bash
   :caption: Inspect the registered MCP catalog abilities

   vendor/bin/typo3 abilities:list --json
   vendor/bin/typo3 abilities:describe typo3-mcp/list-tools
   vendor/bin/typo3 abilities:run typo3-mcp/list-tools --input '{}'
   vendor/bin/typo3 abilities:run typo3-mcp/list-skills --input '{}'
   vendor/bin/typo3 abilities:run typo3-mcp/execute-tool \
       --input '{"name":"GetCapabilities","arguments":{}}'

CLI execution uses its own trusted surface, but policy and each ability's
permission check still apply. Protected REST ability routes use explicit token
scopes; the two OpenAPI documentation routes are public.

.. _sg-apicore-operational-checks:

Operational checks
==================

- Confirm unauthenticated ability list, describe, and run requests return 401.
- Confirm both OpenAPI routes work without a bearer token and expose no secrets.
- Confirm ``execute-tool`` is absent from REST discovery and exact OpenAPI
  paths, while it remains available to trusted CLI operators.
- Check ``X-Request-ID`` and rate-limit headers on responses.
- Confirm OpenAPI lists all four REST-exposed MCP abilities and their JSON
  Schemas.
- Confirm unrelated API Core routes and ``/api/abilities/v1/mcp`` return 404,
  even when sg_apicore's global MCP switch is enabled.
- Test with a non-admin backend user and verify page mounts and workspaces.
- Keep cross-origin access empty unless a named browser origin is required.
- Do not enable the API's own MCP projection for the ``abilities`` API.
- Set ``activateAbilitiesApi=0`` when only the Abilities CLI/backend registry
  is wanted; the MCP extension does not override that kill switch.
