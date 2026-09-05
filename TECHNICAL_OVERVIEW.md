# TYPO3 MCP Server — Technical Overview

This is the architecture entry point for contributors. The canonical manual
owns the detailed contracts so tool parameters, configuration, and examples do
not have to be maintained twice.

## Design principles

- **TYPO3 remains authoritative.** Use DataHandler for record writes, FAL for
  files, `PageRepository` for language overlays, and the Schema API for table
  semantics. TCA and the authenticated backend user's permissions determine
  what a tool may access.
- **Workspace internals stay transparent.** Strict mode selects or creates a
  writable draft. Trusted local mode defaults an omitted `workspace_id` to
  live workspace `0`; an explicit draft still stages local changes. Clients
  use stable live-facing UIDs.
- **Files have different semantics.** Physical writes take effect immediately.
  The MCP sandbox limits write locations in strict mode; backend file mounts
  apply in every mode. Only file references are workspace-versioned.
- **Schemas follow the instance.** Language parameters appear only when
  meaningful site languages exist. Optional extension data is discovered at
  runtime. Tool contracts may evolve within TYPO3 v14 to improve usability.
- **Policy is shared.** Native MCP, CLI, and Abilities projections execute the
  same governed tools. Local mode relaxes documented workspace, file, and
  outbound restrictions, never authentication or backend-user permissions.

## Request path

1. HTTP requests enter `/mcp` through `McpServerMiddleware`.
2. `AuthenticationRateLimiter` applies TYPO3's per-IP failure budget;
   `McpEndpoint` validates the bearer token and initializes backend-user context.
3. `McpServerFactory` builds the `logiscape/mcp-sdk-php` server. The SDK handles
   the supported session-based and stateless protocol versions.
4. `ToolRegistry` discovers tagged tools. `AbstractTool` enforces the capability
   manifest and normalizes errors; record tools select workspace context.
5. Shared services enforce page, table, field, language, file, and network
   policy before TYPO3 Core performs the operation.
6. `ToolResultNormalizer` retains readable text and adds structured JSON where
   applicable.

Local `mcp:server` starts at the factory after CLI backend-user bootstrap.
`GetCapabilities` exposes runtime policy and a compact user/permission summary
without switching workspace. Its summary guides discovery; each operation
still performs its own authorization checks.

## Local stdio and the host OS boundary

`vendor/bin/typo3 mcp:server` runs as the OS user that starts it, or inside the
DDEV container when launched through `ddev exec`. TYPO3 permissions do not
isolate that PHP process from the rest of the host. A client with additional
shell access can act with those OS privileges. Use local stdio on trusted
hosts with suitable OS accounts and credentials; see the
[installation guidance](Documentation/Installation/Index.rst).

## Canonical references

| Topic | Maintained source |
|---|---|
| Project lineage and fork changes | [Fork changes](Documentation/Introduction/ForkChanges.rst) |
| Runtime layers and shared services | [Implementation overview](Documentation/Architecture/ImplementationOverview.rst) |
| Workspace selection and stable UIDs | [Workspace transparency](Documentation/Architecture/WorkspaceTransparency.rst) |
| Page language overlays | [Language overlays](Documentation/Architecture/LanguageOverlays.rst) |
| Inline relations and DataHandler | [Inline relations](Documentation/Architecture/InlineRelations.rst) |
| Per-tool subsystem and network policy | [Capability manifest](Documentation/Architecture/CapabilityManifest.rst) |
| Schema, manifest, Abilities, and skills | [Capabilities and Abilities](Documentation/Architecture/CapabilitiesAndAbilities.rst) |
| SDK and tested wire protocols | [Protocol compatibility](Documentation/Architecture/ProtocolMigration.rst) |
| Security decisions and accepted risks | [Security audit](Documentation/Architecture/SecurityAudit.rst) |
| Tool parameters and limits | [Tool reference](Documentation/Tools/Index.rst) |
| Local live editing and strict mode | [Local-mode configuration](Documentation/Configuration/LiveEditsOnDevelopment.rst) |
| Executable workflows and quality gates | [Testing](Documentation/Testing/Index.rst) |
| End-to-end editing scenarios | [Chatbot test script](Documentation/Testing/FullFeatureChatbotScript.md) |

Runtime operations belong in tools; reusable editorial instructions belong in
skills. Bundled skills are exposed as MCP prompts and Markdown resources, and
all their operations still pass through the same permission and policy gates.
See the [intended behavior](Documentation/Introduction/IntendedBehavior.rst)
before changing a public contract.
