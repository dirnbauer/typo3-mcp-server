.. include:: /Includes.rst.txt

====================
2026-03-15 docs audit
====================

Summary
=======

The README and TYPO3 documentation were heavily improved, but a few
documentation-quality and maintenance issues still remain.

Main findings
=============

1. :file:`Documentation/guides.xml` still points its edit link to the upstream
   repository instead of the current repository.
2. :file:`Documentation/guides.xml` should use the TYPO3 render-guides schema
   URL directly.
3. :file:`Documentation/.editorconfig` is still missing.
4. The backend module connection UX is only partially documented in the RST
   documentation.
5. The feature-tour poster and video are referenced in the README, but not yet
   integrated into the RST docs.
6. The architecture documentation contains duplicate ``.md`` and ``.rst``
   versions of the same content.

Recommended next changes
========================

- Fix :file:`Documentation/guides.xml`
- Add :file:`Documentation/.editorconfig`
- Document the backend module client setup flow in the RST docs
- Add the feature-tour media to the RST docs
- Remove duplicate Markdown architecture files where the RST version is
  canonical

Current assessment
==================

The docs are already useful and render cleanly, but the documentation audit is
not fully done until the maintenance issues above are resolved.
