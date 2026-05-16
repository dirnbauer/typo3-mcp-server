# TYPO3 MCP Server — Technical Overview

Long-form companion to the [README](README.md) and the
[reStructuredText manual](Documentation/Index.rst). Focus is on design
rationale, architecture, and practical AI-workflow scenarios, not a full
tool reference (see
[`Documentation/Tools/Index.rst`](Documentation/Tools/Index.rst) for that).

---

## Table of contents

- [Project lineage](#project-lineage)
- [What problem this solves](#what-problem-this-solves)
- [Design principles](#design-principles)
- [MCP ergonomics (mcp-builder alignment)](#mcp-ergonomics-mcp-builder-alignment)
- [Real-world scenarios](#real-world-scenarios)
- [Implementation architecture](#implementation-architecture)
- [Known limitations](#known-limitations)
- [Skills vs MCP tools](#skills-vs-mcp-tools)
- [Best practices for editors](#best-practices-for-editors)

## Project lineage

This repository builds on the original TYPO3 MCP Server work by Marco
Pfeiffer and [hauptsacheNet](https://github.com/hauptsacheNet). That
foundation was strong in exactly the right places: TYPO3-native,
editor-first, workspace-safe, practical. The current v14-focused line keeps
that direction while tightening security, clarifying MCP ergonomics, and
expanding the tool surface.

## What problem this solves

TYPO3 backends are designed for people using forms, trees, and list modules.
LLMs need something different: structured tools, stable identifiers, and
machine-readable responses. This extension provides that layer while:

- routing every record-write through a TYPO3 workspace (no accidental live
  edits),
- respecting TCA, DataHandler, permissions, and language overlays,
- keeping editors in charge of publishing.

```
┌──────────────┐     OAuth/HTTP      ┌─────────────────┐
│  MCP Client  │ ◄──────────────────►│   MCP Server    │
│ Cursor/Claude│     stdin/stdout    │  (TYPO3 ext)    │
└──────────────┘                     └────────┬────────┘
                                              │
                                              ▼
                                     ┌─────────────────┐
                                     │  TYPO3 Core     │
                                     │  DataHandler    │
                                     │  Workspaces     │
                                     │  TCA / FAL      │
                                     └─────────────────┘
```

## Design principles

### 1. Workspace transparency

Every record-backed write stages in a TYPO3 workspace. Clients see stable
live-facing UIDs; internal version rows never leak into tool output. If the
user has no active workspace, the extension picks a writable one or creates
an "MCP" workspace automatically.

### 2. TCA-first

Tool schemas are derived from TYPO3 TCA, not from handwritten per-table
adapters. That means field labels, palettes, FlexForms, relations, record
types, and third-party extensions (e.g. `georgringer/news`) work without any
MCP-specific code.

### 3. Familiar patterns

MCP tools resemble what TYPO3 editors already know: page-tree,
list-module-style reads, schema-driven writes. Better TCA labels and
descriptions instantly improve the AI experience too.

### 4. User context

MCP calls run with the authenticated backend user's permissions and
workspace. The AI can only do what the user could do through the backend.

### 5. Safety by default

Writes go through DataHandler. Publishes and rollbacks default to dry-run.
File tools are sandboxed. Admin-only tools (`CreateSite`, `InstallExtension`)
are clearly gated.

A **capability manifest** (`Configuration/Capabilities.yaml`) declares
which subsystems each tool needs and gates outbound HTTP. Production
operators harden by removing subsystems (e.g. delete `database:write` to
make MCP read-only) or constraining `network.outbound` to specific
domains. See
[`Documentation/Architecture/CapabilityManifest.rst`](Documentation/Architecture/CapabilityManifest.rst).

A **DDEV / local-mode service** (`LocalModeService`) detects developer
environments and relaxes the workspace-staging, non-workspace-table,
outbound HTTP, and file-sandbox safety nets — never authentication, backend
user permissions, or per-tool subsystem checks. Production stays strict by
default, and strict sandbox mode can be forced via TYPO3 feature flag or User
TSconfig.

### 6. Language-awareness, conditional

Translation parameters are only exposed when the site actually has more than
one language configured. `WriteTable` accepts ISO codes (`de`, `fr`, ...).
Page overlays use TYPO3's `PageRepository`; workspace overlays use custom
transparency logic. See
[`Documentation/Architecture/LanguageOverlays.rst`](Documentation/Architecture/LanguageOverlays.rst).

### 7. Versioning and evolution

The extension targets TYPO3 v14 strictly. MCP tool contracts are treated as
**editor/product ergonomics, not as a legacy API**. Tool names, parameters,
and defaults may change within v14 when that improves LLM usability or TYPO3
correctness. Pin Composer versions and read release notes before upgrades.

## MCP ergonomics (mcp-builder alignment)

This extension is reviewed against the public
[mcp-builder skill](https://github.com/anthropics/skills/blob/main/skills/mcp-builder/SKILL.md).

**What matches the guide**

- **Schemas** — Each tool has a top-level `description`, JSON Schema
  `inputSchema` with per-field descriptions, and `required` where useful.
  Record-backed tools share an optional `workspace_id` (see
  `AbstractRecordTool`).
- **Annotations** — All four hints are set on every tool: `readOnlyHint`,
  `destructiveHint`, `idempotentHint`, `openWorldHint`.
- **Actionable errors** — `AbstractTool` + `ExceptionHandlerTrait` map
  exceptions to `CallToolResult` errors with editor-oriented text. Server
  internals stay in logs. Unknown tool names return a helpful
  `tools/list` hint instead of a JSON-RPC `-32603`.
- **Pagination** — `ReadTable` returns `total`, `count`, `limit`, `offset`,
  `nextOffset`, `hasMore`. `Search` enforces per-table limits and reports
  both totals and returned matches when a table was truncated. Tree tools
  warn about depth vs. site size.
- **Transport** — Remote HTTP + OAuth for hosted use; local stdio for
  trusted environments.

**Intentional differences**

- **Naming** — PascalCase (`ReadTable`, `WriteTable`) matches TYPO3 vocabulary
  instead of the `service_action` prefix style.
- **Structured output** — The PHP SDK does not currently expose
  `outputSchema` for structured tool results. Successful tools return **JSON
  inside `TextContent`**; client-side parsing is expected.

### Local stdio and the host OS boundary

`vendor/bin/typo3 mcp:server` runs as the OS user that launched it. TYPO3
constrains editorial rules; it does **not** isolate the PHP process from the
rest of the machine. If the MCP client exposes a shell — or you wrap startup
in `bash` — effective risk includes arbitrary host commands at the user's
privilege level. Treat that combination like interactive shell access: use
only on trusted local / non-production hosts; prefer dedicated OS accounts;
do not pair with production credentials.

## Real-world scenarios

### "Translate that page"

**Prompt:** *"Translate the /about-us page to German."*

```jsonc
GetPage    { "url": "/about-us" }
ReadTable  { "table": "tt_content", "pid": 123 }

WriteTable {
  "table": "tt_content",
  "action": "translate",
  "uid": 456,
  "data": {
    "sys_language_uid": "de",
    "header":   "Über uns",
    "bodytext": "[translated content]",
    "slug":     "/ueber-uns"
  }
}
// Response includes translationUid (live), targetLanguage ("de" — resolved
// per-site, not first-wins across all sites), siteIdentifier, slug, and
// hidden=false. Translations are visible by default — pass hidden: true to
// keep them in review.
```

### "Create a news article from this draft"

```jsonc
GetPageTree      { "startPage": 0, "depth": 3 }
GetTableSchema   { "table": "tx_news_domain_model_news" }
ReadTable        { "table": "tx_news_domain_model_category", "pid": 789 }

WriteTable {
  "table": "tx_news_domain_model_news",
  "action": "create",
  "pid": 789,
  "data": {
    "title":      "Annual Report 2026 Released",
    "teaser":     "Our latest financial results …",
    "bodytext":   "[full article content]",
    "categories": [12, 15],
    "datetime":   "2026-01-15T10:00:00"
  }
}
```

### "Fill in missing SEO descriptions"

```jsonc
ContentAudit { "startPage": 1, "depth": 4, "checks": ["missingMetaDescription"] }
// iterate results, then for each hit:
WriteTable   { "table": "pages", "action": "update", "uid": …, "data": { "description": "…" } }
```

### "Add alt text to all product images"

```jsonc
BrowseFiles       { "path": "products/" }
ReadFileMetadata  { "identifier": "products/widget-pro.jpg" }
WriteFile         {
  "path": "products/widget-pro.jpg",
  "metadata": {
    "alternative": "Widget Pro — ergonomic design in brushed aluminium",
    "title":       "Widget Pro product photo"
  }
}
```

### "Generate a small text asset"

```jsonc
WriteFile {
  "path": "notes/campaign-copy.md",
  "content": "# Contact block\n\nUse a concise call to action here.",
  "metadata": {
    "title":       "Campaign copy notes",
    "description": "Draft notes generated during MCP content editing"
  }
}
```

`WriteFile` intentionally excludes SVG from its default text-file allowlist
because SVG can carry inline scripts when served from `fileadmin/`. Operators
who need SVG generation must opt in through TYPO3's `SYS.textfile_ext` and
sanitize content before serving it.

### Workflow: draft → review → publish

```jsonc
ListWorkspaces    {}
WorkspaceReview   { "workspace_id": 3 }
PublishWorkspace  { "workspace_id": 3, "dryRun": true  }  // preview
PublishWorkspace  { "workspace_id": 3, "dryRun": false }  // execute
```

### Workflow: translations-only rollout

```jsonc
// Ship only the translation rows, leaving source-language drafts in place.
PublishWorkspace  { "workspace_id": 3, "onlyTranslations": true, "dryRun": true }
PublishWorkspace  { "workspace_id": 3, "onlyTranslations": true, "dryRun": false }
```

### Workflow: add a site configuration

```jsonc
// 1. Use an existing live root page prepared for the site. Site YAML is not
// workspace-versioned, so CreateSite must point at a page that TYPO3 can
// resolve outside a draft-only workspace row.
CreateSite { "action": "create",
             "identifier": "launch-2026",
             "rootPageId": 474,
             "base": "https://example.com/",
             "dependencies": ["webconsulting/desiderio-preset-corporate"] }
// No warning — the Site Set is attached, so the frontend will render.
// If no theme/site-package-like Site Set is installed and no sys_template
// exists, CreateSite writes a minimal setup.typoscript fallback in the active
// TYPO3 site configuration path.

// 2. Already created a site without a theme? Attach one in place.
CreateSite { "action": "update",
             "identifier": "launch-2026",
             "dependencies": ["webconsulting/desiderio-preset-corporate"] }
```

## Implementation architecture

The runtime is intentionally thin; TYPO3 does most of the work.

### Request path

1. **Remote client** authenticates via OAuth, then calls `/mcp`.
   `McpEndpoint` validates the token, bootstraps a backend user context, and
   hands the request to the SDK's `HttpServerRunner::handleRequest()`.
   Session state persists to `var/mcp_sessions/` via `FileSessionStore`.
2. **Local client** starts `vendor/bin/typo3 mcp:server` over stdio.
3. `McpServerFactory` builds the server and registers `tools/list` and
   `tools/call` handlers.
4. `ToolRegistry` collects every DI service tagged `mcp.tool`. Native
   `ToolInterface` implementations are used directly; other objects exposing
   `getName()`/`execute()` are wrapped by `CompatibleToolAdapter`.
5. Tools call shared services for workspace, TCA, language, sandbox, URL,
   and OAuth logic.
6. TYPO3 core APIs (`DataHandler`, `PageRepository`, `TcaSchemaFactory`,
   FAL) perform the actual CMS work.

### Shared services

| Service | Responsibility |
|---|---|
| `WorkspaceContextService` | Pick/keep/create the workspace; switch context safely |
| `TableAccessService` | Central gate for table/field access + TSconfig visibility |
| `LanguageService` | Map ISO ↔ TYPO3 language UIDs; hide params when monolingual |
| `McpFileSandboxService` | Enforce sandbox root; compute workspace upload folders |
| `SiteInformationService` | Resolve site URLs, domains, and base paths |
| `FileReferenceAttachmentService` | Workspace-safe `sys_file_reference` creation via DataHandler |
| `OAuthService` | Auth codes, PKCE, SHA-256 token hashing, revocation |
| `SelectItemResolver` | FormEngine-style select resolution (itemsProcFunc, TSconfig) |
| `LocalModeService` | Detect DDEV / Development context; gate live writes + unrestricted file access |
| `CapabilityManifestService` | Read `Capabilities.yaml`; refuse undeclared tools and out-of-policy outbound HTTP |

### HTTP transport hardening

- **Redacted logs** (`McpHttpLogRedactor`) — sensitive headers and query
  tokens are not logged.
- **Query-token auth disabled by default** — `?token=…` on `/mcp` requires
  explicit opt-in via `allowMcpTokenInQueryString`.
- **Minimal auth diagnostic** — `?test=auth` is disabled by default. When
  enabled via `enableMcpAuthHeaderDiagnostic`, it reports only whether the
  `Authorization` header arrived and does not reveal server fingerprint data.

See [`Documentation/Architecture/SecurityAudit.rst`](Documentation/Architecture/SecurityAudit.rst)
for the full audit snapshot.

### Testing strategy

- **Unit tests** for focused pure logic (OAuth hashing, sandbox paths).
- **Functional TYPO3 tests** for workspaces, language overlays, TCA-driven
  tool behavior, file sandbox, non-admin permissions, and extension
  compatibility.
- **LLM tests** (opt-in, needs `OPENROUTER_API_KEY`) that exercise tool
  ergonomics in multi-step conversations with real models.

## Known limitations

- **Physical files are not workspace-versioned.** The sandbox and optional
  workspace subfolders reduce risk, but `WriteFile` / `UploadFile` changes
  are immediate across all workspaces. Only file *references* are versioned.
- **`PublishWorkspace` is irreversible.** It defaults to dry-run; always
  review before executing.
- **`BulkWrite` is capped at 50 operations** per call. Split larger batches
  into multiple calls.
- **Redirects (`sys_redirect`) are outside TYPO3 workspaces.** MCP lists
  them read-only; create/delete through MCP is intentionally disabled.
- **Context-window limits.** Operations like "translate the entire page"
  may hit client model limits; process in chunks for very large pages.

## Skills vs MCP tools

Not everything should be an MCP tool. Runtime data operations (read/write
records, files, workspaces) are MCP tools. Domain knowledge and workflow
templates live as **AI skills** that drive the same tools.

| MCP tools (runtime) | Skills (knowledge) |
|---|---|
| Read/write records, files, configurations | Content modeling (Content Blocks, TCA patterns) |
| Navigate page tree, search, audit content | Frontend templates (shadcn, design systems) |
| Manage workspaces, publish, rollback | Form creation (Powermail) |
| Install extensions, run safe CLI | Legal pages (Impressum, Datenschutz) |
| Redirects, system log, site config | SEO audit, accessibility audit |

**Example workflow — "Add a contact form to the about page"**

1. LLM loads the `typo3-powermail` skill (form structure, best practices).
2. `GetPage` → find the about page.
3. `GetTableSchema` → understand
   `tx_powermail_domain_model_form` fields.
4. `WriteTable` → create the form, page, and fields.
5. `WriteTable` → add a `list` content element with the form plugin.

This separation keeps MCP tools generic and reusable; skills evolve
independently.

## Best practices for editors

**Use full URLs.** `https://example.com/about-us` resolves cleanly through
`GetPage` across languages and domains.

**Be specific about scope.** "Update meta descriptions under /products"
beats "update all pages". Helps avoid context-window issues and focuses the
assistant.

**Review before publishing.** Everything lands in a workspace first — the
TYPO3 backend (`Workspaces` module) is still the final authority.

**Provide context.** "We're a law firm — keep the tone professional" or
"summer campaign, make it cheerful" dramatically improves output quality.

**Work incrementally.** Analyze → change → review in small steps. For big
projects, use multiple chat sessions in parallel; each maintains its own
context.

**Use controlled environments first.** `CreateSite`, `InstallExtension`,
`SafeCli`, and `PublishWorkspace` are powerful. Try them on staging first
and narrow the tool surface you expose to production clients.
