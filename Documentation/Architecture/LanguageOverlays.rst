.. include:: /Includes.rst.txt

=================
Language overlays
=================

Decision
========

The extension uses TYPO3's ``PageRepository`` API for language overlays while
keeping workspace overlays in custom code.

Context
=======

The MCP server must combine two concerns:

- Workspace transparency for MCP clients
- Correct TYPO3 language behavior, including overlays and fallbacks

Both concerns were initially handled manually, but language overlays are a
better fit for TYPO3's existing APIs than for custom query logic.

Rationale
=========

Language overlays differ from workspace overlays:

- They represent content variation, not versioning
- Clients ask for a target language and should not need overlay internals
- TYPO3 already ships battle-tested logic for language fallbacks and overlay
  modes

Using ``PageRepository`` with the proper ``Context`` and ``LanguageAspect``
keeps the implementation aligned with TYPO3 core behavior while preserving the
custom workspace transparency model.

Implementation
==============

When a language is requested, the extension:

- Creates a ``Context`` with the appropriate ``LanguageAspect``
- Instantiates ``PageRepository`` with that context
- Reads pages through TYPO3's language-aware APIs

This means the extension reuses TYPO3's overlay logic instead of reimplementing
fallback and translation behavior itself.

Benefits
========

- Less custom code for language behavior
- Better compatibility with TYPO3 v13 and v14
- Correct handling of fallbacks and overlay modes
- Lower maintenance cost when TYPO3 evolves

Related code
============

- ``Classes/MCP/Tool/GetPageTool.php``
- ``Classes/MCP/Tool/GetPageTreeTool.php``
- ``Classes/MCP/Tool/Record/AbstractRecordTool.php``

Future considerations
=====================

If TYPO3 changes its language overlay internals, the extension only needs to
adjust its ``Context`` and ``PageRepository`` integration instead of replacing
an entire custom overlay system.
