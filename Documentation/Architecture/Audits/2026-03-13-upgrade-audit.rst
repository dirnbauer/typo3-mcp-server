.. include:: /Includes.rst.txt

=============================
TYPO3 extension upgrade audit
=============================

Date
====

2026-03-13

Scope
=====

``typo3-extension-upgrade`` skill recheck for TYPO3 v13/v14 support

Current state
=============

- Version constraints already target TYPO3 ``^13.4 || ^14.0`` and PHP
  ``>=8.2``
- ``rector.php`` and ``fractor.php`` are present
- ``composer.json`` declares ``ssch/typo3-rector`` and ``a9f/typo3-fractor``
- A DDEV project exists with PHP ``8.3``
- ``Build/setup-typo3.sh`` prepares ``public/`` and ``var/`` on fresh setup

Findings
========

1. Containerized upgrade runtime is available and working.

   Severity: Resolved

   Verified commands:

   - ``ddev exec composer rector``
   - ``ddev exec composer fractor``
   - ``ddev exec composer phpstan``
   - ``ddev exec composer test``

2. Rector applies a meaningful modernization pass and remains green after
   application.

   Severity: Resolved

   The pass initially reported changes in 76 files and remained green after
   application with PHPStan and tests passing.

3. Fractor is installed and currently clean on the configured paths.

   Severity: Resolved

4. Installed Rector and Fractor packages do not yet expose explicit TYPO3 v14
   level sets.

   Severity: Low

   v14 compatibility is therefore validated by green QA on PHP 8.3 rather than
   a dedicated TYPO3 v14 migration level.

Recommended changes
===================

1. Keep the DDEV PHP 8.3 workflow as the canonical upgrade verification path.
2. Keep Rector in the QA loop because it modernizes the codebase and remains
   green.
3. Keep Fractor in the QA loop as a regression check for non-PHP migrations.
4. Watch for future TYPO3 v14-specific Rector and Fractor releases.

Acceptance criteria
===================

- DDEV project exists and starts successfully
- TYPO3 bootstrap works on a fresh checkout
- Rector and Fractor run in a PHP 8.2+ environment
- Remaining upgrade gaps are captured from real command output
- ``ddev exec composer phpstan`` passes
- ``ddev exec composer test`` passes
