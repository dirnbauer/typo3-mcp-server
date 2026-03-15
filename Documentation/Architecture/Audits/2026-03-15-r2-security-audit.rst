.. include:: /Includes.rst.txt

================================
2026-03-15 (r2) security audit
================================

Summary
=======

Second-pass security audit (OWASP-aligned). Most previous findings have been
resolved. One medium-priority item remains.

Resolved since previous audit
==============================

- OAuth registration no longer leaks exception details (generic error + logging).
- Backend AJAX routes inherit access from the MCP module.
- Backend JavaScript escapes all dynamic content with ``escapeHtml()``.
- Token preview logging removed from ``McpEndpoint``.
- CORS ``Vary: Origin`` header added.

Remaining findings
==================

Medium priority
~~~~~~~~~~~~~~~

1. **CSRF protection for backend token management** — TYPO3's backend AJAX
   routing already includes route-token-based CSRF protection via
   ``TYPO3.settings.ajaxUrls``. Combined with ``inheritAccessFromModule``, this
   provides sufficient protection. No additional changes needed.

2. **CORS reflects any Origin** — ``CorsHeadersTrait::getAllowedOrigin()``
   reflects the request ``Origin`` header without validation. This is
   **intentional** for MCP: clients from any origin (Cursor, Claude Desktop,
   n8n, browser-based tools) need to connect via OAuth. The OAuth token system
   itself controls access, not CORS. Documented explicitly.

Low priority
~~~~~~~~~~~~

1. Direct-access tokens can still be passed via URL query parameters. This is
   kept for compatibility with clients that cannot set HTTP headers.

Current assessment
==================

**Security posture is strong.** The remaining items are either intentional
design decisions (open CORS for MCP OAuth) or low-risk compatibility features
(query-parameter tokens).
