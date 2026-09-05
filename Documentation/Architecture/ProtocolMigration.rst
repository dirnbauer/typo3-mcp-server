.. include:: /Includes.rst.txt

.. _protocol-migration:

================================
MCP protocol compatibility
================================

.. _protocol-migration-status:

Supported implementation
========================

The installed ``logiscape/mcp-sdk-php`` v2 runtime serves both tested wire
versions concurrently:

- ``2025-11-25`` clients use initialization and the session lifecycle.
- ``2026-07-28`` clients use stateless, self-contained requests and optional
  ``server/discover`` discovery.

``composer.lock`` records the SDK version tested in this repository. Consumer
projects resolve their own lock file. Run both protocol tracks when updating
the SDK; package versions and protocol versions are separate contracts.

This page describes the implemented wire behavior. Consult the
`MCP specification <https://modelcontextprotocol.io/specification/>`__ for
external release status rather than inferring it from a client product name
or the dates recorded in older project changelogs.

.. _protocol-migration-diff:

Protocol differences
========================

.. list-table:: MCP ``2025-11-25`` compared with ``2026-07-28``
   :header-rows: 1
   :widths: 20 38 42

   * - Area
     - ``2025-11-25``
     - ``2026-07-28`` stateless protocol
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

Client compatibility
====================

Check the installed client's actual lifecycle: ``initialize`` selects the
session path; a ``2026-07-28`` request with the required metadata selects the
stateless path. “Streamable HTTP” alone does not establish which revision a
client uses. Keep both paths enabled and record the client version and
negotiated protocol in manual test reports.

.. _protocol-migration-rollout:

Rollout checklist
=================

1. Install from the tested SDK lock before checking client compatibility.
2. Run a stable ``initialize`` → ``tools/list`` → ``tools/call`` sequence.
3. Run a modern ``server/discover`` → ``tools/list`` → ``tools/call``
   sequence over stdio and HTTP.
4. Assert that modern requests do not receive ``Mcp-Session-Id``.
5. Assert that legacy requests do not receive ``resultType`` or cache fields
   they do not understand.
6. Verify prompts, resources, structured output, unknown-tool errors, and
   origin rejection on both tracks.
7. Re-run the applicable MCP conformance suite when the SDK pin changes.

See :doc:`../Testing/ProtocolCompatibility` for executable checks.
