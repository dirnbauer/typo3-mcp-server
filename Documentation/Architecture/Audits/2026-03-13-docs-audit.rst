.. include:: /Includes.rst.txt

================
TYPO3 docs audit
================

Date
====

2026-03-13

Scope
=====

``typo3-docs`` skill pass for TYPO3 v13/v14

Summary
=======

Status: Documentation structure is aligned more closely with docs.typo3.org and
the current MCP tool surface.

Findings
========

1. Audit reports were not discoverable from the published docs.

   Severity: Medium

   Resolution:

   - Added ``Documentation/Architecture/Audits/Index.rst``
   - Linked the audit section from ``Documentation/Architecture/Index.rst``

2. ``guides.xml`` lacked TYPO3 documentation metadata.

   Severity: Medium

   Resolution:

   - Added the extension key
   - Added the TYPO3 Core API inventory
   - Added edit-on-GitHub settings

3. The tool reference drifted from the implemented schemas.

   Severity: Medium

   Resolution:

   - Updated the tool reference for current parameter names and behavior
   - Clarified that ``ReadTable.where`` is a restricted filter expression
   - Clarified workspace override behavior in the docs

Residual gaps
=============

- No docs rendering check existed in CI before this follow-up
- Screenshot coverage for backend-module docs could still be expanded
- More end-to-end examples would still help for translation, file handling,
  and OAuth

Assessment
==========

Status: Documentation is easier to navigate and less likely to mislead readers
about the current MCP contracts.
