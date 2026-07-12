.. include:: /Includes.rst.txt

.. _testing-protocol-compatibility:

==============================
Protocol compatibility testing
==============================

.. _testing-protocol-goal:

Goal
====

Every change to the MCP SDK, factory, HTTP endpoint, prompts, resources, or
OAuth must be checked against both supported eras. A passing stable test does
not prove the stateless release-candidate path, and the reverse is also true.

.. _testing-protocol-quality-gates:

Project quality gates
=====================

.. code-block:: bash
   :caption: Deterministic project checks

   ddev exec composer test
   ddev exec composer phpstan
   ddev exec composer php-cs-fixer
   composer docs:check
   composer audit

Run ``composer test:llm`` only when ``OPENROUTER_API_KEY`` is available. LLM
tests validate descriptions and workflows; they do not replace protocol,
permission, or workspace assertions.

.. _testing-protocol-install-smoke:

Installed TYPO3 smoke test
==========================

.. code-block:: bash
   :caption: Prepare and inspect the DDEV installation

   ddev start
   ddev exec composer setup
   ddev exec vendor/bin/typo3 extension:setup
   ddev exec vendor/bin/typo3 mcp:tool:list
   ddev exec vendor/bin/typo3 mcp:prompt:list --json
   ddev exec vendor/bin/typo3 mcp:get-capabilities --json

Confirm that the generated TYPO3 site URL and ``trustedHostsPattern`` match
the DDEV host. Do not weaken the pattern to ``.*`` to make a smoke test pass.

.. _testing-protocol-stable:

Stable ``2025-11-25`` track
===========================

Use an MCP client or raw JSON-RPC harness that explicitly selects
``2025-11-25``:

1. Send ``initialize`` and assert the selected version.
2. Preserve ``Mcp-Session-Id`` when the HTTP response supplies it.
3. Send ``notifications/initialized``.
4. Call ``tools/list``, ``prompts/list``, and ``resources/list``.
5. Call a read-only tool such as ``GetCapabilities``.
6. Assert that release-candidate-only ``resultType``, ``ttlMs``, and
   ``cacheScope`` are not leaked to the legacy response.
7. Close the client and confirm the server exits cleanly on stdio EOF.

.. _testing-protocol-modern:

Release-candidate ``2026-07-28`` track
======================================

Use a client that explicitly opts into the modern era:

1. Call ``server/discover`` and check that supported versions include
   ``2026-07-28``.
2. Do not send ``initialize`` or expect ``Mcp-Session-Id``.
3. Put the required protocol, client information, and client capabilities in
   each request's ``_meta`` object.
4. On HTTP, send ``MCP-Protocol-Version``, ``Mcp-Method``, and the applicable
   ``Mcp-Name`` header. Assert that a header/body mismatch is rejected.
5. Check ``resultType: complete`` and private cache hints on catalogs.
6. Verify one JSON-returning tool contains both legacy text and
   ``structuredContent``.
7. Verify unknown tools and unknown resources return the negotiated Invalid
   Params error.

Repeat the track over stdio and authenticated HTTP. Protocol behavior must not
depend on the transport.

.. _testing-protocol-http-security:

HTTP security track
===================

- A valid same-origin request reaches OAuth validation.
- An absent ``Origin`` remains valid for non-browser clients.
- A malformed, ``null``, or unlisted cross-origin ``Origin`` returns 403
  before bearer-token processing.
- Only exact configured HTTP(S) origins are accepted. Do not use substring or
  suffix matching.
- A token in ``?token=`` is rejected even if an obsolete local setting still
  exists. Header-only bearer authentication is unconditional.
- Responses include ``nosniff``, frame denial, no-referrer, and no-store
  headers.
- Logs redact authorization, cookies, and token-shaped values.

.. _testing-protocol-abilities:

Abilities and ``sg_apicore`` track
==================================

With both optional packages installed:

.. code-block:: bash
   :caption: Verify the optional projections

   ddev exec vendor/bin/typo3 abilities:list --json
   ddev exec vendor/bin/typo3 abilities:describe typo3-mcp/execute-tool
   ddev exec vendor/bin/typo3 abilities:run typo3-mcp/list-tools --input '{}'
   ddev exec vendor/bin/typo3 abilities:run typo3-mcp/list-skills --input '{}'

Then verify the REST projection:

- unauthenticated discovery returns 401;
- the OpenAPI document contains list, describe, and run routes and all five
  MCP abilities;
- a backend-user-bound read token can list and describe;
- a token with ``mcp:skills:read`` can list and retrieve bundled skills;
- a token without ``mcp:tools:execute`` cannot execute;
- an execution token still respects TYPO3 permissions and workspace rules;
- response request IDs and rate-limit headers are present;
- temporary smoke-test tokens are revoked or deleted after the test.

.. _testing-protocol-manifest:

Manifest consistency
====================

The test suite checks that:

- every registered native tool has an ``x-mcp.tools`` entry;
- every declared tool exists;
- ``x-mcp.commands`` matches registered ``mcp:*`` Symfony commands;
- manifest-owned database tables match ``ext_tables.sql``;
- skills resolve to safe Markdown sources and reference known tools.

Changing only implementation or only YAML should therefore fail early.

.. _testing-protocol-official:

External validation
===================

Use the `official MCP conformance suite
<https://github.com/modelcontextprotocol/conformance>`__ for wire-level
scenarios and `MCP Inspector
<https://github.com/modelcontextprotocol/inspector>`__ for interactive
inspection. Pin the conformance version in CI during the release-candidate
window; moving ``main`` can change expected behavior without a Composer lock
change.

Client testing is still required. Codex, Cursor, and Claude releases do not
currently publish a reliable dated revision matrix, so record the exact host
version and the lifecycle it negotiated with every manual report.
