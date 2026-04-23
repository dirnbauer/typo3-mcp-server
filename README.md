# TYPO3 MCP Server

[Model Context Protocol](https://modelcontextprotocol.io/) server for
**TYPO3 v14**: give LLMs and MCP clients structured, workspace-safe tools for
pages, records, TCA/FlexForm schemas, file handling, and workflow — without
scraping the backend UI.

**Built on** the editor-first design of
[hauptsacheNet/typo3-mcp-server](https://github.com/hauptsacheNet/typo3-mcp-server)
by [Marco Pfeiffer](https://github.com/hauptsacheNet). This fork maintains,
updates, and extends that upstream for TYPO3 v14 with additional tools and
integrations.

---

## In 60 seconds

An MCP client (Cursor, Claude Desktop, n8n, Manus, ...) connects over OAuth
to `https://your-site/mcp` and can then:

- browse the page tree and read records with TCA context,
- **safely** edit content (every write lands in a TYPO3 workspace first),
- attach images, translate records, copy content, publish or roll back
  workspace changes,
- import text/Markdown/HTML and propose or create content elements,
- audit content for missing metadata or alt text.

TYPO3 stays in control of permissions, TCA, DataHandler, workspaces, and
language overlays. The MCP client sees a clean, machine-readable surface.

**Status:** experimental surface — tool names, parameters, and defaults may
change between releases. Pin Composer versions. Validate in staging before
relying on this in production.

## Table of contents

- [Quick start](#quick-start)
- [Example session](#example-session)
- [Capabilities at a glance](#capabilities-at-a-glance)
- [Authentication and clients](#authentication-and-clients)
- [Configuration](#configuration)
- [Development](#development)
- [Documentation](#documentation)
- [Acknowledgements](#acknowledgements)

## Quick start

### 1. Install

```bash
composer require hn/typo3-mcp-server
vendor/bin/typo3 extension:activate mcp_server
```

**Requirements**

- TYPO3 `^14.0`
- PHP `>=8.2`
- `typo3/cms-workspaces`

### 2. Open the backend module

In the TYPO3 backend, go to **User → MCP Server**. The module shows:

- your MCP endpoint URL (`https://your-site/mcp`),
- one-click setup instructions for popular clients,
- a live health check for OAuth discovery endpoints,
- token management (create and revoke personal-access tokens).

### 3. Connect a client

Pick the client you use — the backend module renders ready-to-copy
configuration for each:

| Client | Transport | Setup |
|---|---|---|
| **Cursor** | Remote HTTP + OAuth | One-click install button in the module |
| **Claude Desktop** | Via `mcp-remote` proxy | Paste JSON config from the module |
| **n8n** | Remote HTTP + OAuth | Paste endpoint URL into the MCP Client node |
| **Manus** | Remote HTTP + OAuth | Paste endpoint URL |
| **MCP Inspector** | Remote HTTP | `npx @modelcontextprotocol/inspector …` |
| **Local / trusted host** | stdio | `vendor/bin/typo3 mcp:server` |

The first request triggers the OAuth flow: TYPO3 logs you in with your
existing backend credentials and authorizes the client.

## Example session

Here is what a typical "add a news article on page 42" conversation looks like
from the tool-call perspective. The MCP client drives these calls; you only
write a natural-language prompt.

```text
USER: Add a news article "Spring Sale" on the News page with a short teaser.
```

```jsonc
// 1. Discover context
GetPageTree  { "startPage": 1, "depth": 3 }
ListTables   {}
GetTableSchema { "table": "tx_news_domain_model_news" }

// 2. Write into a workspace (workspace is auto-selected/created)
WriteTable {
  "table": "tx_news_domain_model_news",
  "action": "create",
  "pid": 42,
  "data": {
    "title":    "Spring Sale",
    "teaser":   "30% off selected items for two weeks.",
    "datetime": "2026-04-20"
  }
}
// → { "action": "create", "table": "tx_news_domain_model_news", "uid": 1234, "pid": 42 }

// 3. Review and publish
WorkspaceReview   { "workspaceId": 3 }
PublishWorkspace  { "workspaceId": 3, "dryRun": false }
```

The live UID `1234` is stable across workspace and live — MCP clients never
see the internal workspace version ID.

## Capabilities at a glance

The extension ships **35 MCP tools** across six groups. For the authoritative
list with parameters, see
[`Documentation/Tools/Index.rst`](Documentation/Tools/Index.rst).

- **Navigation & search** — `GetPageTree`, `GetPage` (accepts `uid`, `pageId`,
  or `url`), `ListTables`, `Search` (accepts `query` or `terms`), `SearchMedia`
- **Schema introspection** — `GetTableSchema`, `GetFlexFormSchema`
- **Read & write records** — `ReadTable` (structured `filters` with
  `sys_language_uid` ISO codes and boolean `hidden`), `WriteTable`,
  `BulkWrite`, `CopyContent`, `AttachImage`
- **Content import** — `ImportContent`, `ImportFromUrl`
- **Workspace workflow** — `ListWorkspaces`, `WorkspaceReview`,
  `PublishWorkspace` (supports `tables` list and `onlyTranslations`),
  `RollbackWorkspace`
- **Files (sandboxed)** — `BrowseFolder`, `BrowseFiles`, `WriteFile`,
  `UploadFile`, `UploadFileFromUrl`, `ReadFileMetadata`, `SearchFile`,
  `ListStorages`
- **Diagnostics** — `ContentAudit`, `GetSystemLog`, `ManageRedirects`
- **Admin / operations** — `CreateSite` (supports `create`/`update` with
  `dependencies`, `sets`, `settings`, and arbitrary `config` merging; warns
  when no Site Set or TypoScript template is attached), `InstallExtension`,
  `SafeCli`
- **Optional: x402 monetization** — `ListPaidContent`, `GetPaidContent`,
  `GetPaymentStats` (when `typo3-x402-paywall` is installed)

### Building a site from scratch

`CreateSite` takes a rendering definition so the frontend renders out of the box:

```jsonc
CreateSite {
  "action": "create",
  "identifier": "launch-2026",
  "rootPageId": 474,
  "base": "https://example.com/",
  "dependencies": ["webconsulting/desiderio-preset-corporate"],
  "settings": { "theme": { "accent": "violet" } }
}
```

Missed the theme on creation? `action: "update"` merges top-level keys into an
existing site config without touching unrelated entries:

```jsonc
CreateSite {
  "action": "update",
  "identifier": "launch-2026",
  "dependencies": ["webconsulting/desiderio-preset-corporate"]
}
```

### Translating a page in one call

Translations are created **visible by default** (`hidden=0`). Pass
`hidden: true` to keep them in review. Inline children are auto-localized by
TYPO3 unless you opt out with `translateChildren: false` — useful when you
plan to translate child records yourself. Slug, siteIdentifier, and the target
ISO code are returned in the response.

```jsonc
WriteTable {
  "action": "translate",
  "table": "pages",
  "uid": 474,
  "hidden": false,
  "data": { "sys_language_uid": "hu", "title": "Esemény", "slug": "/esemeny" }
}
// → { "translationUid": 1234, "targetLanguage": "hu",
//     "siteIdentifier": "launch-2026", "slug": "/esemeny", "hidden": false }
```

If the follow-up field update fails, the tool rolls back the freshly created
translation row so you are never left with an orphan source-language record.

### Core guarantees

- **Workspace transparency** — every record-backed write stages in a TYPO3
  workspace. MCP clients see stable live UIDs; the workspace context is
  selected or created automatically.
- **TCA-first** — tool schemas come from TCA, not from handwritten adapters.
  Field labels, permissions, palettes, FlexForms, and third-party extensions
  such as `georgringer/news` work out of the box.
- **Language-aware** — translation parameters only appear when the site has
  more than one language. ISO codes like `de`, `fr` are accepted.
- **File sandbox** — file tools are restricted to `fileadmin/mcp/` by
  default. Physical files are **not** workspace-versioned; only file
  references are. Uploads can optionally be routed into workspace-specific
  subfolders.
- **Dry-run by default** — `PublishWorkspace` and `RollbackWorkspace`
  default to preview mode. Destructive actions need an explicit second step.

## Authentication and clients

Two connection models:

- **Remote HTTP** at `/mcp`, protected by OAuth 2.1 + PKCE.
  Recommended for everything non-local: Cursor, Claude Desktop, n8n, Manus,
  MCP Inspector.
- **Local stdio** via `vendor/bin/typo3 mcp:server`.
  Runs as your OS user; TYPO3 gates CMS operations but does not contain the
  host. Only use stdio with trusted local clients.

The **User → MCP Server** backend module handles token creation, per-client
instructions, and endpoint health checks.

Remote MCP requests remain stateless from the editor's point of view. The
extension creates an anonymous in-memory backend user session for the lifetime
of the request so TYPO3 internals that expect session state during
``DataHandler`` writes keep working, without creating a persistent backend
login.

## Configuration

All settings live in **Extension Configuration → `mcp_server`**.

| Key | Default | Purpose |
|---|---|---|
| `fileSandboxRoot` | `1:/mcp/` | FAL folder root where file tools operate |
| `workspaceUploadSubfolders` | `0` | Route uploads into workspace-specific folders |
| `allowMcpTokenInQueryString` | `0` | Allow `?token=…` on `/mcp` (legacy clients only, logging risk) |
| `enableMcpAuthHeaderDiagnostic` | `1` | Enable minimal `?test=auth` diagnostic on `/mcp` |

See [`Documentation/Configuration/Index.rst`](Documentation/Configuration/Index.rst)
for details and security recommendations.

## Development

```bash
composer test            # unit + functional tests
composer test:llm        # LLM-assisted ergonomics tests (needs OPENROUTER_API_KEY)
composer phpstan         # static analysis
composer php-cs-fixer:fix
composer docs:check      # RST render check (uses Docker)
```

### Syncing with upstream

This fork tracks upstream at
[hauptsacheNet/typo3-mcp-server](https://github.com/hauptsacheNet/typo3-mcp-server)
(TYPO3 v13). To pull in new upstream changes:

```bash
git fetch upstream
git merge upstream/main
```

Resolve any conflicts by keeping TYPO3 v14 patterns (`final class`,
constructor DI, `getToolSchema()`). Then verify:

```bash
composer php-cs-fixer:fix && composer phpstan && composer test
```

### Repository layout

```
Classes/
  MCP/         MCP server, tool registry, tool implementations
  Service/    workspace, TCA, language, file sandbox, OAuth
  Http/       /mcp endpoint + OAuth/discovery endpoints
  Controller/ backend module controller
  Command/    CLI commands (mcp:server, mcp:test, mcp:oauth)
Configuration/ DI, backend module, middleware
Documentation/ reStructuredText manual (published source)
Resources/     templates, CSS/JS, XLIFF labels (en + de)
Tests/         unit, functional, LLM, architecture
```

## Documentation

The canonical manual lives under `Documentation/` in reStructuredText.
Suggested reading order:

| Topic | Entry point |
|---|---|
| Overview and safety model | [`Introduction/Index.rst`](Documentation/Introduction/Index.rst) |
| What the tools promise | [`Introduction/IntendedBehavior.rst`](Documentation/Introduction/IntendedBehavior.rst) |
| Install and activate | [`Installation/Index.rst`](Documentation/Installation/Index.rst) |
| Module, OAuth, sandbox | [`Configuration/Index.rst`](Documentation/Configuration/Index.rst) |
| Full MCP tool reference | [`Tools/Index.rst`](Documentation/Tools/Index.rst) |
| Architecture deep-dives | [`Architecture/Index.rst`](Documentation/Architecture/Index.rst) |
| Troubleshooting | [`Troubleshooting/Index.rst`](Documentation/Troubleshooting/Index.rst) |

For long-form design rationale and real-world scenarios see
[`TECHNICAL_OVERVIEW.md`](TECHNICAL_OVERVIEW.md).

## Acknowledgements

Thank you to [hauptsacheNet](https://github.com/hauptsacheNet) and
Marco Pfeiffer for open-sourcing the original TYPO3 MCP Server: a strong,
editor-first, workspace-safe foundation that this project builds on.

## License

GPL-2.0-or-later
