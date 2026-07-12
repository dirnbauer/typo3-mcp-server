.. include:: /Includes.rst.txt

.. _protocol-migration:

================================
MCP 2026 protocol migration
================================

.. _protocol-migration-status:

Release status
==============

MCP ``2025-11-25`` is the current published stable revision. MCP
``2026-07-28`` is a locked release candidate as of 2026-07-11; the final
specification is scheduled for 2026-07-28. The release candidate contains
breaking lifecycle and transport changes.

This extension requires ``logiscape/mcp-sdk-php:^2.0.0-beta3``; the committed
lock file currently selects ``2.0.0-beta3``. It serves both eras concurrently:

- Stable clients keep the initialization and session lifecycle.
- Release-candidate clients use stateless, self-contained requests.
- An RC-capable client can use ``server/discover`` and a stable client can
  continue to send ``initialize`` to the same endpoint.

Treat the locked SDK version as intentional. Update the lock only after
running both protocol tracks, because a pre-release SDK can still change
before the final specification.

.. _protocol-migration-diff:

Stable-to-RC differences
========================

.. list-table:: MCP ``2025-11-25`` compared with ``2026-07-28``
   :header-rows: 1
   :widths: 20 38 42

   * - Area
     - ``2025-11-25``
     - ``2026-07-28`` release candidate
   * - Lifecycle
     - ``initialize`` followed by ``notifications/initialized``.
     - No handshake. ``server/discover`` returns identity, versions, and
       capabilities when a client wants discovery.
   * - Request metadata
     - Protocol version and client information are negotiated once.
     - Protocol version, client information, and client capabilities travel in
       every request's reserved ``_meta`` fields.
   * - Session
     - HTTP can use ``Mcp-Session-Id`` and a session store.
     - The protocol session and ``Mcp-Session-Id`` are removed.
   * - HTTP stream
     - A standalone GET/SSE channel can resume with ``Last-Event-ID``.
     - No session GET or resumable protocol stream. SSE is request-scoped when
       a response needs it.
   * - Routing headers
     - Gateways normally inspect the JSON-RPC body.
     - ``Mcp-Method`` and, where applicable, ``Mcp-Name`` and designated
       ``Mcp-Param-*`` headers mirror routing data and must match the body.
   * - Results
     - Ordinary result objects; Tasks were an experimental core feature.
     - ``resultType`` distinguishes complete, input-required, and extension
       results. Tasks use their own extension.
   * - Server requests
     - A live session or SSE stream can carry client requests.
     - Multi round-trip requests return ``input_required`` plus opaque request
       state; the client resubmits the original operation with answers.
   * - Caching
     - Change notifications are the main freshness signal.
     - List, discovery, and resource results can declare ``ttlMs`` and
       ``cacheScope``.
   * - Extensions
     - Extension data exists without a complete lifecycle framework.
     - Reverse-DNS extensions are negotiated and versioned independently.
       MCP Apps and Tasks are the first official extensions.
   * - JSON Schema
     - Tool schemas use the prior constrained schema shape.
     - Full JSON Schema 2020-12 composition is supported. Structured tool
       results may be any JSON value.
   * - Missing resource
     - Custom error code ``-32002``.
     - Standard Invalid Params code ``-32602``.
   * - Deprecations
     - Roots, sampling, and logging are active core features.
     - They remain functional but are deprecated, with documented
       replacements and a minimum lifecycle window.
   * - Authorization
     - OAuth protected-resource discovery and resource indicators apply.
     - Adds authorization-response issuer validation, client application type,
       and authorization-server binding for registered credentials.

The official `release-candidate announcement
<https://blog.modelcontextprotocol.io/posts/2026-07-28-release-candidate/>`__
and `draft specification
<https://modelcontextprotocol.io/specification/draft>`__ remain authoritative
until the final release.

.. _protocol-migration-this-server:

How this server adapts
======================

The SDK detects the request era. Modern HTTP requests are handled in an
ephemeral context and never create a protocol session. Legacy HTTP requests
retain file-backed sessions and response headers required by stable clients.

The extension adds these cross-era contracts:

- ``tools/list`` returns typed, deterministically sorted tools with a private
  30-second cache hint for the modern era.
- Prompts and resources use private 60-second cache hints.
- Successful single-JSON text results also receive ``structuredContent``.
  The text representation remains for stable clients and humans.
- Unknown tools use the typed Invalid Params error instead of a fabricated
  successful tool result.
- Resource-not-found errors use the code required by the negotiated era.
- Empty tool properties serialize as ``{}``, not an invalid JSON array.
- Sensitive HTTP routing headers are allowed through CORS only after exact
  origin validation.

No bundled TYPO3 tool currently depends on Tasks or multi round-trip requests.
Those protocol facilities are available in the SDK but are not advertised as
application features until a TYPO3 workflow needs them and has tests.

.. _protocol-migration-client-status:

Codex, Cursor, and Claude status
================================

Product names are not protocol-version declarations. As of 2026-07-11, no
public support matrix from Codex, Cursor, Claude Desktop, or Claude Code
confirms that their generally available builds send the locked
``2026-07-28`` wire format.

.. list-table:: Deployment interpretation on 2026-07-11
   :header-rows: 1
   :widths: 22 36 42

   * - Host
     - Public evidence
     - Guidance
   * - OpenAI Codex
     - The public `Codex source
       <https://github.com/openai/codex>`__ documents MCP and uses the Rust
       ``rmcp`` client line, but does not promise the dated RC revision.
     - Treat support as stable-era until the actual connection negotiates the
       RC. Keep server fallback enabled.
   * - Cursor
     - The public `Cursor MCP documentation
       <https://docs.cursor.com/context/model-context-protocol>`__ has no dated
       protocol-revision matrix.
     - Test the installed build. Do not infer RC support from “Streamable
       HTTP” alone.
   * - Claude Desktop / Claude Code
     - Anthropic's public `MCP documentation
       <https://docs.anthropic.com/en/docs/mcp>`__ has no dated RC commitment.
     - Expect stable compatibility and verify discovery before relying on RC
       fields such as cache hints.

This is deliberately a compatibility statement, not a claim that these hosts
cannot support the RC. Client releases move quickly. Observe whether a client
sends ``server/discover`` or ``initialize`` and test the specific version used
in production.

The development machine was also inspected on 2026-07-11. Codex CLI
``0.139.0`` contains the stable ``2025-11-25`` client revision but no
``2026-07-28`` or ``server/discover`` marker; Cursor ``3.11.13`` sets its
bundled MCP client's current revision to ``2025-11-25``; and Claude Code
``2.1.170`` likewise sets its MCP revision constant to ``2025-11-25``. Those
specific installed builds must therefore use this server's stable path. This
binary/source inspection says nothing about a separately deployed ChatGPT or
Claude hosted connector, and it must be repeated after client upgrades.

.. _protocol-migration-rollout:

Rollout checklist
=================

1. Keep the tested SDK lock during the release-candidate window.
2. Run a stable ``initialize`` → ``tools/list`` → ``tools/call`` sequence.
3. Run a modern ``server/discover`` → ``tools/list`` → ``tools/call``
   sequence over stdio and HTTP.
4. Assert that modern requests do not receive ``Mcp-Session-Id``.
5. Assert that legacy requests do not receive ``resultType`` or cache fields
   they do not understand.
6. Verify prompts, resources, structured output, unknown-tool errors, and
   origin rejection on both tracks.
7. Re-run the official MCP conformance suite when the final specification or
   SDK pin changes.

See :doc:`../Testing/ProtocolCompatibility` for executable checks.
