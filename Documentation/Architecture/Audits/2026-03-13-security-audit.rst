.. include:: /Includes.rst.txt

====================
TYPO3 security audit
====================

Date
====

2026-03-13

Scope
=====

``typo3-security`` and ``security-audit`` pass for TYPO3 v13/v14

Summary
=======

Status: Critical and high-impact findings were remediated.

Verified commands:

- ``ddev exec composer php-cs-fixer``
- ``ddev exec composer phpstan``
- ``ddev exec composer test``

Remediated findings
===================

1. Direct-access tokens were stored inconsistently.

   Severity: High

   Resolution: direct tokens are now stored as SHA-256 hashes, and the backend
   UI treats them as non-recoverable secrets.

2. ``ReadTableTool.where`` accepted raw SQL fragments.

   Severity: High

   Resolution: the raw passthrough was replaced with a constrained parser for
   literal comparisons, ``AND`` / ``OR``, ``LIKE``, ``IN (...)``, and null
   checks.

3. The OAuth continuation cookie lacked integrity protection.

   Severity: Medium

   Resolution: the cookie is now signed and verified with TYPO3
   ``HashService``.

4. OAuth authorization accepted unsafe redirect and PKCE combinations.

   Severity: Medium

   Resolution: only ``S256`` is advertised and enforced, and redirect URIs are
   validated before use.

5. The auth debug endpoint echoed authorization headers.

   Severity: Medium

   Resolution: the endpoint now reports only whether a header was present.

6. Internal exception messages leaked to clients.

   Severity: Low

   Resolution: client-facing responses are generic while detailed errors remain
   in server-side logs.

Residual hardening opportunities
================================

- Add rate limiting to token creation and OAuth endpoints
- Narrow CORS origins further where deployments allow it
- Replace the fixed test admin password with an environment variable
- Add functional tests for redirect validation and cookie tamper detection

Assessment
==========

Status: Secure baseline improved, with the highest-value issues addressed in
code and verified by QA.
