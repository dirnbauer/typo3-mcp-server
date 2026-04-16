# TYPO3 MCP Server

**Thank you** to **[hauptsacheNet](https://github.com/hauptsacheNet)** and
**Marco Pfeiffer** for creating and open-sourcing the original TYPO3 MCP
Server—their TYPO3-native, workspace-aware design is what this project builds
on.

This repository **maintains, updates, and extends** that upstream with
additional integration work and **new capabilities**. Some of those additions
are **experimental**: they may change shape or defaults as we iterate with real
editor and MCP workflows—validate in **staging** before you rely on them in
production.

---

[Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server for
TYPO3: pages, records, TCA/FlexForm schemas, workspaces, FAL-backed search,
and a constrained file sandbox. MCP clients get structured tools and
machine-readable responses; TYPO3 stays authoritative for permissions, TCA,
workspaces, and editorial workflows (no scraping the backend UI).

**Stack:** TYPO3 **v14**, PHP **8.2+**, `typo3/cms-workspaces`. Tool names,
schemas, OAuth, and integration points **will change** across releases when
that improves safety or editor workflows—pin Composer versions and read
upgrade notes for production sites.

**Upstream:** [hauptsacheNet/typo3-mcp-server](https://github.com/hauptsacheNet/typo3-mcp-server).

Treat this as **pilot / staging / supervised editorial** use until your own QA
says otherwise. Some tools create sites, install extensions, or run whitelisted
CLI commands: try that only on **non-production** instances and narrow the tool
surface you expose.

**Typical capabilities**

- Page tree navigation and page context
- Workspace-aware record reads and writes (transparent live UIDs)
- TCA / FlexForm introspection before writes
- Translations via ISO language codes when the site has multiple languages
- Table + media search across FAL
- Sandbox-scoped file browse/upload/metadata
- AttachImage: stage images in the sandbox (URL or existing `sys_file`), optional FAL CropScaleMask processing (resize, format), attach to TCA file fields
- Workspace review, publish, rollback (with safe defaults)
- Copy/duplicate records with relations
- Content audit, system log reads, redirects, bulk writes, imports (where enabled)
- Remote HTTP (OAuth 2.1 + PKCE) or local `vendor/bin/typo3 mcp:server` (stdio)

## Why it exists

TYPO3 backends are optimized for people using forms, trees, and list modules.
LLMs need explicit tools, stable identifiers, and machine-readable schemas.
This extension maps MCP calls back to TYPO3 concepts instead of trying to make
AI interact with backend HTML.

The core contract is:

1. MCP clients talk to tools, not to rendered TYPO3 forms.
2. TYPO3 remains the source of truth for permissions, TCA, workspaces,
   language handling, and DataHandler behavior.
3. Editors still review and publish changes through normal TYPO3 workflows.

## Architecture at a glance

The implementation is split into a few clear layers:

- Connection layer:
  `Classes/Http/McpEndpoint.php` handles OAuth validation and backend user
  setup, then calls the SDK's `HttpServerRunner::handleRequest()` directly
  and maps the full SDK response into TYPO3's PSR-7 pipeline (including
  `Mcp-Session-Id` and other protocol headers). Session state is persisted to
  `var/mcp_sessions/` via `FileSessionStore`. OAuth discovery, authorization,
  and token endpoints live alongside it in `Classes/Http/`.
  `Classes/Command/McpServerCommand.php` exposes a local stdio server for
  development and trusted local clients.
- MCP runtime:
  `Classes/MCP/McpServerFactory.php` builds the MCP server and registers
  handlers. `Classes/MCP/ToolRegistry.php` auto-registers every service tagged
  `mcp.tool` via Symfony DI. Native `ToolInterface` implementations are used
  directly; other tagged objects are wrapped in `CompatibleToolAdapter` which
  normalizes their schema and result types automatically.
- Tool layer:
  `Classes/MCP/Tool/` contains page, search, workspace, and file tools.
  `Classes/MCP/Tool/Record/` contains the TCA-driven record tools (including
  `AttachImage` for image staging + file references).
  `AbstractRecordTool` injects the optional `workspace_id` parameter and
  switches workspace context before the tool executes.
- Shared services:
  `WorkspaceContextService` handles workspace selection/creation and context
  switching, `TableAccessService` is the central TCA/permission gate,
  `LanguageService` maps TYPO3 languages to ISO codes, `McpFileSandboxService`
  enforces the file sandbox, `SiteInformationService` resolves domains and URLs,
  and `OAuthService` manages auth codes and hashed access tokens.
- TYPO3 core integration:
  writes go through `DataHandler`, table/schema introspection uses TCA and
  `TcaSchemaFactory`, page language overlays use `PageRepository`, and file
  handling goes through TYPO3 FAL.

Tool design (schemas, MCP annotations, pagination hints, errors) follows
common MCP server guidance; see **MCP ergonomics (mcp-builder alignment)** in
[`TECHNICAL_OVERVIEW.md`](TECHNICAL_OVERVIEW.md) and
[`Documentation/Tools/Index.rst`](Documentation/Tools/Index.rst).

## Behavioral guarantees

### Workspace transparency

Record-backed MCP writes are workspace-first.

- Clients can explicitly choose a workspace by passing `workspace_id` on
  record-backed tools.
- If `workspace_id` is omitted and the authenticated backend user is already in
  a non-live workspace, that workspace is kept.
- Otherwise the extension selects the first writable workspace.
- If none exists and the user is allowed to create one, the extension creates
  an MCP workspace automatically.
- MCP clients keep using stable, live-facing UIDs while the extension resolves
  internal workspace versions behind the scenes.

This behavior is implemented in `WorkspaceContextService`, the record-tool base
class, and the custom workspace restriction logic under
`Classes/Database/Query/Restriction/`.

### TCA-first contracts

The tool interface is derived from TYPO3 TCA, not from handwritten per-table
adapters. That gives the MCP client:

- field labels and descriptions TYPO3 editors already use
- field visibility filtered through permissions and TSconfig
- correct type handling for record subtypes, palettes, relations, and FlexForms
- compatibility with TYPO3 core tables and third-party extensions such as
  `georgringer/news`

### Language-aware, but only when TYPO3 supports it

Language parameters are not exposed unconditionally.

- Tools only add `language` parameters when more than one language is available
  in the site configuration.
- `WriteTable` accepts ISO language codes such as `de` and `fr` for
  `sys_language_uid`.
- Page overlays use TYPO3 `PageRepository` APIs, while workspace overlays stay
  in custom transparency logic.

If an instance has no meaningful language setup, translation-specific behavior
is hidden instead of being exposed as half-working parameters.

### File operations use a file sandbox, not file versioning

File tools are intentionally constrained to a dedicated MCP file sandbox, which
defaults to:

```text
1:/mcp/
```

This usually maps to:

```text
fileadmin/mcp/
```

Important TYPO3 rule: physical files are not workspace-versioned.

The extension is explicit about that:

- `BrowseFiles`, `ReadFileMetadata`, `WriteFile`, `UploadFile`, and
  `UploadFileFromUrl` are restricted to the sandbox root
- uploads can be routed into workspace-specific subfolders
- file references remain workspace-versioned records
- the underlying file still exists immediately once written or uploaded

### Intended behavior

This repository treats the written contract as part of the product surface. The
high-level intent is:

- discovery tools (`GetPageTree`, `GetPage`, `ListTables`, `Search`,
  `SearchMedia`) help an MCP client understand the current TYPO3 instance before
  it writes anything
- schema tools (`GetTableSchema`, `GetFlexFormSchema`) explain *what TYPO3
  expects*, not an MCP-only abstraction layer
- record writes (`WriteTable`, `BulkWrite`, `CopyContent`, `AttachImage`) stage
  record changes in a TYPO3 workspace and keep live-facing UIDs stable in tool
  output
- publish / rollback tools default to preview mode first, so destructive actions
  require an explicit second step
- import tools are designed for reviewable workflows: `ImportContent` and
  `ImportFromUrl` can analyze first and create later
- admin tools (`CreateSite`, `InstallExtension`, `SafeCli`) are intentionally
  narrower than unrestricted shell or filesystem access
- optional capability families stay explicit: `ManageRedirects` depends on
  `sys_redirect` being available, and the x402 tools only become meaningful when
  the paywall extension is installed

For the more detailed product-level spec, see
[`Documentation/Introduction/IntendedBehavior.rst`](Documentation/Introduction/IntendedBehavior.rst)
and [`TECHNICAL_OVERVIEW.md`](TECHNICAL_OVERVIEW.md).

## Available tools

### Discovery and navigation

- `GetPageTree`
- `GetPage`
- `ListTables`
- `Search`
- `SearchMedia` — search files across all FAL storage by metadata, type, or
  dimensions

### Schema and reading

- `ReadTable`
- `GetTableSchema`
- `GetFlexFormSchema`

### Writing

- `WriteTable`
- `CopyContent` — duplicate records via DataHandler, preserving file references
  and relations
- `AttachImage` — stage or transform sandbox images and create
  `sys_file_reference` rows on TCA file fields

### Content import

- `ImportContent` — analyze raw content (text, Markdown, HTML) and propose
  TYPO3 content elements with CType mapping. Use the proposal for review, or use
  the tool's execute mode to create the elements directly when that is the
  better workflow.

### Content quality and diagnostics

- `ContentAudit` — scan page trees for missing meta descriptions, alt text,
  empty content, and pages without content
- `GetSystemLog` — read TYPO3 system log entries for debugging failed
  operations and recent errors

### Files

- `BrowseFiles`
- `ReadFileMetadata`
- `WriteFile`
- `UploadFile`
- `UploadFileFromUrl`

### Workspaces

- `ListWorkspaces`
- `WorkspaceReview` — review all pending changes in a workspace with
  field-level diffs before publishing
- `PublishWorkspace` — publish pending workspace changes to live (dry-run by
  default for safety)
- `RollbackWorkspace` — discard pending workspace changes (dry-run by default)

### Batch operations

- `BulkWrite` — execute multiple create/update/delete operations in a single
  DataHandler transaction (max 50 per call)

### URL redirects

- `ManageRedirects` — list `sys_redirect` records and explain redirect write
  limitations when the TYPO3 redirects table is available

If the redirects surface is missing, the tool returns configuration guidance
instead of a raw table-access failure. If it is available, `list` works
normally, but TYPO3 keeps `sys_redirect` outside workspaces, so `create` and
`delete` are rejected instead of editing live redirect rows through MCP.

### Content import from URL

- `ImportFromUrl` — fetch a web page, extract content, and propose or create
  TYPO3 pages with content elements (SSRF-protected)

### Site management

- `CreateSite` — create or update TYPO3 site configurations with languages
  (admin-only)

### Extension management

- `InstallExtension` — install, activate, or search TYPO3 extensions via
  Composer (admin-only)

### System maintenance

- `SafeCli` — execute whitelisted TYPO3 CLI commands (`cache:flush`,
  `cache:warmup`, `referenceindex:update`, `extension:list`, `site:list`,
  `site:show`)

### Optional x402 monetization

- `ListPaidContent` — list pages gated by the optional x402 paywall extension
- `GetPaidContent` — return payment requirements or paid content for a page
- `GetPaymentStats` — summarize payment activity and revenue when the x402
  payment-log table exists

When x402 is not installed, these tools return configuration information instead
of failing with raw database errors.

## Authentication and client setup

The extension supports two connection models:

- remote MCP over HTTP with OAuth 2.1 style flows and PKCE
- local stdio MCP via `vendor/bin/typo3 mcp:server`

**Local stdio** runs as your **operating-system user**; TYPO3 constrains CMS
operations, not the host. Clients that expose shell/terminal access or wrap the
server in `bash`/scripts widen risk to arbitrary host commands—see **Local stdio
and the host OS boundary** in [`TECHNICAL_OVERVIEW.md`](TECHNICAL_OVERVIEW.md),
the local CLI section in [`Documentation/Installation/Index.rst`](Documentation/Installation/Index.rst),
and **User → MCP Server → Local Setup (TYPO3 CLI)**.

The backend module under `User > MCP Server` is the operator-facing entry
point. It exposes:

- the MCP endpoint URL
- OAuth/client setup instructions
- token management
- health checks for discovery endpoints

## Installation

Install with Composer:

```bash
composer require hn/typo3-mcp-server
vendor/bin/typo3 extension:activate mcp_server
```

Requirements:

- TYPO3 `^14.0`
- PHP `>=8.2`
- `typo3/cms-workspaces`

Before connecting an MCP client, make sure the target backend user has:

- the backend module available
- page mounts for the content area it should work on
- table permissions for the records it should manage
- access to a writable workspace, or permission for automatic workspace
  creation

## Configuration

The two most important extension settings are:

- `fileSandboxRoot`
  Combined folder identifier for the MCP file sandbox. Default: `1:/mcp/`
- `workspaceUploadSubfolders`
  When enabled, uploads are stored below workspace-specific folders such as
  `1:/mcp/workspaces/ws-3/`
- `allowMcpTokenInQueryString`
  Off by default. Only enable this if you explicitly need legacy query-string
  bearer tokens and accept the related proxy/logging risk.
- `enableMcpAuthHeaderDiagnostic`
  Controls the lightweight backend-module auth-header diagnostic. Enabled by
  default, but intentionally minimal and suitable for hardening if disabled.

These settings do not change TYPO3 core file semantics. They only constrain and
organize MCP-driven file work.

## Testing and quality

The repository has three complementary test layers:

- unit tests for focused services such as OAuth hashing and file sandbox path
  handling
- TYPO3 functional integration tests for tool behavior, permissions, language
  overlays, workspace transparency, file sandbox restrictions, and write flows
- LLM-oriented tests under `Tests/Llm/` that use real models to verify that the
  tool contracts are intuitive in realistic multi-step workflows

Run the default test suite:

```bash
composer test
```

Additional useful commands:

```bash
composer test:llm
composer docs:check   # RST render check; needs Docker (see “Documentation” below)
composer phpstan
```

The functional suite already covers important extension-level scenarios:

- workspace selection, transparent live/workspace UID handling, and delete
  placeholders
- multilingual reads, writes, and overlay behavior
- non-admin permission filtering
- file sandbox browsing, writes, metadata, and uploads
- MCP endpoint security defaults and URL upload safety checks
- extension compatibility with `georgringer/news`
- media search across FAL storage with metadata and dimension filters
- content audit checks for SEO and quality issues
- system log reading with severity/component/date filtering
- workspace change review with field-level diffs
- record duplication via CopyContent with overrides
- CLI command validation, argument allowlisting, and shell injection rejection
- workspace publishing with dry-run preview and live execution
- bulk write operations with per-operation result tracking
- content import with format detection, CType mapping, and section merging

## Repository map

- `Classes/MCP/`
  MCP server factory, tool registry, `CompatibleToolAdapter`, and tool
  implementations
- `Classes/Service/`
  workspace, TCA access, language, file sandbox, site, and OAuth services
- `Classes/Http/`
  remote MCP endpoint and OAuth/discovery endpoints
- `Configuration/`
  service wiring, backend module registration, middleware, and routes
- `Documentation/`
  TYPO3 RST documentation
- `TECHNICAL_OVERVIEW.md`
  long-form design rationale and usage scenarios
- `Tests/`
  unit, architecture, TYPO3 functional, and LLM-assisted tests

## Documentation

TYPO3 extension manuals are usually published on [docs.typo3.org](https://docs.typo3.org/)
when the extension is set up for official rendering; the canonical sources are
always under `Documentation/` (reStructuredText).

**Local render check** (Docker; fails on RST problems):

```bash
composer docs:check
```

Uses `ghcr.io/typo3-documentation/render-guides` with the project tree mounted.

**Suggested reading order**

| Topic | File |
| --- | --- |
| Overview and safety model | [`Documentation/Introduction/Index.rst`](Documentation/Introduction/Index.rst) |
| Intended behavior / feature spec | [`Documentation/Introduction/IntendedBehavior.rst`](Documentation/Introduction/IntendedBehavior.rst) |
| Install and activate | [`Documentation/Installation/Index.rst`](Documentation/Installation/Index.rst) |
| Module, OAuth, sandbox | [`Documentation/Configuration/Index.rst`](Documentation/Configuration/Index.rst) |
| MCP tools reference | [`Documentation/Tools/Index.rst`](Documentation/Tools/Index.rst) |
| Design and deep dives | [`Documentation/Architecture/Index.rst`](Documentation/Architecture/Index.rst) |

**Also useful**

- [`TECHNICAL_OVERVIEW.md`](TECHNICAL_OVERVIEW.md) — long-form architecture and scenarios
- [`BUGLIST.md`](BUGLIST.md) — audit findings, fixes, and environment-dependent gaps
- [`Documentation/Architecture/WorkspaceTransparency.rst`](Documentation/Architecture/WorkspaceTransparency.rst), [`LanguageOverlays.rst`](Documentation/Architecture/LanguageOverlays.rst), [`InlineRelations.rst`](Documentation/Architecture/InlineRelations.rst)
- [`Documentation/Architecture/SecurityAudit.rst`](Documentation/Architecture/SecurityAudit.rst), [`ImplementationOverview.rst`](Documentation/Architecture/ImplementationOverview.rst)

Use **PHP ≥ 8.2** for Composer scripts (`composer test`, `composer phpstan`, …).

## Acknowledgements

We are grateful to **[hauptsacheNet](https://github.com/hauptsacheNet)** and to
**Marco Pfeiffer** for the original TYPO3 MCP Server: a strong, editor-first
foundation—workspace-safe, practical, and clear about where TYPO3 stays in
control.

**Thank you again** for publishing that work under an open license so others
could extend it responsibly.

This codebase **updates the original extension** and **adds features** beyond the
initial scope (CI, documentation, HTTP/OAuth hardening, tool ergonomics,
third-party tool adapters, and other improvements—see Git history). Parts of
that surface are **experimental** and intentionally subject to faster
iteration; pin versions and test upgrades like any integration-heavy
extension.

## License

GPL-2.0-or-later
