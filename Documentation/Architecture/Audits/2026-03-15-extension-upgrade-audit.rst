.. include:: /Includes.rst.txt

==============================
2026-03-15 extension upgrade audit
==============================

Summary
=======

The extension is already on TYPO3 v14 and PHP 8.2+, but there are still a few
upgrade-oriented follow-ups for long-term maintenance and future TYPO3 v15
compatibility.

Verification snapshot
=====================

- ``ddev exec composer rector``: reports pending cleanup changes
- ``ddev exec composer fractor``: clean
- ``ddev exec composer phpstan``: currently failing
- ``ddev exec composer test``: passing

Main findings
=============

1. ``FlexFormService`` is still used in
   :file:`Classes/MCP/Tool/Record/ReadTableTool.php`. This should move to
   TYPO3's newer FlexForm tooling before v15 work.
2. Rector still proposes small modernization changes, mainly unused catch
   variables and stronger HMAC usage in OAuth-related code.
3. PHPStan currently fails in the file harness work, so the static-analysis gate
   is not green even though the functional test suite passes.

Recommended next changes
========================

- Replace ``FlexFormService`` usage with TYPO3 v14-compatible FlexForm APIs.
- Apply the pending Rector cleanups that do not change behavior.
- Fix the current PHPStan findings before claiming the upgrade work is complete.

Current assessment
==================

The extension is v14-ready in practice, but the upgrade pass is not fully done
while Rector and PHPStan still report follow-up work.
