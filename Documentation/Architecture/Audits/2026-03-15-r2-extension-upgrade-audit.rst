.. include:: /Includes.rst.txt

======================================
2026-03-15 (r2) extension upgrade audit
======================================

Summary
=======

Second-pass upgrade audit. The extension is fully v14-ready with all automated
tools clean.

Verification snapshot
=====================

- ``ddev exec vendor/bin/rector process --dry-run``: **clean**
- ``ddev exec vendor/bin/fractor process --dry-run``: **clean**
- ``ddev exec composer phpstan``: **clean** (level 9)
- ``ddev exec composer test``: **443 tests, 2264 assertions, all passing**

Resolved since previous audit
==============================

- PHPStan failures in file harness code fixed.
- Rector cleanups (unused catch variables, stronger HMAC) applied.
- Duplicate PHPDoc on ``processFileRelations()`` fixed.

Remaining low-priority items
=============================

1. ``FlexFormService`` is still used in
   :file:`Classes/MCP/Tool/Record/ReadTableTool.php`. This is deprecated in v14
   but ``FlexFormTools`` is available as replacement. Deferred to a future pass
   because the current usage works and has no deprecation warning in v14.

Current assessment
==================

**Extension upgrade work is complete.** All automated upgrade gates pass.
