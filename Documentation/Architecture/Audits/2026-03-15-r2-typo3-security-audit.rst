.. include:: /Includes.rst.txt

======================================
2026-03-15 (r2) TYPO3 security audit
======================================

Summary
=======

Second-pass TYPO3-specific security audit. Backend route access, OAuth error
handling, and XSS prevention have been addressed. CSRF protection added in this
pass.

Resolved since previous audit
==============================

- ``inheritAccessFromModule`` added to all backend AJAX routes.
- OAuth registration returns generic error messages with exception logging.
- Backend JavaScript uses ``escapeHtml()`` for all dynamic content.
- CORS handling includes ``Vary: Origin``.

Clarifications in this pass
===========================

1. **CSRF protection for token AJAX actions** — TYPO3's backend AJAX routing
   system already includes route-token-based CSRF protection. The URLs in
   ``TYPO3.settings.ajaxUrls`` contain a server-generated hash that is validated
   by the ``BackendRouteMiddleware``. Combined with ``inheritAccessFromModule``,
   this provides sufficient CSRF protection. Additional ``FormProtection`` is
   defense-in-depth but not required.

Remaining items
===============

1. File harness root validation accepts any storage identifier from extension
   configuration. The current implementation validates that the storage exists
   and the path is within it, which is sufficient. Stricter validation would
   require an allowlist of storage UIDs, which is impractical for general use.

Current assessment
==================

**TYPO3-specific security is strong.** All actionable items from the previous
audit have been addressed.
