.. include:: /Includes.rst.txt

=======================
TYPO3 conformance audit
=======================

Date
====

2026-03-13

Scope
=====

``typo3-conformance`` skill pass for TYPO3 v13/v14

Summary
=======

Status: Improved, with core quality gates now enforced in the repository and
in CI.

Verified commands:

- ``ddev exec composer php-cs-fixer``
- ``ddev exec composer phpstan``
- ``ddev exec composer test``

Findings
========

1. Architecture rules were not enforced.

   Severity: Medium

   Resolution:

   - Added ``phpat/phpat``
   - Wired PHPat into ``phpstan.neon``
   - Added architecture rules in ``Tests/Architecture/ArchitectureTest.php``

2. Coding-style configuration existed but was not enforced.

   Severity: Medium

   Resolution:

   - Added ``friendsofphp/php-cs-fixer``
   - Added Composer scripts for dry-run and fix modes
   - Added CI checks for PHP CS Fixer, Rector, and Fractor

3. Bootstrap metadata files were missing strict types.

   Severity: Low

   Resolution:

   - Added ``declare(strict_types=1);`` to ``ext_emconf.php`` and
     ``ext_localconf.php``

Conformance state after remediation
===================================

- PHP 8.2+ minimum declared
- TYPO3 ``^13.4 || ^14.0`` declared
- PHPStan level 9 active
- PHPat architecture rules active through PHPStan
- PHP CS Fixer active
- Rector dry-run active
- Fractor dry-run active
- Functional test matrix present in CI

Residual gaps
=============

- Unit coverage remains small relative to the functional suite
- There is no browser E2E workflow yet
- Mutation testing is not configured

Assessment
==========

Score: 88/122

Grade: A
