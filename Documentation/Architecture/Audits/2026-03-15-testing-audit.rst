.. include:: /Includes.rst.txt

=======================
2026-03-15 testing audit
=======================

Summary
=======

The extension has strong functional coverage and a healthy TYPO3 integration
test suite, but the unit-test layer is still thin and the newer backend module
work has no dedicated controller-level tests yet.

Verification snapshot
=====================

- ``ddev exec composer test``: passing
- Functional suite: broad coverage across MCP tools
- PHPat: configured and active

Main findings
=============

1. Unit-test coverage is still very low compared to the size of the codebase.
2. :file:`Classes/Controller/McpServerModuleController.php` has no dedicated
   tests even though it now drives a significant amount of backend-module logic.
3. Coverage is generated in CI, but there is no explicit minimum coverage gate.
4. PHPat exists, but the architecture rules are still fairly minimal.

Recommended next changes
========================

- Add focused unit tests for pure formatting and utility classes.
- Add functional or unit tests for the MCP backend module controller and token
  management flows.
- Strengthen architecture rules where practical.
- Consider a later coverage threshold once the unit-test layer is healthier.

Current assessment
==================

Testing is strong enough for functional confidence, but not yet complete against
the stricter testing-skill expectations.
