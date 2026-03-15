.. include:: /Includes.rst.txt

==============================
2026-03-15 TYPO3 security audit
==============================

Summary
=======

The extension already has strong foundations for TYPO3-specific safety, such as
workspace-first record changes, token hashing, and the MCP file harness.
However, several TYPO3 backend and HTTP hardening improvements are still
recommended.

Positive findings
=================

- Access tokens are stored as hashes
- PKCE support is enforced for supported OAuth flows
- File access is restricted through the MCP file harness
- QueryBuilder and named parameters are used consistently

Main findings
=============

1. Backend AJAX routes in :file:`Configuration/Backend/AjaxRoutes.php` do not
   yet inherit access from the MCP backend module.
2. The backend token actions do not currently validate TYPO3 form protection for
   custom AJAX requests.
3. CORS handling should be tightened further, especially where origins are
   reflected or permissive values are returned.
4. The configured file harness root accepts any storage identifier from
   extension configuration and should be validated more strictly.

Recommended next changes
========================

- Add ``inheritAccessFromModule`` for the MCP backend AJAX routes.
- Introduce TYPO3 form-protection validation for token management requests.
- Restrict CORS handling to a safer allowlist model.
- Validate the configured MCP file harness storage more defensively.

Current assessment
==================

The extension is on a good path security-wise, but the TYPO3-specific hardening
pass is not complete yet.
