# TYPO3 MCP Server Technical Overview

This document provides a comprehensive technical overview of the TYPO3 MCP (Model Context Protocol) Server extension. It explains how AI assistants can safely interact with TYPO3 content through a carefully designed interface that maintains security while hiding complexity.

## Project Lineage

This repository builds on the original TYPO3 MCP Server work by Marco Pfeiffer
and hauptsacheNet. That original foundation was strong in exactly the right
places: TYPO3-native, editor-first, workspace-safe, and practical in its tool
design. The current v14-focused line keeps that direction while tightening
security, clarifying MCP ergonomics, and simplifying the maintained
documentation set.

## Introduction & Core Concepts

### What is the TYPO3 MCP Server?

The TYPO3 MCP Server is an extension that bridges the gap between AI language models and TYPO3 content management. It provides a standardized interface that allows AI assistants like Claude, ChatGPT, or other MCP-compatible tools to:

- Read and understand TYPO3 content structure
- Create and modify content safely
- Navigate complex site hierarchies
- Work with any TYPO3 extension's data

### The Problem It Solves

Traditional CMS interfaces are designed for human interaction through web browsers. AI assistants need a different approach - one that provides structured data access while maintaining the safety and workflow controls that make TYPO3 reliable. The MCP Server solves this by:

- **Ensuring all changes go through workspaces** - no accidental live modifications
- **Providing AI-friendly interfaces** - structured data instead of HTML forms
- **Maintaining security** - respecting user permissions and access controls
- **Supporting complex operations** - handling relations, translations, and more

### How MCP Fits In

The Model Context Protocol (MCP) is an open standard for connecting AI systems with external tools and data sources. In the TYPO3 context:

- **MCP Client**: Your AI assistant (Claude Desktop, custom implementations, etc.)
- **MCP Server**: This TYPO3 extension
- **TYPO3**: Your content management system

The MCP Server acts as an intelligent translator between what the AI understands and how TYPO3 works internally.

```
┌──────────────┐     OAuth/HTTP      ┌─────────────────┐
│  MCP Client  │◄───────────────────▶│   MCP Server    │
│(Claude, etc) │     stdin/stdout    │  (TYPO3 Ext)    │
└──────────────┘                     └────────┬────────┘
                                              │
                                              ▼
                                     ┌─────────────────┐
                                     │  TYPO3 Core     │
                                     │  - DataHandler  │
                                     │  - Workspaces   │
                                     │  - TCA/Database │
                                     └─────────────────┘
```

## Core Principles

These design principles guide every aspect of the MCP Server implementation:

### 1. Workspace Transparency
Record-backed modifications automatically happen in TYPO3 workspaces. The AI
assistant doesn't need to understand TYPO3 version rows - it just creates or
modifies records, and the system stages those changes for review. Clients can
also choose a workspace explicitly through ``workspace_id`` when needed. From
the AI's perspective, it is still working with stable record IDs while the
workspace system manages versions and drafts behind the scenes.

### 2. TCA-First Approach
TYPO3's TCA is designed to create human-understandable forms, and we leverage this same design to create AI-understandable data representations. Every operation is based on the Table Configuration Array rather than raw database schemas. This means:
- Validation rules are automatically enforced
- Field types are properly handled
- Relations work as configured
- Well-named fields and descriptions in TCA automatically create a good AI interface
- The same effort that makes forms intuitive for editors makes data intuitive for AI

### 3. Familiar Interface Patterns
The MCP tools closely resemble TYPO3's Page-Tree and List-Module interfaces. This familiar pattern means:
- TYPO3 users can easily estimate what the AI will see and understand
- Extensions that design good interfaces through TCA automatically provide good AI interfaces
- Better for everyone: improvements in human usability translate directly to AI usability

### 4. Extension Compatibility
TYPO3 extensions do not need custom MCP adapters when they fit TYPO3's normal
record model. Tables with suitable TCA, user access, and workspace support for
writes can usually work automatically through the generic tools. MCP still
applies table restrictions for security and system integrity, so "editable in
the backend" is not the same as "exposed through MCP". This includes:
- Core TYPO3 content tables such as pages and content elements
- Popular extensions (news, etc.)
- Your custom extensions

### 5. User Context & Responsibility
The MCP operates with the permissions and workspace of the authenticated user. It's a tool to help editors, but editors remain responsible for the content. The AI assistant:
- Can only access what the user can access
- Creates changes in the user's workspace and in the users name
- Respects all permission settings
- Maintains an audit trail

### 6. Page-Centric Context
Everything in TYPO3 revolves around pages, and the MCP Server embraces this. Most operations require or benefit from page context:
- Content elements belong to pages
- Records are often filtered by page
- Permissions are page-based
- URLs map to pages

### 7. Safety by Default
No direct modifications to live data are possible. Every change:
- Goes through TYPO3's DataHandler
- Is created in a workspace
- Must be explicitly published
- Can be reviewed before going live

### 8. Thoughtful Data Representation
The complexity of TYPO3 is hidden through carefully crafted data representations. Rather than simply dumping JSON, we thoughtfully curate what the AI sees:
- JSON structures that mirror the form layouts, showing only relevant fields
- Friendly error messages instead of technical exceptions
- Logical field names with context from TCA labels and descriptions
- Automatic handling of relations and references
- Tab and palette groupings preserved to maintain semantic relationships

### 9. MCP ergonomics (mcp-builder alignment)

This extension is reviewed against the public
[mcp-builder skill](https://github.com/anthropics/skills/blob/main/skills/mcp-builder/SKILL.md)
(Model Context Protocol — tool design, schemas, annotations, errors, pagination).

**What matches the guide**

- **Tool schemas**: Each tool exposes a top-level `description`, JSON Schema
  `inputSchema` with per-field descriptions, and `required` where appropriate.
  Record-backed tools share an optional `workspace_id` (see `AbstractRecordTool`).
- **Annotations**: Every registered tool sets all four hints —
  `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint` — so
  clients can classify calls without guessing.
- **Actionable errors**: `AbstractTool` + `ExceptionHandlerTrait` map exceptions
  to `CallToolResult` errors with user-facing text; `McpException` /
  `ValidationException` carry editor-oriented messages. Server-side details stay
  in logs. Unknown tool names in ``tools/call`` are answered the same way: a
  tool result with ``isError`` and a hint to call ``tools/list`` (avoids a
  JSON-RPC ``-32603`` from the PHP SDK’s generic exception path — see
  [mcp-builder](https://github.com/anthropics/skills/blob/main/skills/mcp-builder/SKILL.md)
  on recoverable agent mistakes).
- **Context / pagination**: `ReadTable` returns `total`, `count`, `limit`,
  `offset`, `nextOffset`, and `hasMore` in JSON (see tool description).
  `Search` enforces its per-table `limit` and reports both total matches and
  returned matches when a table was truncated. Tree tools warn about depth vs.
  site size and now include workspace-only draft pages in the visible tree.
- **Transport**: Remote HTTP (OAuth) for hosted use; local `stdio` via
  `McpServerCommand` for trusted environments — consistent with the guide’s
  HTTP vs. stdio split.

**Intentional differences**

- **Naming**: mcp-builder often recommends a `service_action` prefix (e.g.
  `github_list_repos`). Here tools use stable **PascalCase** names derived from
  class names (`ReadTable`, `WriteTable`, …) to match TYPO3 vocabulary and
  existing clients. Discoverability is documented in
  `Documentation/Tools/Index.rst` (tool table) and this file.
- **Structured tool output**: The PHP SDK in use does not expose
  `outputSchema` / structured content on `CallToolResult` the way some TS/Python
  stacks do. Successful tools return **JSON (or text) inside `TextContent`**;
  schemas describe *inputs*; models should parse JSON from the text payload.
  See also `Documentation/Architecture/SecurityAudit.rst`.

**Security and “open world”**

- File and URL tools are sandbox-bound and SSRF-limited (`UploadFileFromUrl`).
- Record writes are workspace-first; live rows are not edited directly through
  these tools.

### 10. Versioning and Evolution in TYPO3 v14

The extension is now aligned strictly to TYPO3 v14. That does **not** mean the
MCP surface is frozen.

- Tool names, descriptions, and parameters may change within the v14 line when
  that improves LLM usability, TYPO3 correctness, or security.
- MCP contracts are treated as editor/product ergonomics, not as a legacy API
  that must preserve every historical naming choice.
- Production users should pin versions and review release notes before upgrades.


## Available Tools

The MCP Server provides these tools for interacting with TYPO3:

### Discovery & Navigation
- **GetPageTree** - Navigate site hierarchy and explore page structure
- **GetPage** - Get page details by URL or ID with content summary
- **ListTables** - Discover available TYPO3 tables and extensions
- **Search** - Find content across tables using full-text search

### Content Reading & Schema
- **ReadTable** - Read records from any TYPO3 table with filtering, pagination, and language support
- **GetTableSchema** - Understand table structure, field types, and validation
- **GetFlexFormSchema** - Get plugin configuration schemas (FlexForm DataStructures)

### Content Modification
- **WriteTable** - Create, update, translate, or delete records (safely in workspace)

#### Write positioning and TYPO3 DataHandler

`WriteTable` uses TYPO3's `DataHandler` for record creation and updates, but the
MCP-facing `position` parameter is a higher-level abstraction. TYPO3 does not
offer native `top` and `before` create tokens, so the MCP server translates
these into workspace-aware operations that behave as editors would expect.

For `action=create`, the current behavior is:

- `bottom`: create on the requested page and, for sortable tables, assign a
  sorting value after the last visible sibling in the active workspace context
- `top`: create on the requested page and, for sortable tables, assign a
  sorting value before the first visible sibling in the active workspace context
- `after:UID`: resolve the visible target record first and then use TYPO3's
  native create-after-record behavior by passing a negative pid target to
  `DataHandler`
- `before:UID`: resolve the visible target record and translate the request to
  "after previous visible sibling"; if the target is already first, this becomes
  a top insert

This translation layer is deliberate. It keeps the MCP interface intuitive
while still staying aligned with TYPO3's official `DataHandler` model and
workspace behavior.

### File Management
- **BrowseFiles** - Browse folders inside the dedicated MCP file sandbox and inspect the current sandbox root and upload folder behavior
- **ReadFileMetadata** - Read metadata for a file by UID or path inside the sandbox (title, description, alt text, categories, dimensions, usage references)
- **WriteFile** - Create or overwrite text-based files in the MCP file sandbox and/or update file metadata (title, description, alt text, copyright). Supports `.txt`, `.html`, `.css`, `.js`, `.json`, `.xml`, `.csv`, `.svg`, `.yaml`, `.md`, and other text formats. Can update metadata on existing files — including images — without changing content.
- **UploadFile** - Upload binary or text files into the MCP file sandbox using base64 payloads. Uploads are restricted to a configurable sandbox folder (default: `fileadmin/mcp/`) and can use workspace-specific subfolders for safer draft handling.
- **UploadFileFromUrl** - Download a public `http` or `https` URL server-side and store the result in the MCP file sandbox. This avoids base64 size limits and adds SSRF and file-size safeguards.

> **Note:** Physical files are **not** workspace-versioned. File writes and metadata changes take effect immediately across all workspaces.

### Workspace Management
- **ListWorkspaces** - List workspaces available to the current user and show which one is active. Use to get a `workspace_id` for other tools.

> Each tool provides detailed schema information when called. See the Real-World Scenarios below for practical examples.

## Real-World Scenarios

Here are practical examples of how the MCP Server enables AI-powered content management:

### "Translate that page"

**User says**: "Translate the /about-us page to German"

**What happens**:
1. AI uses `GetPage` with URL "/about-us" to fetch the page
2. Reads all content elements using `ReadTable` with pid filter
3. Translates the text content
4. Creates German language versions using `WriteTable`
5. Sets proper language relations and parent references

**Tool calls**:
```json
// 1. Get page info
{"tool": "GetPage", "params": {"url": "/about-us"}}

// 2. Read content elements
{"tool": "ReadTable", "params": {
  "table": "tt_content",
  "pid": 123
  // No language parameter = default language
}}

// 3. Create translations
{"tool": "WriteTable", "params": {
  "table": "tt_content",
  "action": "translate",
  "uid": 456,
  "data": {
    "sys_language_uid": "de",
    "header": "Über uns",
    "bodytext": "[translated content]"
  }
}}
```

### "Create a news article from this Word draft"

**User says**: "Create a news article from this document" [provides Word file]

**What happens**:
1. AI extracts content from the Word document
2. Finds appropriate storage location for news articles
3. Uses `GetTableSchema` to understand news record structure
4. Searches for or creates appropriate categories
5. Creates news record with proper metadata
6. Handles relations and references

**Tool calls**:
```json
// 1. Find news storage folder
{"tool": "GetPageTree", "params": {"startPage": 0, "depth": 3}}
// or
{"tool": "ReadTable", "params": {
  "table": "pages",
  "where": "doktype = 254",
  "limit": 10
}}

// 2. Check news table structure
{"tool": "GetTableSchema", "params": {"table": "tx_news_domain_model_news"}}

// 3. Look for existing categories
{"tool": "ReadTable", "params": {
  "table": "tx_news_domain_model_category",
  "pid": 789
}}

// 4. Create news article
{"tool": "WriteTable", "params": {
  "table": "tx_news_domain_model_news",
  "action": "create",
  "pid": 789,
  "data": {
    "title": "Annual Report 2024 Released",
    "teaser": "Our latest financial results...",
    "bodytext": "[full article content]",
    "categories": [12, 15],
    "datetime": "2024-01-15T10:00:00"
  }
}}
```

### "Proofread and judge the tone of site X"

**User says**: "Review the tone of our product pages and make them more friendly"

**What happens**:
1. AI finds all product pages using `GetPageTree`
2. Reads content from each page
3. Analyzes tone and style
4. Provides specific recommendations
5. Can update content if requested

### "Fill in the SEO descriptions of those sites"

**User says**: "Add meta descriptions to all pages that don't have them"

**What happens**:
1. AI searches for pages without descriptions
2. Reads page content to understand context
3. Generates appropriate meta descriptions
4. Updates page records with SEO content

### "Add alt text to all product images"

**User says**: "Add descriptive alt text to all images in the product folder"

**What happens**:
1. AI uses `BrowseFiles` to list files in the product images folder
2. Reads existing metadata with `ReadFileMetadata` for each image
3. Generates appropriate alt text based on file names and context
4. Updates metadata using `WriteFile` (metadata-only mode)

**Tool calls**:
```json
// 1. Browse the product images folder
{"tool": "BrowseFiles", "params": {"path": "products/"}}

// 2. Read metadata for an image
{"tool": "ReadFileMetadata", "params": {"identifier": "products/widget-pro.jpg"}}

// 3. Update metadata (no content change, just metadata)
{"tool": "WriteFile", "params": {
  "path": "products/widget-pro.jpg",
  "metadata": {
    "alternative": "Widget Pro - ergonomic design in brushed aluminium",
    "title": "Widget Pro Product Photo",
    "description": "Front view of the Widget Pro showing the new ergonomic design"
  }
}}
```

### "Generate an SVG icon"

**User says**: "Create a simple phone icon for our contact section"

**What happens**:
1. AI generates SVG markup for the requested icon
2. Uses `WriteFile` to save it inside the MCP file sandbox with descriptive metadata
3. Parent folders are created automatically if they don't exist

**Tool calls**:
```json
{"tool": "WriteFile", "params": {
  "path": "icons/contact-phone.svg",
  "content": "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z\"/></svg>",
  "metadata": {
    "title": "Contact Phone Icon",
    "alternative": "Phone icon for contact section",
    "description": "Line-art phone icon, 24x24 viewBox, uses currentColor"
  }
}}
```

### "Which workspace am I in?"

**User says**: "Show me available workspaces" or "Switch to the staging workspace"

**Tool calls**:
```json
// List all workspaces
{"tool": "ListWorkspaces", "params": {}}

// Use a specific workspace for subsequent operations
{"tool": "ReadTable", "params": {
  "table": "tt_content",
  "pid": 1,
  "workspace_id": 3
}}
```

**Note on Limitations**: Complex operations like "translate the entire page" may hit context window limits depending on the MCP client and language model. Consider processing in chunks for large pages.

## Key Features in Detail

### URL Resolution

The `GetPage` tool intelligently handles various URL formats:

- **Full URLs**: `https://example.com/about-us`
- **Paths**: `/about-us` or `about-us`  
- **Multi-language**: Detects language from URL
- **Domain validation**: Ensures URLs match configured sites
- **Fallback strategies**: Router → slug lookup → ID

### Relation Handling

Relations are transparently resolved and can be set using simple syntax:

- **Select relations**: Use comma-separated IDs or arrays
- **Inline relations**: Provide as nested objects
- **MM relations**: Handled automatically
- **File references**: Browsable via `BrowseFiles`, metadata readable/writable via `ReadFileMetadata` and `WriteFile`, binaries uploadable via `UploadFile`
- **Bidirectional**: Updates both sides as needed

### File Management

The MCP Server exposes a **restricted** subset of TYPO3 FAL, intentionally
limited to the configured MCP file sandbox (default `1:/mcp/`):

1. **Browse Sandbox Folders**: Navigate only the configured MCP file sandbox, not arbitrary `fileadmin` paths
2. **Read Metadata**: Inspect title, description, alt text, dimensions, categories, and usage-related metadata for files inside the sandbox
3. **Write Text Files**: Create or overwrite text-based files (`.txt`, `.html`, `.css`, `.js`, `.json`, `.xml`, `.csv`, `.svg`, `.yaml`, `.md`) inside the sandbox
4. **Upload Files**: Upload binary or text files into the sandbox via base64 (`UploadFile`) or public URL (`UploadFileFromUrl`)
5. **Update Metadata**: Set title, description, alternative text, and copyright on any existing sandbox file — including images — without changing file content

**Important**: Physical files are **not** workspace-versioned. File writes and metadata changes take effect immediately. The MCP file sandbox reduces risk by restricting operations to a dedicated folder and can place uploads in workspace-specific subfolders, but the physical file still exists immediately once uploaded. Record-based data (content elements, pages, etc.) remains safely workspace-versioned behind the TYPO3 workspace safety boundary.

### HTTP Transport Hardening

Recent v14 maintenance tightened the remote MCP transport itself, not only the
record model behind it:

1. **Safe logging**: sensitive headers and query-token values are redacted
   before request details are written to logs (`McpHttpLogRedactor`).
2. **Query-token auth disabled by default**: bearer tokens in `?token=` are
   only accepted when explicitly enabled via extension configuration
   (`allowMcpTokenInQueryString`).
3. **Minimal auth diagnostic**: the lightweight `?test=auth` probe used by the
   backend module no longer exposes server fingerprint details and can be
   disabled via `enableMcpAuthHeaderDiagnostic`.

This keeps the remote MCP endpoint safer in real hosting environments where
reverse proxies, access logs, and misconfigured header forwarding are common.

### Language Support

The MCP Server provides sophisticated multi-language support:

1. **ISO Code Support**: Instead of numeric language UIDs, use ISO codes like 'de', 'fr', 'en'
2. **Automatic Discovery**: Available languages are discovered from site configuration
3. **Smart Schema Display**: Language fields show available ISO codes in GetTableSchema
4. **Translation Actions**: Built-in support for creating and managing translations

Example:
```json
// Instead of: "sys_language_uid": 1
// Use: "sys_language_uid": "de"
```

### Workspace Magic

Behind the scenes, the workspace system:

1. **Keeps, finds, or creates** an appropriate workspace
2. **Manages versions** without exposing version UIDs
3. **Handles deletes** through delete placeholders
4. **Overlays data** for transparent reading
5. **Queues changes** for editorial review

The `ListWorkspaces` tool lets AI assistants see which workspaces are available
and which one is currently active. Any record-backed tool accepts an optional
`workspace_id` parameter to target a specific workspace. This gives editors
explicit control over where changes land while keeping TYPO3's internal version
rows out of the MCP-facing interface.

### Validation & Error Handling

Errors are designed to help AI assistants self-correct:

```json
{
  "error": "Validation failed",
  "details": {
    "field_errors": {
      "title": "This field is required",
      "email": "Invalid email format"
    },
    "suggestions": {
      "email": "Use format: user@example.com"
    }
  }
}
```

### Permission Handling

The MCP Server respects all TYPO3 permissions:

- **Page permissions**: Read, write, delete
- **Table permissions**: Based on user group
- **Field permissions**: Exclude fields work
- **Record permissions**: Custom access checks
- **Workspace permissions**: Automatic workspace selection

## Implementation Architecture

The runtime is intentionally thin and delegates most TYPO3-specific work to
shared services.

### Request Path

1. **Remote clients** authenticate through the OAuth endpoints and call the
   MCP HTTP endpoint.
2. **Local clients** start the stdio server through `vendor/bin/typo3 mcp:server`.
3. `McpServerFactory` builds the MCP server and registers the tool handlers.
4. `ToolRegistry` provides the tool instances.
5. Tools call shared services for workspace selection, TCA access, language
   mapping, URL resolution, file sandbox restrictions, and OAuth validation.
6. TYPO3 core APIs such as `DataHandler`, `PageRepository`, `TcaSchemaFactory`,
   and FAL do the actual CMS work.

### Key Services

- **WorkspaceContextService**: keeps the current non-live workspace by default,
  switches explicitly when requested, otherwise selects a writable workspace,
  and if necessary creates one
- **TableAccessService**: central gatekeeper for table access, field access,
  workspace capability, and TSconfig-based field visibility
- **LanguageService**: maps site languages to ISO codes and lets tool schemas
  hide translation-specific parameters when the site is effectively monolingual
- **McpFileSandboxService**: constrains all file tools to the configured MCP
  sandbox root and computes workspace-specific upload folders
- **OAuthService**: stores authorization codes and hashes access tokens before
  they are written to the database

### Testing Strategy

The repository verifies behavior at several levels:

- **Unit tests** for focused services such as OAuth hashing and file sandbox
  path handling
- **Functional TYPO3 tests** for record reads/writes, workspace transparency,
  language handling, file tools, non-admin permissions, and extension
  compatibility such as `georgringer/news`
- **LLM tests** that use real models to check whether tool descriptions and
  schemas are intuitive in realistic multi-step conversations

## What's Not Yet Implemented

While the MCP Server is powerful, some features are still in development:

### Full Physical File Versioning
- Binary uploads are supported through `UploadFile`, but TYPO3 still does not workspace-version physical files
- The MCP file sandbox can isolate uploads to a dedicated sandbox folder and optional workspace subfolders
- Publishing still affects only record references and content; uploaded files themselves exist immediately after upload

### Workspace Publishing
- Cannot publish workspace changes through MCP
- Must use TYPO3 backend for reviewing and publishing
- Workspace selection and listing is fully supported

### Bulk Operations Optimization
- Large batch operations may be slow
- No built-in chunking for massive updates
- Consider breaking into smaller operations

## Best Practices for Users

To get the most out of your AI assistant with TYPO3:

### Use Full Page URLs
Give your AI assistant complete URLs like `https://example.com/about-us`. The system can automatically resolve these to the correct pages, making your instructions clearer and reducing errors.

### Be Specific About Scope
For large operations, specify exactly what to process. Instead of "update all pages", say "update the meta descriptions for pages under /products". This helps avoid context window limits and ensures focused results.

### Review Before Publishing
Always check the Workspaces module to review AI-generated changes before they go live. The AI is powerful but should be treated as a helpful assistant, not an autonomous system.

### Provide Context
Give your AI assistant relevant background information. For example: "We're a law firm, keep the tone professional" or "This is for our summer campaign, make it cheerful".

### Work Incrementally
For complex tasks, break them into smaller steps:
1. First, analyze the current content
2. Then, make specific improvements
3. Finally, review and refine

**Tip**: For very complex operations, consider using multiple chat sessions in parallel. Each session maintains its own context, allowing you to tackle different aspects of a project simultaneously without overwhelming a single conversation.

### Understand the Publishing Workflow
Remember that all changes need your approval:
1. AI creates/modifies content in workspace
2. You review in TYPO3 Backend → Workspaces
3. You publish approved changes
4. Content goes live on your site

This workflow ensures you maintain full control while benefiting from AI efficiency.
