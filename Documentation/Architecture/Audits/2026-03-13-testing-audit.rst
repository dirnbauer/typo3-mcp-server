.. include:: /Includes.rst.txt

===================
TYPO3 testing audit
===================

Date
====

2026-03-13

Scope
=====

``typo3-testing`` skill pass for TYPO3 v13/v14

Summary
=======

Status: The test baseline is broader now that unit tests run alongside the
functional suite.

Verified commands:

- ``ddev exec vendor/bin/phpunit -c Tests/UnitTests.xml``
- ``ddev exec composer test``
- ``ddev exec composer php-cs-fixer``
- ``ddev exec composer phpstan``

Findings
========

1. Unit-test infrastructure existed only implicitly.

   Severity: Medium

   Resolution:

   - Added ``Tests/UnitTests.xml``
   - Added ``composer test:unit``
   - Updated ``composer test`` to run unit tests before functional tests

2. CI did not exercise the unit suite or generate coverage.

   Severity: Medium

   Resolution:

   - Added a dedicated ``unit-tests`` GitHub Actions job
   - Enabled Xdebug coverage output for that job

3. Existing unit coverage included a stale test against a removed API.

   Severity: Low

   Resolution:

   - Removed the stale ``ToolAnnotationsTest``
   - Added ``Tests/Unit/Service/OAuthServiceTest.php`` for current behavior

Conformance state after remediation
===================================

- Dedicated unit-test configuration exists
- Unit tests run locally and in CI
- ``composer test`` covers both unit and functional suites
- PHPat architecture checks remain active through PHPStan
- Functional TYPO3 integration coverage remains green

Residual gaps
=============

- The unit-test surface is still small
- No browser or E2E workflow exists yet
- No mutation testing is configured
- No minimum coverage threshold is enforced yet

Assessment
==========

Status: The testing foundation is now explicit and broader, with room for
deeper unit and end-to-end coverage later.
