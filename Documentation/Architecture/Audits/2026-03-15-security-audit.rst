.. include:: /Includes.rst.txt

========================
2026-03-15 security audit
========================

Summary
=======

An application-security review found several actionable issues in OAuth
registration, backend token management, and client-facing output.

High-priority findings
======================

1. The OAuth registration endpoint is currently too open and returns client
   secrets directly.
2. Exception messages from registration code should be generalized instead of
   being returned directly to clients.
3. Direct-access token management in the backend module would benefit from
   stronger CSRF protection.

Medium-priority findings
========================

1. The extension still accepts direct-access tokens via URL query parameters in
   addition to headers.
2. Some CORS responses remain broader than necessary.
3. Redirect URI handling for OAuth is currently restrictive and should become a
   documented, configurable allowlist.

Low-priority findings
=====================

1. The backend module JavaScript builds token tables through ``innerHTML`` and
   should move to safer DOM rendering.
2. Logging should avoid exposing even partial token material where possible.

Recommended next changes
========================

- Lock down OAuth registration behavior.
- Replace raw exception exposure with generic error messages and logging.
- Add CSRF protection to backend token actions.
- Reduce risky fallback behavior around tokens in URLs and permissive CORS.
- Avoid HTML injection paths in the backend module JavaScript.
