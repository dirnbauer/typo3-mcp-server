# TYPO3 MCP Server

TYPO3 MCP Server exposes TYPO3 content, structure, and selected file
operations through the
[Model Context Protocol](https://modelcontextprotocol.io/). It lets AI
assistants work with TYPO3 using tools that feel native to editors while still
respecting TYPO3 permissions, TCA, and editorial review workflows.

The extension is designed for real TYPO3 work:

- browse the page tree and inspect page content
- read and write any accessible workspace-capable table
- inspect TCA and FlexForm schemas before writing
- translate records using TYPO3 language handling
- search across content and records
- browse files, upload files, and update metadata inside a dedicated MCP file
  harness
- connect remote MCP clients over OAuth or local clients over CLI

## Why this extension exists

TYPO3 is an editorial CMS. MCP clients and LLMs need a structured interface,
not a browser backend. This extension bridges that gap without bypassing TYPO3.

The core idea is simple:

1. The AI talks to MCP tools instead of scraping TYPO3 HTML.
2. TYPO3 permissions, TCA, workspaces, and DataHandler stay in charge.
3. Editors review and publish changes in normal TYPO3 workflows.

That gives you AI-assisted content work without turning the live website into a
direct write target.

## Key capabilities

### Content and page work

- `GetPageTree` mirrors the TYPO3 page tree for navigation and discovery.
- `GetPage` resolves a page by URL or UID and returns page context plus content
  summaries.
- `ReadTable` and `WriteTable` provide structured access to TYPO3 records.
- `GetTableSchema` and `GetFlexFormSchema` help the client understand valid
  fields before it writes.
- `Search` supports content audits, migration work, and editor assistance.

Typical prompts:

- "Translate the `/about-us` page to German."
- "Create a news item from this draft."
- "Add missing meta descriptions below `/products`."
- "Find pages still using our old company name."

### Workspace-safe editing

All record writes are workspace-first.

- If a backend user already works in a writable workspace, that workspace is
  used.
- If not, the extension selects the first writable workspace.
- If no suitable workspace exists, the extension can create one.
- Clients can also pass `workspace_id` explicitly on record tools.

The MCP client does not need to understand TYPO3 workspace internals. The
extension hides that complexity and exposes stable, editor-friendly behavior.

### File operations with a harness

File handling is intentionally constrained.

- `BrowseFiles` only browses the MCP file harness.
- `ReadFileMetadata` only reads metadata for files inside the harness.
- `WriteFile` creates or updates text-based files inside the harness.
- `UploadFile` uploads binary or text files into the harness.

By default, the harness root is:

```text
fileadmin/mcp/
```

Internally this is stored as:

```text
1:/mcp/
```

Uploads can be placed into workspace-specific subfolders below that root. This
does not magically workspace-version physical files, but it reduces collisions
and keeps draft-oriented uploads separated from live-oriented file paths.

### Authentication and client connections

The extension supports two connection modes:

- remote MCP over HTTP with OAuth 2.1 + PKCE
- local stdio MCP via `vendor/bin/typo3 mcp:server`

The backend module under `User > MCP Server` provides:

- the MCP endpoint URL
- OAuth and token management
- client setup instructions
- workspace context information

## Feature tour

A local Remotion feature video is included in this repository:

- video: `Documentation/Media/typo3-mcp-server-feature-tour.mp4`
- poster: `Documentation/Images/FeatureTourPoster.png`

[![Feature tour poster](Documentation/Images/FeatureTourPoster.png)](Documentation/Media/typo3-mcp-server-feature-tour.mp4)

## Requirements

- TYPO3 `^14.0`
- PHP `>=8.2`
- `typo3/cms-workspaces`

The extension currently targets TYPO3 v14 in `composer.json`.

## Installation

Install with Composer:

```bash
composer require hn/typo3-mcp-server
```

Activate the extension:

```bash
vendor/bin/typo3 extension:activate mcp_server
```

Make sure the backend users who should use MCP have:

- access to the backend module
- access to the relevant page mounts
- access to relevant table permissions
- access to a writable workspace, or permission to let the extension create one

## Initial setup

### Recommended: remote MCP with OAuth

1. Open `User > MCP Server` in the TYPO3 backend.
2. Copy the server URL shown in the module.
3. Add that URL to your MCP client.
4. Complete the OAuth flow in the TYPO3 backend when prompted.

The backend module includes setup instructions for clients such as Claude
Desktop, n8n, Manus, and MCP Inspector.

### Local setup: TYPO3 CLI

For local tools or development clients with shell access:

```json
{
  "mcpServers": {
    "my-typo3-site": {
      "command": "php",
      "args": ["vendor/bin/typo3", "mcp:server"]
    }
  }
}
```

This is convenient for development, but it is a different trust model than the
OAuth-based remote endpoint.

## Extension configuration

The extension can be configured through TYPO3 extension configuration.

### `fileHarnessRoot`

Defines the sandbox root for MCP file tools.

Default:

```text
1:/mcp/
```

Examples:

- `1:/mcp/`
- `1:/ai-content/`

All MCP file tools are restricted to this root.

### `workspaceUploadSubfolders`

When enabled, `UploadFile` stores uploads in workspace-specific subfolders
below the harness root.

Default:

```text
true
```

Example resulting paths:

- live/default context: `1:/mcp/uploads/...` or directly below the harness
  root, depending on the requested path
- workspace context: `1:/mcp/workspaces/ws-3/...`

### Configuration goals

The file harness exists to make file operations safer and easier to reason
about:

- avoid unrestricted `fileadmin` access
- keep AI-generated files in a known location
- reduce naming collisions for uploads
- make cleanup and review easier

## Available tools

### Discovery and navigation

- `GetPageTree`
- `GetPage`
- `ListTables`
- `Search`

### Schema and reading

- `ReadTable`
- `GetTableSchema`
- `GetFlexFormSchema`

### Writing

- `WriteTable`

### Files

- `BrowseFiles`
- `ReadFileMetadata`
- `WriteFile`
- `UploadFile`

### Workspaces

- `ListWorkspaces`

## Detailed feature notes

### TCA-first access model

The extension builds its record interface from TYPO3 TCA instead of direct
database assumptions. That means:

- better field labels and descriptions for the AI
- automatic respect for many TYPO3 field semantics
- extension compatibility without custom MCP adapters per extension

### Language handling

Language-aware tools use TYPO3 language information and expose ISO language
codes where possible. When a TYPO3 instance has no language setup, translation
concepts are hidden rather than partially exposed.

### File upload semantics

`UploadFile` is now available, but an important TYPO3 rule still applies:

- physical files are not workspace-versioned

The extension does not hide this. Instead, it makes file work safer through a
dedicated harness and optional workspace-scoped upload folders.

Use this model for:

- media drafts that should not pollute general `fileadmin` paths
- generated assets
- AI-assisted file intake workflows

Do not assume that an uploaded file is "invisible until publish" just because a
record reference lives in a workspace.

### Security model

The extension is built around a few non-negotiable rules:

- record writes go through TYPO3 workspaces
- TYPO3 permissions still apply
- access tokens are hashed before storage
- file operations are sandboxed to the harness root
- tool inputs are validated and normalized
- TCA and DataHandler remain central for record operations

## Testing and quality

The project includes unit tests, functional TYPO3 integration tests, and LLM
tests.

Run the full suite:

```bash
composer test
```

Validate documentation rendering:

```bash
composer docs:check
```

Recent file harness and upload changes are covered by TYPO3 functional tests.

## Development

### Repository structure

- `Classes/` contains the PHP implementation
- `Configuration/` contains TYPO3 module, route, and service wiring
- `Documentation/` contains TYPO3 RST documentation
- `TECHNICAL_OVERVIEW.md` contains the deep architectural overview
- `Tests/` contains unit, functional, and LLM-oriented tests
- `remotion/` contains the feature-tour video source

### Adding or changing tools

Tools live in `Classes/MCP/Tool/` and are auto-registered through the tool
interface tag. Record tools should follow the existing workspace-aware patterns
in the record tool base classes. File tools should go through the MCP file
harness rather than using arbitrary paths.

### Rendering the feature video

Install Node dependencies and render the video:

```bash
npm install
npm run remotion:render
```

This renders the feature tour into `Documentation/Media/`.

## Documentation

For TYPO3-style documentation, start with:

- `Documentation/Index.rst`
- `Documentation/Introduction/Index.rst`
- `Documentation/Installation/Index.rst`
- `Documentation/Configuration/Index.rst`
- `Documentation/Tools/Index.rst`
- `Documentation/Architecture/Index.rst`

For implementation detail and design rationale, see:

- [`TECHNICAL_OVERVIEW.md`](TECHNICAL_OVERVIEW.md)
- [`Documentation/Architecture/WorkspaceTransparency.rst`](Documentation/Architecture/WorkspaceTransparency.rst)
- [`Documentation/Architecture/LanguageOverlays.rst`](Documentation/Architecture/LanguageOverlays.rst)
- [`Documentation/Architecture/InlineRelations.rst`](Documentation/Architecture/InlineRelations.rst)

## Acknowledgements

This repository is based on
[hauptsacheNet/typo3-mcp-server](https://github.com/hauptsacheNet/typo3-mcp-server).
Special thanks to `hauptsacheNet` for the original project and the foundation
this fork builds upon.

## License

GPL-2.0-or-later
