.. include:: /Includes.rst.txt

================================
2026-03-15 (r2) documentation audit
================================

Summary
=======

Second-pass documentation audit using the typo3-docs skill. The extension
documentation follows TYPO3 docs.typo3.org standards and renders without
warnings.

Resolved in this pass
=====================

1. **Duplicate Markdown files removed** — Architecture documents
   (``WorkspaceTransparency.md``, ``SecurityAudit.md``, ``InlineRelations.md``,
   ``LanguageOverlays.md``) and five 2026-03-13 audit reports existed as both
   ``.md`` and ``.rst``. The ``.md`` duplicates have been removed since RST is
   the canonical format for TYPO3 documentation.

2. **Heading hierarchy fixed** — ``Installation/Index.rst`` had "Option 1" and
   "Option 2" at the same heading level as their parent section "Connection
   options". These are now subsections using ``-`` underlines.

3. **Outdated content updated** — ``InlineRelations.rst`` described
   ``sys_file_reference`` as "deliberately restricted" but this is now
   supported through the ``WriteTable`` tool's file field handling.

4. **Reference labels added** — Key section files (Introduction, Installation,
   Configuration, Tools, Architecture) now have ``.. _label:`` anchors for
   cross-referencing.

5. **Orphan toctree entries fixed** — Four r2 audit reports were not included
   in ``Audits/Index.rst``. Added to the toctree.

Verification
============

- ``composer docs:check`` (render-guides ``--fail-on-log``): **clean**
- All RST files include ``/Includes.rst.txt``
- ``guides.xml`` has project metadata, extension name, and GitHub integration
- ``.editorconfig`` matches TYPO3 documentation standards
- Images have ``:alt:`` text and ``:class: with-shadow``

Current assessment
==================

**Documentation is compliant with TYPO3 docs standards.** Structure, syntax,
directives, and rendering are all correct.
