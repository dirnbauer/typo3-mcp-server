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
- Aligned with TYPO3 v14 PageRepository and overlay behaviour
- Correct handling of fallbacks and overlay modes
- Lower maintenance cost when TYPO3 evolves

Per-site ISO ⇄ UID resolution
=============================

Language UIDs are site-local: two sites in the same installation can legitimately
assign the same UID to different languages (site A has ``hu=1``, site B has
``es=1``). The extension therefore keeps two layers of mapping in
``LanguageService``:

- A global union (first-wins) used to build schema enums across all sites.
- A per-site lookup (``getUidFromIsoCodeForPage``, ``getIsoCodeFromUidForPage``,
  ``getAvailableIsoCodesForPage``) used when a record, page, or translate
  request has site context.

The write tools resolve ``sys_language_uid`` per-site whenever a pid or uid is
known, and reverse-map UIDs back to ISO codes through the owning site when
building translate responses. Without this split, multi-site installations saw
``targetLanguage: "zh"`` for a record that was actually translated to Hungarian.

When a ``CreateSite`` call creates, updates, or changes the languages on a site,
the language cache is reset so the next ``tools/list`` or write call sees the
new layout without restarting the MCP session.

Translation ergonomics (WriteTable ``translate``)
=================================================

DataHandler's ``localize`` command creates a translation shell record that
mirrors source-language content with placeholder labels. The ``translate``
action layers several ergonomic defaults on top:

- **Visible by default.** Translations are created with ``hidden=0`` unless the
  caller explicitly passes ``hidden: true``. TYPO3 core's localize command sets
  hidden=1 on every translation, which historically surprised MCP callers by
  producing empty-looking pages.
- **Follow-up update for translated values.** Because localize copies source
  strings verbatim, the MCP tool runs a follow-up ``update`` with the values
  provided in ``data`` (including any ``slug``). This yields a single-call
  translate-and-fill experience.
- **Rollback on partial failure.** If the follow-up update fails, the
  freshly-created translation row is deleted so the caller never ends up with
  an orphan source-language record.
- **Opt-out of auto-localizing inline children.** Passing
  ``translateChildren: false`` temporarily blanks inline child field references
  on the parent so DataHandler does not copy children. Useful when translating
  inline relations manually in follow-up calls — it avoids the "already been
  localized" error path.
- **Rich response.** The tool returns ``translationUid`` (live UID),
  ``targetLanguage`` (site-aware ISO code), ``siteIdentifier``, ``slug`` when
  the table has one, and ``hidden`` reflecting the effective value on the new
  row.

Related code
============

- ``Classes/Service/LanguageService.php``
- ``Classes/MCP/Tool/GetPageTool.php``
- ``Classes/MCP/Tool/GetPageTreeTool.php``
- ``Classes/MCP/Tool/Record/AbstractRecordTool.php``
- ``Classes/MCP/Tool/Record/WriteTableTool.php``
- ``Classes/MCP/Tool/Record/CreateSiteTool.php``

Future considerations
=====================

If TYPO3 changes its language overlay internals, the extension only needs to
adjust its ``Context`` and ``PageRepository`` integration instead of replacing
an entire custom overlay system.
