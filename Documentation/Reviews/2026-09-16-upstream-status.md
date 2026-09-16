# Upstream review — 2026-09-16

The fork baseline was `origin/main` at `7b8bf4e` (0.7.2). Freshly fetched
`upstream/main` remains `74e8188`, already an ancestor of the fork through the
September 12 merge. There are no additional main-branch commits to merge.
The newer changes below are **open upstream pull requests**, reviewed and
selectively adapted rather than merged wholesale.

| Upstream change and reviewed head | Decision in this fork |
| --- | --- |
| [#128: nested file relations](https://github.com/hauptsacheNet/typo3-mcp-server/pull/128), `228ff66` | Adapted recursive relation extraction and synchronization in `RecordInlineRelationWriteService`. Descendants stay in the fork's single DataHandler run, including nested deletes, ownership checks, and array ordering. No direct SQL writes to record relations were imported. |
| [#125: failing online media helpers](https://github.com/hauptsacheNet/typo3-mcp-server/pull/125), `0510750` | Adapted helper isolation in `UploadFileFromUrl`. One failing helper cannot block later helpers. Kept URL validation, sandbox, manifest checks, and the restriction of online-media detection to supported video hosts. Warning logs contain only the extension and exception class, since exception messages can also contain signed URLs. |
| [#121: page TSconfig and circular moves](https://github.com/hauptsacheNet/typo3-mcp-server/pull/121), `9eabdbd` | Field visibility now uses the page itself for page records and the containing page for other records. Page moves walk workspace-overlaid ancestry and reject cycles before any record data is changed. |
| [#112: shared inline child tables](https://github.com/hauptsacheNet/typo3-mcp-server/pull/112), `7c997fd` | Creation already sets `foreign_table_field` through Core. Added the missing parent-table scope to reads and synchronization; otherwise matching numeric parent UIDs could expose or claim another table's children. |
| [#108: session timeout](https://github.com/hauptsacheNet/typo3-mcp-server/pull/108), `61c930e` | Added `sessionTimeout`, defaulting to four hours, through the fork's injected extension configuration. Legacy HTTP lifecycle tests cover the default, custom expiry, and fallback. Stateless requests remain covered by the existing protocol tests. |
| [#119: inline ordering](https://github.com/hauptsacheNet/typo3-mcp-server/pull/119), `5857c33` | Production behavior already works through the unified DataHandler map. Adapted upstream's stronger creation/reordering assertions, including retained child UIDs. |
| [#126: typed filters](https://github.com/hauptsacheNet/typo3-mcp-server/pull/126), `1ffeff3` | Already covered by the fork's parameterized `filters` contract and explicit refusal of legacy SQL `where` input. Kept the existing contract and regression tests. |
| [#116: browser CORS](https://github.com/hauptsacheNet/typo3-mcp-server/pull/116), `025cd22` | Relevant transport headers, dynamic `Mcp-Param-*` headers, session-header exposure, DELETE, and preflight handling already exist. Retained exact-origin and header validation rather than importing unrestricted reflection. Responses already carry no-store security headers. |

The upstream branches rejecting scalar-field arrays (`37e84b0`) and live
workspace writes (`f479cad`) were also compared. The fork already rejects
arrays for scalar fields and enforces draft writes in strict mode; its
intentional trusted-local-mode behavior is retained.

Regression tests first reproduced the missing behavior. Additional checks
cover multiple nesting levels, replacing/patching/removing nested files,
unchanged live references, shared-table ownership, content-page TSconfig,
staged page ancestry, and credential-safe helper warnings. Temporarily
restoring the original relation, field-read, and upload implementations made
the added focused regressions fail again.

Validation on TYPO3 14.3.6:

- `ddev exec composer test`: **130 unit tests, 982 assertions; 1,129 functional
  tests, 7,012 assertions** (PHP 8.4.22, SQLite).
- `ddev exec composer phpstan`, `ddev exec composer php-cs-fixer`, and
  `ddev exec composer rector`: passed. The updated relation types removed 54
  obsolete PHPStan baseline occurrences; no additional occurrences are ignored.
- `/opt/homebrew/opt/php@8.5/bin/php Build/protocol-smoke.php`: passed legacy,
  automatic negotiation, and modern modes, including the Abilities bridge.
- `/opt/homebrew/opt/php@8.5/bin/php vendor/bin/fractor process --dry-run`:
  passed.
- `composer docs:check`: passed.

The protocol smoke test and Fractor used host PHP 8.5 because the existing local
SQLite settings and generated Fractor package map contain Mac paths, which are
unavailable inside DDEV. The local configuration was left intact.

Not run: browser E2E and LLM scenarios. Test fixtures model the relevant Content
Blocks TCA shapes without adding Content Blocks as a runtime or development
dependency; they do not claim coverage of an installed Content Blocks package.
