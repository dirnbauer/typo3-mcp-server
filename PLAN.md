# TYPO3 MCP Server Record Management Plan

This file began as Marco Pfeiffer's early record-management plan on 2025-04-30.
That original direction was strong and unusually clear: TYPO3-native, editor-
first, workspace-safe, and centered on TCA instead of raw SQL thinking. This
updated version keeps that intent, records what is already implemented, and
documents the additional TYPO3 v14-only hardening and MCP-quality work that was
added in the latest maintenance round.

## Current Status

This is no longer a speculative plan only. The core design has been shipped and
refined.

- TYPO3 support is now **v14-only**.
- The record-management concept is implemented through `ListTables`,
  `GetTableSchema`, `GetFlexFormSchema`, `ReadTable`, `WriteTable`, `Search`,
  `GetPage`, `GetPageTree`, and `ListWorkspaces`.
- File handling is implemented separately through the MCP file sandbox
  (`BrowseFiles`, `ReadFileMetadata`, `WriteFile`, `UploadFile`,
  `UploadFileFromUrl`).
- The latest round tightened security, clarified schemas and annotations,
  cleaned the docs, and aligned tool ergonomics with the public
  [mcp-builder skill](https://github.com/anthropics/skills/blob/main/skills/mcp-builder/SKILL.md).

## Design Invariants

These points are not historical notes anymore; they are active project rules.

- **TCA-first**: record access and validation come from TYPO3 TCA and TYPO3 core
  APIs, not handwritten table adapters wherever avoidable.
- **Workspace-first**: record writes must never edit live rows directly. TYPO3
  workspaces remain the mandatory safety boundary.
- **Transparent workspaces**: MCP clients should not have to understand TYPO3
  version rows. Results must look stable and use live-facing identifiers.
- **Page-centric context**: pages remain the main navigation model even when
  generic table tools are used.
- **Permission-aware output**: table access, field visibility, and language
  options must reflect the authenticated backend user and site configuration.
- **LLM-usable contracts**: descriptions, JSON Schema, pagination hints, and
  errors should help an agent recover and continue instead of guessing.

## Original Plan vs. Current Tool Map

The original plan used slightly different names. The implemented tool set is:

| Original plan concept | Current tool | Notes |
| --- | --- | --- |
| `ListTableTypes` | `ListTables` | Lists accessible TYPO3 tables with purpose/access information |
| `GetTableType` | `GetTableSchema` | Returns TCA-driven schema details and field semantics |
| FlexForm follow-up | `GetFlexFormSchema` | Added separately for plugin/data-structure inspection |
| `ReadTable` | `ReadTable` | Implemented with filters, language handling, relations, pagination metadata |
| `WriteTable` | `WriteTable` | Implemented with create/update/translate/delete in workspaces |
| Additional discovery | `GetPage`, `GetPageTree`, `Search` | Added to make record work practical for LLMs |
| Workspace visibility | `ListWorkspaces` | Added so clients can inspect/select a workspace explicitly |

## What Is Implemented Today

### Read-side behavior

- `ListTables` exposes only tables that are actually available to the current
  backend user and safe to expose through MCP.
- `GetTableSchema` and `GetFlexFormSchema` provide field-level descriptions,
  types, enums, and structure based on TYPO3 configuration rather than guesswork.
- `ReadTable` supports:
  - `pid` / `uid` filtering
  - restricted `where` expressions
  - `limit` / `offset`
  - JSON responses with `total`, `limit`, `offset`, and `hasMore`
  - optional language filtering when the instance supports multiple languages
  - workspace overlays instead of live-only reads

### Write-side behavior

- `WriteTable` uses TYPO3 `DataHandler`.
- Writes are created in a writable workspace, not live.
- The tool supports create, update, translate, and delete flows.
- Positioning semantics (`top`, `bottom`, `before:UID`, `after:UID`) are
  translated into TYPO3-compatible behavior.
- Validation errors are shaped to be understandable for an agent and actionable
  for editors.

### File-side behavior

- Physical files are intentionally separated from record writes because TYPO3
  does not workspace-version files.
- All file tools are restricted to the MCP file sandbox (default `1:/mcp/`).
- `UploadFileFromUrl` exists to avoid base64 limits and adds SSRF protections,
  redirect limits, and size limits.

## Latest TYPO3 v14 Maintenance Round

The most recent overhaul added or clarified the following:

### TYPO3 v14-only alignment

- CI and docs were cleaned up to reflect TYPO3 v14-only support.
- Stale v13 wording was removed from the architecture docs and comments.
- The project now explicitly communicates that MCP contracts may evolve in the
  TYPO3 v14 line when that improves clarity or safety.

### Security hardening

- MCP HTTP request logging now redacts sensitive headers and query tokens.
- Query-string bearer tokens are disabled by default and only available behind
  an explicit extension setting.
- The lightweight `?test=auth` diagnostic was reduced to minimal JSON and made
  configurable.
- Security documentation was consolidated into `Documentation/Architecture/SecurityAudit.rst`.

### MCP ergonomics

- All tools now expose the full MCP annotation set:
  `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`.
- Tool descriptions and schema hints were tightened, especially around
  pagination, workspace use, and file sandbox behavior.
- Unknown tool names in `tools/call` now return a `CallToolResult` error instead
  of falling through to a generic JSON-RPC internal error path.

### Tests

- Added targeted tests for:
  - MCP HTTP security behavior
  - log redaction
  - URL upload rejection of unsafe hosts/schemes
- Existing functional coverage remains focused on TYPO3 integration, workspace
  transparency, permissions, and extension compatibility.

## Remaining Evolution Areas

These are still valid future areas, but they should now be read as product
evolution topics, not missing basics.

- **Workspace publishing**: listing/selecting workspaces is supported, but
  publishing remains a TYPO3 backend action.
- **Structured outputs**: the current PHP MCP SDK exposes `structuredContent`
  on results, but this project does not yet publish a full output-schema
  contract like some TypeScript/Python stacks do.
- **Tool naming strategy**: PascalCase names are kept today for TYPO3 clarity;
  prefixed tool names remain an option if LLM discoverability proves better.
- **Large workflow ergonomics**: more chunking/batching guidance may be useful
  for very large site updates.
- **History / audit UX**: TYPO3's own history and review flows still remain the
  primary source of truth.

## Technical Notes

- Use TYPO3 core APIs whenever possible (`DataHandler`, `PageRepository`, TCA,
  FAL, language/site APIs).
- Record-backed tools may change names or parameters if that improves LLM
  ergonomics; backward compatibility is intentionally not guaranteed for MCP
  tool contracts.
- When TYPO3 does not meaningfully support multiple languages, translation-
  related parameters should be hidden instead of exposed as misleading options.
- In tests, MCP tool calls should be asserted with:
  `$this->assertFalse($result->isError, json_encode($result->jsonSerialize()));`

## Professional Acknowledgement

Marco Pfeiffer's original plan established the right foundation for this
extension: TYPO3-native, practical for editors, and ambitious without ignoring
core safety boundaries. This updated document keeps that work visible because it
continues to shape the implemented architecture.
