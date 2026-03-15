.. include:: /Includes.rst.txt

====================================
2026-03-15 (r2) conformance audit
====================================

Summary
=======

Second-pass conformance audit. Core conformance gates (PHPStan, Rector, Fractor,
CI alignment) are met. Service-locator usage remains the main gap but is
acceptable for the extension's architecture.

What is conformant
==================

- Backend module registered via ``Configuration/Backend/Modules.php``
- ``composer.json`` and ``ext_emconf.php`` target TYPO3 v14 and PHP 8.2+
- ``declare(strict_types=1)`` in every PHP file
- PHPStan level 9 clean
- Rector and Fractor clean
- CI code-quality job uses PHP 8.3
- Backend AJAX routes use ``inheritAccessFromModule``
- ``Services.yaml`` uses autowire+autoconfigure with no redundant wiring
- Backend JavaScript uses ``escapeHtml()`` for dynamic content

Service locator usage
=====================

Many classes use ``GeneralUtility::makeInstance()`` for services that could be
constructor-injected. The highest-impact candidates:

- ``OAuthService`` (7+ call sites in HTTP endpoints)
- ``ConnectionPool`` in ``OAuthService`` (12 uses)
- ``LanguageService`` in tool classes
- ``WorkspaceContextService`` / ``TableAccessService`` in ``AbstractRecordTool``

These are not changed in this pass because:

1. The tool classes use ``makeInstance`` in constructors which prevents
   circular-dependency issues with TYPO3's container.
2. HTTP endpoints use ``makeInstance`` because they are instantiated by the
   middleware pipeline, not by the DI container.
3. Refactoring these would be a large change with regression risk and limited
   user-facing benefit.

Remaining gaps
==============

1. Backend module JavaScript is plain script, not an ES6 module using TYPO3
   backend APIs (``@typo3/backend/modal.js``). Acceptable for the current
   feature set.
2. ``TcaFormattingUtility`` and ``RecordFormattingUtility`` use static methods
   with internal ``makeInstance`` calls. Converting to instance services is
   deferred.

Current assessment
==================

**Conformance is sufficient for production use.** The extension passes all
automated quality gates. Service-locator patterns are a known debt item tracked
for future improvement.
