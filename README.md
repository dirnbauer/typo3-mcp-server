# TYPO3 MCP Server

[Model Context Protocol](https://modelcontextprotocol.io/) server for
**TYPO3 v14**: structured, workspace-safe tools for pages, records,
TCA/FlexForm schemas, file handling, and editorial workflow — over MCP for
LLM clients (Cursor, Claude Desktop, …) and over the TYPO3 CLI for shell
scripts and CI.

**Built on** the editor-first design of
[hauptsacheNet/typo3-mcp-server](https://github.com/hauptsacheNet/typo3-mcp-server)
by [Marco Pfeiffer](https://github.com/hauptsacheNet). This fork tracks
upstream and adds: capability-manifest enforcement, DDEV-aware local mode,
preview/render tools for the editor verification loop, a complete CLI
mirror of the MCP surface, and ~25 fork-only tools (file sandbox, content
audit, translate ergonomics, …).

---

## Continuously Tested With Real LLMs

Every push to `main` runs a benchmark that has the latest models from **Anthropic, OpenAI, Mistral, and Google** actually use this MCP to perform real TYPO3 tasks. That's how we stay vendor-independent and prove the tool descriptions convey what they claim across very different prompting styles — your AI assistant of choice should just work, not only ours. Click any badge for the full run-by-run history.

[
![haiku-4.5](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fscript.google.com%2Fmacros%2Fs%2FAKfycbwyS4NavPMDQWbQQYCh3uKA4zJ5C8sxggxTZQQPdgjXOZ7Vt4BpUd5mzWdsWMqjzniI%2Fexec&query=%24.percentages%5B%22haiku-4.5%22%5D&suffix=%25&label=haiku-4.5)
![gpt-5.4-mini](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fscript.google.com%2Fmacros%2Fs%2FAKfycbwyS4NavPMDQWbQQYCh3uKA4zJ5C8sxggxTZQQPdgjXOZ7Vt4BpUd5mzWdsWMqjzniI%2Fexec&query=%24.percentages%5B%22gpt-5.4-mini%22%5D&suffix=%25&label=gpt-5.4-mini)
![gpt-oss-120b](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fscript.google.com%2Fmacros%2Fs%2FAKfycbwyS4NavPMDQWbQQYCh3uKA4zJ5C8sxggxTZQQPdgjXOZ7Vt4BpUd5mzWdsWMqjzniI%2Fexec&query=%24.percentages%5B%22gpt-oss-120b%22%5D&suffix=%25&label=gpt-oss-120b)
![mistral-large-2512](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fscript.google.com%2Fmacros%2Fs%2FAKfycbwyS4NavPMDQWbQQYCh3uKA4zJ5C8sxggxTZQQPdgjXOZ7Vt4BpUd5mzWdsWMqjzniI%2Fexec&query=%24.percentages%5B%22mistral-large-2512%22%5D&suffix=%25&label=mistral-large-2512)
![gemini-3-flash](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fscript.google.com%2Fmacros%2Fs%2FAKfycbwyS4NavPMDQWbQQYCh3uKA4zJ5C8sxggxTZQQPdgjXOZ7Vt4BpUd5mzWdsWMqjzniI%2Fexec&query=%24.percentages%5B%22gemini-3-flash%22%5D&suffix=%25&label=gemini-3-flash)
](https://docs.google.com/spreadsheets/d/18jL34ymMaUfoCtL32FauPu3n0cTbBTLKuVO7dmGSAS4/edit?usp=sharing)

## In 60 seconds

An MCP client (Cursor, Claude Desktop, n8n, Manus, MCP Inspector) connects
over OAuth to `https://your-site/mcp` and can:

- Browse the page tree and read records with TCA context.
- **Safely** edit content — every write lands in a TYPO3 workspace first.
- Attach images, translate records, copy content, publish, or roll back.
- Render a workspace preview URL or fetch the rendered HTML to verify a
  change without leaving the chat.
- Import text/Markdown/HTML and propose or create content elements.
- Audit content for missing metadata or alt text.

Every tool is also a Symfony console command (`vendor/bin/typo3 mcp:<tool>`),
with `--json`, `--plain`, and `--no-ansi` output modes for shell scripting.

TYPO3 stays in control of permissions, TCA, DataHandler, workspaces, and
language overlays. The MCP client sees a clean, machine-readable surface.

**Status:** experimental surface — tool names, parameters, and defaults may
change between releases. Pin Composer versions. Validate in staging before
relying on this in production.

## Table of contents

- [Quick start](#quick-start)
- [Example session](#example-session)
- [Capabilities at a glance](#capabilities-at-a-glance)
- [CLI: every tool, every shell](#cli-every-tool-every-shell)
- [Capability manifest (security model)](#capability-manifest-security-model)
- [DDEV / local-development mode](#ddev--local-development-mode)
- [Authentication and clients](#authentication-and-clients)
- [Configuration](#configuration)
- [Development](#development)
- [Documentation](#documentation)
- [Acknowledgements](#acknowledgements)

## Quick start

While there are a lot of automated tests, TYPO3 instances are widely
different and language models are also widely different. Feel free to
[create issues here on GitHub](https://github.com/hauptsacheNet/typo3-mcp-server/issues)
or [share experiences in the typo3-core-ai channel](https://typo3.slack.com/archives/C091M0M7BL6).

### 1. Install

```bash
composer require hn/typo3-mcp-server
vendor/bin/typo3 extension:activate mcp_server
```

**Requirements**

- TYPO3 `^14.0` (v14.3 LTS — no v12/v13 fallback paths in this fork)
- PHP `8.2 – 8.5` (CI matrix runs all four)
- `typo3/cms-workspaces`

### 2. Open the backend module

In the TYPO3 backend, go to **User → MCP Server**. The module shows:

- Your MCP endpoint URL (`https://your-site/mcp`).
- One-click setup for Cursor and ready-to-paste config for Claude Desktop,
  n8n, Manus.
- A live health check for OAuth discovery endpoints.
- Token management (create/revoke personal-access tokens).

### 3. Connect a client

| Client | Transport | Setup |
|---|---|---|
| **Cursor** | Remote HTTP + OAuth | One-click install button (see [`Documentation/Testing/CursorTesting.md`](Documentation/Testing/CursorTesting.md)) |
| **Claude Desktop** | Via `mcp-remote` proxy | Paste JSON config from the module |
| **n8n** | Remote HTTP + OAuth | Paste endpoint URL into the MCP Client node |
| **Manus** | Remote HTTP + OAuth | Paste endpoint URL |
| **MCP Inspector** | Remote HTTP | `npx @modelcontextprotocol/inspector …` |
| **Local / trusted host** | stdio | `vendor/bin/typo3 mcp:server` |
| **Shell / CI / scripts** | TYPO3 CLI | `vendor/bin/typo3 mcp:<tool> [--json]` |

The first remote request triggers the OAuth flow: TYPO3 logs you in with
your existing backend credentials and authorizes the client.

## Example session

What an "add a news article on page 42" conversation looks like at the
tool-call level. The MCP client drives these calls; you only write a
natural-language prompt.

```text
USER: Add a news article "Spring Sale" on the News page with a short teaser.
```

```jsonc
// 1. Discover context
GetCapabilities {}                                 // know what's allowed
GetPageTree     { "startPage": 1, "depth": 3 }
ListTables      {}
GetTableSchema  { "table": "tx_news_domain_model_news" }

// 2. Write into a workspace (auto-selected/created)
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

// 3. Verify before publishing
GetPreviewUrl { "table": "tx_news_domain_model_news", "uid": 1234 }
RenderRecord  { "pageId": 42, "mode": "text", "maxLength": 5000 }

// 4. Review and publish
WorkspaceReview  {}
PublishWorkspace { "dryRun": false }
```

The live UID `1234` is stable across workspace and live — MCP clients never
see the internal workspace version ID.

## Capabilities at a glance

The extension ships **~40 MCP tools** across these groups. For the
authoritative list with parameters, see
[`Documentation/Tools/Index.rst`](Documentation/Tools/Index.rst). The same
list is also returned by the `GetCapabilities` tool, gated by
[`Configuration/Capabilities.yaml`](Configuration/Capabilities.yaml).

- **Discovery & schema** — `GetCapabilities`, `ListTables`, `GetTableSchema`,
  `GetFlexFormSchema`
- **Navigation & search** — `GetPageTree`, `GetPage` (`uid`, `pageId`, or
  `url`), `Search` (accepts `query` or `terms`)
- **Read & write records** — `ReadTable` (structured `filters` with
  `sys_language_uid` ISO codes and boolean `hidden`), `WriteTable`,
  `BulkWrite`, `CopyContent`, `AttachImage`
- **Verification (new)** — `GetPreviewUrl` (signed workspace preview link),
  `RenderRecord` (fetches the FE HTML so the LLM can see the result)
- **Content import** — `ImportContent`, `ImportFromUrl`
- **Workspace workflow** — `ListWorkspaces`, `WorkspaceReview`,
  `PublishWorkspace` (supports `tables` list and `onlyTranslations`),
  `RollbackWorkspace`
- **Files (sandboxed)** — `BrowseFolder`, `BrowseFiles`, `WriteFile`,
  `UploadFile`, `UploadFileFromUrl`, `ReadFileMetadata`, `SearchFile`,
  `SearchMedia`, `ListStorages`
- **Diagnostics** — `ContentAudit`, `GetSystemLog`, `ManageRedirects`
- **Admin / operations** — `CreateSite`, `SiteSet`, `InstallExtension`, `SafeCli`
- **Optional: x402 monetization** — `ListPaidContent`, `GetPaidContent`,
  `GetPaymentStats` (when `typo3-x402-paywall` is installed)

### Building a site from scratch

`CreateSite` accepts a rendering definition so the frontend renders out of
the box:

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

Need to add the theme later? `action: "update"` merges top-level keys into
an existing site config without touching unrelated entries.

### Translating a page in one call

Translations are visible by default (`hidden=0`). Pass `hidden: true` to
keep them in review. Inline children are auto-localized by TYPO3 unless you
opt out with `translateChildren: false`.

```jsonc
WriteTable {
  "action": "translate",
  "table": "pages",
  "uid": 474,
  "data": { "sys_language_uid": "hu", "title": "Esemény", "slug": "/esemeny" }
}
// → { "translationUid": 1234, "targetLanguage": "hu",
//     "siteIdentifier": "launch-2026", "slug": "/esemeny", "hidden": false }
```

### Core guarantees

- **Workspace transparency** — every record-backed write stages in a TYPO3
  workspace. MCP clients see stable live UIDs; the workspace context is
  selected or created automatically.
- **TCA-first** — tool schemas come from TCA, not from handwritten adapters.
  Field labels, permissions, palettes, FlexForms, and third-party
  extensions like `georgringer/news` work out of the box.
- **Language-aware** — translation parameters only appear when the site has
  more than one language. ISO codes (`de`, `fr`) are accepted.
- **File sandbox** — file tools are restricted to `fileadmin/mcp/` by
  default. Physical files are not workspace-versioned; only file references
  are.
- **Dry-run by default** — `PublishWorkspace` and `RollbackWorkspace` show
  what would happen unless `dryRun: false`.
- **Capability-manifest enforcement** — every tool declares its required
  subsystems (`database:write`, `file:write`, `render:frontend`, …).
  Removing a subsystem from `Configuration/Capabilities.yaml` disables every
  tool that needs it. Outbound HTTP defaults to `self` only.

## CLI: every tool, every shell

Every MCP tool is also a TYPO3 CLI command, so the same surface is
available to shell scripts, CI pipelines, and `ddev exec`. Three output
modes:

```sh
# Pretty (humans):
ddev exec ./vendor/bin/typo3 mcp:read-table --table tt_content --pid 1

# Plain text only (logs, redirects):
ddev exec ./vendor/bin/typo3 mcp:read-table --table tt_content --pid 1 --plain
ddev exec ./vendor/bin/typo3 mcp:read-table --table tt_content --pid 1 --no-ansi

# JSON envelope `{ok, result}` (jq, agents, CI):
ddev exec ./vendor/bin/typo3 mcp:read-table --table tt_content --pid 1 --json | jq '.result'
```

Every tool also accepts `--param key=value` (repeatable) and
`--params <json>` for arbitrary input. Use `--param data=@payload.json` to
read JSON from a file (constrained to the project root for safety).

```sh
ddev exec ./vendor/bin/typo3 mcp:tool:list             # discover tools
ddev exec ./vendor/bin/typo3 mcp:tool:list --schema=ReadTable
ddev exec ./vendor/bin/typo3 mcp:tool ReadTable --param table=pages --json
ddev exec ./vendor/bin/typo3 mcp:get-capabilities --json
```

The shipped `mcp:<tool>` shortcuts cover the most-used upstream tools
(`read-table`, `write-table`, `get-page`, `get-page-tree`, `search`,
`list-tables`, `get-table-schema`, `list-workspaces`, `publish-workspace`,
`render-record`, `get-preview-url`, `get-capabilities`). For everything
else, `mcp:tool <Name>` works against any registered tool. Adding a new
shortcut is a 15-line subclass of `AbstractMcpToolCommand` — see the
[`typo3-mcp-cli` skill](#documentation) for the recipe.

## Capability manifest (security model)

The MCP server ships [`Configuration/Capabilities.yaml`](Configuration/Capabilities.yaml)
that declares which subsystems each tool needs. Inspired by
[Capability Manifests for TYPO3 extensions](https://www.webconsulting.at/blog/typo3-extension-security-emdash-capability-manifests).

```yaml
capabilities:
  subsystems:
    - database:read
    - database:write
    - file:write          # delete this line → file-write tools all stop working
    # …
  network:
    outbound:
      - self              # only this site by default
      # - '*'             # uncomment to allow public web (still blocks private IPs)
  tools:
    WriteTable:        [database:write]
    UploadFileFromUrl: [file:write]
    RenderRecord:      [database:read, render:frontend]
    # …
```

**Enforcement points:**

- `AbstractTool::execute()` — refuses to run a tool whose required
  subsystems (or any of their `requires:` prerequisites) are not declared.
  Removing `database:write` cascades into disabling every
  `file:write`/`workspace:write`/`site:write` tool too.
- `UploadFileFromUrlTool` and `RenderRecordTool` — refuse outbound requests
  to hosts not in `network.outbound` (the IP-range SSRF check still applies
  on top).

To loosen the policy: edit the YAML or unset `enforceCapabilityManifest` in
extension settings. To check what's active right now:

```sh
ddev exec ./vendor/bin/typo3 mcp:get-capabilities --json
```

## DDEV / local-development mode

By default, MCP enforces two safety nets that are inconvenient on a
developer's laptop:

- All record writes are staged in a workspace (no live edits).
- File operations are jailed to `fileadmin/mcp/`.

When the server detects a DDEV environment (via `IS_DDEV_PROJECT`,
`DDEV_PROJECT`, `DDEV_HOSTNAME`, or `DDEV_TLD`) **or** when TYPO3 runs in
the `Development/...` application context, the safety nets relax:

- `WriteTable` accepts `workspace_id: 0` (live writes).
- `BrowseFiles`, `WriteFile`, `UploadFile` accept any storage / path.
- File-sandbox boundary checks are bypassed (path-traversal protection
  still applies).
- The capability manifest's `network.outbound: [self]` gate is bypassed —
  `UploadFileFromUrl` and `RenderRecord` accept any remote host. The
  SSRF private-IP filter is also lifted so DDEV's `*.ddev.site`
  (which resolves to `127.0.0.1`) and local NAS / staging hosts work
  out of the box.

Override via extension setting `localUnsafeMode`:

| Value  | Behavior                                                                     |
|--------|------------------------------------------------------------------------------|
| `auto` | (default) on if DDEV or Development context detected, off otherwise          |
| `on`   | always relaxed — only set in trusted local environments                      |
| `off`  | always strict — production-safe                                              |

The same mode can be set per backend user or group via User TSconfig, which
allows TYPO3 conditions:

```typoscript
[applicationContext == "Development/DDEV"]
options.mcpServer.localUnsafeMode = on
[else]
options.mcpServer.localUnsafeMode = off
[end]
```

To force the production safety nets even in DDEV, set either the TYPO3 feature
flag `$GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['mcpServer.strictSandbox']`
or User TSconfig:

```typoscript
options.mcpServer.strictSandbox = 1
```

Strict sandbox mode means file tools stay inside the configured MCP file
sandbox and record writes stay in TYPO3 workspaces. Local mode additionally
removes the workspace-capable table requirement so local-only tools can write
non-workspace tables with the current backend user's normal permissions.

The `Configuration/Capabilities.yaml` and OAuth/permission checks remain
enforced — local mode only relaxes the workspace-staging and file-sandbox
nets, never authentication or capability-policy.

## Authentication and clients

Two connection models:

- **Remote HTTP** at `/mcp`, protected by OAuth 2.1 + PKCE.
  Recommended for everything non-local: Cursor, Claude Desktop, n8n,
  Manus, MCP Inspector.
- **Local stdio** via `vendor/bin/typo3 mcp:server`.
  Runs as your OS user; TYPO3 gates CMS operations but does not contain
  the host. Use stdio with trusted local clients only.

The **User → MCP Server** backend module handles token creation, per-client
instructions, and endpoint health checks.

Remote MCP requests stay stateless from the editor's point of view. The
extension creates an anonymous in-memory backend user session for the
lifetime of the request so TYPO3 internals that expect session state during
`DataHandler` writes keep working, without creating a persistent backend
login.

## Configuration

All settings live in **Extension Configuration → `mcp_server`**.

| Key                            | Default | Purpose                                                                            |
|--------------------------------|---------|------------------------------------------------------------------------------------|
| `additionalReadOnlyTables`     | `sys_file` | Comma-separated non-workspace tables exposed for reads only                     |
| `fileSandboxRoot`              | `1:/mcp/` | FAL folder root where file tools operate                                         |
| `workspaceUploadSubfolders`    | `1`     | Route uploads into workspace-specific folders                                      |
| `allowMcpTokenInQueryString`   | `0`     | Allow `?token=…` on `/mcp` (legacy clients only, logging risk)                     |
| `enableMcpAuthHeaderDiagnostic`| `0`     | Enable minimal `?test=auth` diagnostic on `/mcp` (off-by-default since 2026-05)    |
| `localUnsafeMode`              | `auto`  | DDEV/Development → live writes + unrestricted file access. `on`/`off`/`auto`; can be overridden by User TSconfig. |
| `enforceCapabilityManifest`    | `1`     | Reject tools whose required subsystems aren't declared in `Capabilities.yaml`      |

See [`Documentation/Configuration/Index.rst`](Documentation/Configuration/Index.rst)
for details and security recommendations.

## Development

```bash
ddev exec composer test            # 42 unit + 817 functional, paratest -p 4
ddev exec composer test:llm        # LLM-assisted ergonomics tests (needs OPENROUTER_API_KEY)
ddev exec composer phpstan         # PHPStan level max + saschaegerer/phpstan-typo3
                                   # + phpstan-strict-rules + phpstan-deprecation-rules
ddev exec composer php-cs-fixer:fix
ddev exec composer rector          # PHP migrations dry-run
ddev exec composer fractor         # non-PHP (FlexForm/TypoScript/Fluid) dry-run
ddev exec composer docs:check      # RST render check (uses Docker)
```

CI matrix runs **PHP 8.2 / 8.3 / 8.4 / 8.5 × TYPO3 ^14.0** on every push.

E2E (Playwright) — spins up MySQL, TYPO3, Playwright in Docker:

```bash
Build/runTests.sh -s e2e
Build/runTests.sh -s e2e --no-docker      # host PHP + SQLite + local Playwright
TYPO3_BASE_URL=https://my.ddev.site Build/runTests.sh -s e2e
Build/runTests.sh -h                      # all options
```

### Syncing with upstream

This fork tracks
[hauptsacheNet/typo3-mcp-server](https://github.com/hauptsacheNet/typo3-mcp-server).
To pull in new upstream changes:

```bash
git fetch upstream
git merge upstream/main
ddev exec composer php-cs-fixer:fix && ddev exec composer phpstan && ddev exec composer test
```

Resolve conflicts by keeping TYPO3 v14 patterns (`final class`, constructor
DI, `getToolSchema()`).

### Repository layout

```
Classes/
  MCP/         MCP server, tool registry, tool implementations
  Service/     workspace, TCA, language, file sandbox, OAuth, capability manifest, local mode
  Http/        /mcp endpoint + OAuth/discovery endpoints
  Controller/  backend module controller
  Command/     CLI commands (mcp:server, mcp:tool, per-tool shortcuts)
Configuration/
  Capabilities.yaml   declared subsystems + per-tool requirements + outbound policy
  Services.yaml       DI + console.command + event listener registration
  Commands.php        Symfony command map
Documentation/        reStructuredText manual (published source)
Resources/            templates, CSS/JS, XLIFF labels (en + de)
Tests/                unit, functional, LLM, architecture, E2E
```

## Documentation

Canonical manual under `Documentation/` in reStructuredText. Suggested
reading order:

| Topic | Entry point |
|---|---|
| Overview & safety model | [`Introduction/Index.rst`](Documentation/Introduction/Index.rst) |
| What the tools promise | [`Introduction/IntendedBehavior.rst`](Documentation/Introduction/IntendedBehavior.rst) |
| Install & activate | [`Installation/Index.rst`](Documentation/Installation/Index.rst) |
| Module, OAuth, sandbox, manifest, local mode | [`Configuration/Index.rst`](Documentation/Configuration/Index.rst) |
| Full MCP tool reference | [`Tools/Index.rst`](Documentation/Tools/Index.rst) |
| Architecture deep-dives | [`Architecture/Index.rst`](Documentation/Architecture/Index.rst) |
| Security audit | [`Architecture/SecurityAudit.rst`](Documentation/Architecture/SecurityAudit.rst) |
| Testing with Cursor | [`Testing/CursorTesting.md`](Documentation/Testing/CursorTesting.md) |
| Full-feature chatbot script | [`Testing/FullFeatureChatbotScript.md`](Documentation/Testing/FullFeatureChatbotScript.md) |
| E2E test suite | [`Testing/E2eSuite.rst`](Documentation/Testing/E2eSuite.rst) |
| Troubleshooting | [`Troubleshooting/Index.rst`](Documentation/Troubleshooting/Index.rst) |

Long-form design rationale and real-world scenarios:
[`TECHNICAL_OVERVIEW.md`](TECHNICAL_OVERVIEW.md).

A claude-code skill for adding new CLI commands ships at
`~/.claude/skills/typo3-mcp-cli/` (this fork's developer-experience helper).

## Acknowledgements

Thank you to [hauptsacheNet](https://github.com/hauptsacheNet) and
Marco Pfeiffer for open-sourcing the original TYPO3 MCP Server: a strong,
editor-first, workspace-safe foundation that this project builds on. The
capability-manifest concept is adapted from
[Kurt Dirnbauer's TYPO3-extension-security article](https://www.webconsulting.at/blog/typo3-extension-security-emdash-capability-manifests).

## License

GPL-2.0-or-later
