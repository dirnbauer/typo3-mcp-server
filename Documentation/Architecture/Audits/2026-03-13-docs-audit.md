# TYPO3 Documentation Audit

Date: 2026-03-13
Scope: `typo3-docs` skill pass for TYPO3 v13/v14

## Summary

Status: Documentation structure is sound and now better aligned with docs.typo3.org expectations and the current tool surface.

## Findings

### 1. New audit reports were not discoverable from the published docs

Severity: Medium

- Multiple dated audit reports existed under `Documentation/Architecture/Audits/`,
  but there was no index page or navigation entry to reach them.

Resolution:

- Added `Documentation/Architecture/Audits/Index.rst`.
- Linked the audit index from `Documentation/Architecture/Index.rst`.

### 2. `guides.xml` lacked TYPO3 documentation metadata

Severity: Medium

- The docs config only declared the project title/version.
- Important metadata for TYPO3 documentation rendering and maintenance was missing.

Resolution:

- Added the extension key.
- Added the TYPO3 Core API inventory.
- Added edit-on-GitHub settings for the repository and branch.

### 3. Tool reference drifted from the implemented schemas

Severity: Medium

- `Documentation/Tools/Index.rst` still documented outdated parameter names and
  behaviors for several tools.
- The biggest mismatches were around:
  - `GetPageTree`
  - `GetPage`
  - `ReadTable.where`
  - `SearchTool`

Resolution:

- Updated the tool reference to match the current schema names and safer behavior.
- Clarified that `ReadTable.where` is a restricted filter expression, not raw SQL.
- Clarified workspace override behavior in `ListWorkspaces`.

## Residual gaps

These remain good follow-ups, but were not required for this pass:

- No docs rendering check in CI yet
- No screenshot/image coverage for the backend module docs
- The tool reference could still be expanded with richer examples for translation,
  file handling, and OAuth setup

## Assessment

Status: Documentation is now easier to navigate, better configured for TYPO3 docs
tooling, and less likely to mislead readers about the current MCP tool contracts.
