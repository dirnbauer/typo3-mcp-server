# TYPO3 MCP Server Extension

> [!WARNING]
> This fork includes experimental support for `fileadmin` access and explicit workspace
> selection. The capabilities are promising and covered by automated tests, but they should
> be validated in a staging environment before being introduced into production editorial or
> file-management workflows.
>
> Feedback is welcome via [GitHub Issues](https://github.com/hauptsacheNet/typo3-mcp-server/issues)
> or the [#typo3-core-ai Slack channel](https://typo3.slack.com/archives/C091M0M7BL6).

> [!NOTE]
> This repository is a public fork of [hauptsacheNet/typo3-mcp-server](https://github.com/hauptsacheNet/typo3-mcp-server).
> Special thanks to `hauptsacheNet` for the original project and for the implementation and
> testing work behind the newer capabilities in this codebase. If you need additional feature
> development, production hardening, or project-specific validation, the work should ideally
> be commissioned from and compensated to `hauptsacheNet`.

This extension provides a Model Context Protocol (MCP) server implementation for TYPO3 that allows
AI assistants to safely view and manipulate TYPO3 pages and records through TYPO3's workspace system.

## 🔒 Safe AI Content Management with Workspaces

**All content changes are automatically queued in TYPO3 workspaces**, making it completely safe for AI assistants to create, update, and modify content without immediately affecting your live website. Changes require explicit publishing to become visible to site visitors.

## What Can You Do?

With the TYPO3 MCP Server, your AI assistant can help you:

### 📝 **Content Management**
- **Translate Pages**: "Translate the /about-us page to German" - The AI reads your content, translates it, and creates proper language versions
- **Import Documents**: "Create a news article from this Word document" - Transform external documents into TYPO3 content with proper structure
- **Bulk Updates**: "Update all product descriptions to include our new sustainability message" - Make consistent changes across multiple pages

### 🔍 **Content Analysis & SEO**
- **SEO Optimization**: "Add meta descriptions to all pages that don't have them" - Automatically generate missing SEO content based on page content
- **Tone Analysis**: "Review the tone of our product pages and make them more friendly" - Get suggestions for improving content voice and style
- **Content Audit**: "Find all pages mentioning our old company name" - Quickly locate content that needs updating

### 📁 **File Management**
- **Browse Files**: "Show me what's in the images folder" - Navigate file storages and folders in fileadmin
- **Update Metadata**: "Add alt text to all product images" - Set or update title, description, alternative text, and copyright on any file
- **Create Text Files**: "Create a robots.txt with these rules" - Write text-based files (.txt, .html, .css, .json, .xml, .svg, .yaml, etc.) directly to fileadmin

### 🚀 **Productivity Boosters**
- **Template Application**: "Apply our standard legal disclaimer to all service pages" - Consistently apply content patterns
- **Content Migration**: "Copy all news articles from 2023 to the archive folder" - Reorganize content efficiently
- **Multi-language Management**: "Ensure all German pages have English translations" - Identify and fill translation gaps

All these operations happen safely in workspaces, giving you full control to review before publishing!

> 💡 **Want to know how it works?** Check out our [Technical Overview](TECHNICAL_OVERVIEW.md) for detailed information about the implementation, available tools, and real-world examples with actual tool calls.

## Project Status

| Feature                    | Status          | Notes                                                                                                         |
|----------------------------|-----------------|---------------------------------------------------------------------------------------------------------------|
| **MCP Connection**         | ✅ Ready         | HTTP and stdin/stdout protocols (thanks to [logiscape/mcp-sdk-php](https://github.com/logiscape/mcp-sdk-php)) |
| **Authentication**         | ✅ Ready         | OAuth for Backend Users                                                                                       |
| **Page Tree Navigation**   | ✅ Ready         | Page tree view similar to the TYPO3 backend                                                                   |
| **Page Content Discovery** | ✅ Ready         | Similar to the List or Page module with backend layout support                                                |
| **Record Reading/Writing** | ✅ Ready         | Read and write any workspace-capable TYPO3 table (core & extensions) with full schema inspection              |
| **Content Translation**    | ✅ Ready         | Workspace-aware with validation                                                                               |
| **Fileadmin Browsing**     | ✅ Ready         | Browse storages, read file metadata                                                                           |
| **File Writing**           | ⚠️ New           | Create/overwrite text files and update metadata (title, alt text, copyright); physical files are not workspace-versioned |
| **Workspace Selection**    | ✅ Ready         | Explicit workspace listing and selection via `workspace_id`; smart default preserved                          |

While there are a lot of automated tests, and even some [LLM test](Tests/Llm/README.md), TYPO3 instances are widely different and Language Models are also widely different. Feel free to [create issues here on GitHub](https://github.com/logiscape/mcp-sdk-php/issues) or [share experiences in the typo3-core-ai channel](https://typo3.slack.com/archives/C091M0M7BL6). 

## Installation

```bash
composer require hn/typo3-mcp-server
```

**Requirements:**
- TYPO3 v14
- PHP 8.2+
- TYPO3 Workspaces extension (automatically installed as dependency)

## Usage

### Quick Start

There are two ways to connect AI assistants like Claude Desktop to your TYPO3 installation:

#### Option 1: OAuth Authentication (Recommended)

For secure remote access with proper authentication:

1. Go to **[Username] → MCP Server** in your TYPO3 backend
2. Copy the Server URL (and optionally the Integration Name)
3. Add the Integration to whatever MCP Client you are using.

![MCP Server Setup](mcp_setup.png)

#### Option 2: Local Command Line Connection

This method gives you admin privileges by default. Add this to your mcp config file of Claude Desktop or whatever client you are using.
```json
{
   "mcpServers": {
      "[your-typo3-name]": {
         "command": "php",
         "args": [
            "vendor/bin/typo3",
            "mcp:server"
         ]
      }
   }
}
```

## Development

### Adding New Tools

Tools are defined in the `Classes/MCP/Tools` directory. Each tool follows the MCP tool specification and maps to specific TYPO3 functionality.

### Validating documentation

Run the TYPO3 documentation renderer locally before pushing documentation
changes:

```bash
composer docs:check
```

This uses the official `ghcr.io/typo3-documentation/render-guides` container
and fails on renderer warnings or broken ReST syntax.

## Available MCP Tools

| Tool | Description |
|------|-------------|
| **GetPageTree** | Navigate site hierarchy and explore page structure |
| **GetPage** | Get page details by URL or ID with content summary |
| **ListTables** | Discover available TYPO3 tables and extensions |
| **ReadTable** | Read records from any TYPO3 table with filtering and language support |
| **WriteTable** | Create, update, translate, or delete records (safely in workspace) |
| **GetTableSchema** | Understand table structure, field types, and validation |
| **GetFlexFormSchema** | Get plugin configuration schemas |
| **Search** | Find content across tables using full-text search |
| **BrowseFiles** | Browse file storages and folders in fileadmin |
| **ReadFileMetadata** | Read file metadata (title, description, alt text, dimensions) |
| **WriteFile** | Create/overwrite text files and update file metadata |
| **ListWorkspaces** | List available workspaces and select which one to use |

## Learn More

- 📖 **[Technical Overview](TECHNICAL_OVERVIEW.md)** - Comprehensive guide covering architecture, implementation details, and advanced usage
- 🏗️ **[Architecture Documentation](Documentation/Architecture/)** - Deep dives into specific implementation aspects:
  - [Workspace Transparency](Documentation/Architecture/WorkspaceTransparency.md) - How workspace complexity is hidden from AI
  - [Language Overlays](Documentation/Architecture/LanguageOverlays.md) - Multi-language content handling
  - [Inline Relations](Documentation/Architecture/InlineRelations.md) - Managing TYPO3's complex relation types

## License

GPL-2.0-or-later
