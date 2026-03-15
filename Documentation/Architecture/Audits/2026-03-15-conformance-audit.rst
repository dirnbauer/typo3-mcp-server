.. include:: /Includes.rst.txt

==========================
2026-03-15 conformance audit
==========================

Summary
=======

The extension is structurally conformant for TYPO3 v14, but several quality and
backend-module conformance gaps remain.

What is already conformant
==========================

- Backend module registration uses :file:`Configuration/Backend/Modules.php`
- ``composer.json`` and ``ext_emconf.php`` target TYPO3 v14 and PHP 8.2+
- PHP files consistently use ``declare(strict_types=1);``
- PHPat and PHPStan are configured
- Static analysis is already configured at PHPStan level 9

Main findings
=============

1. The backend module JavaScript in
   :file:`Resources/Public/JavaScript/mcp-module.js` is still plain script code
   and uses browser dialogs instead of TYPO3 backend dialog APIs.
2. A large part of the extension still relies on
   ``GeneralUtility::makeInstance()`` instead of constructor injection.
3. :file:`Configuration/Services.yaml` still contains some controller wiring
   that autowiring could handle directly.
4. The CI code-quality job in :file:`.github/workflows/tests.yml` still uses
   PHP 8.2 while most other jobs already test on PHP 8.3 or newer.

Recommended next changes
========================

- Refine the backend module UI implementation toward modern TYPO3 backend
  patterns.
- Reduce service-locator usage where it is practical and improve dependency
  injection in core services and tools.
- Clean up redundant service definitions.
- Align code-quality CI to PHP 8.3.

Current assessment
==================

The extension is v14-conformant enough to run and ship, but it is not yet
fully polished against the stronger conformance criteria from the audit skill.
