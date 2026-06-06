# Changelog

All notable changes to this fork are documented here. The fork tracks
[hauptsacheNet/typo3-mcp-server](https://github.com/hauptsacheNet/typo3-mcp-server)
upstream and adds the items below.

The project follows [Keep a Changelog](https://keepachangelog.com/) and
SemVer once it leaves the experimental surface.

## Unreleased

### Changed

- **Local development default workspace (major behaviour change).** On DDEV /
  trusted local mode, record tools now default to the **live workspace** when
  `workspace_id` is omitted — AI edits update the published local copy
  immediately instead of auto-creating a draft workspace. **Production is
  unchanged** (draft-first). Per-user opt-out via User TSconfig
  `options.mcpServer.localUnsafeMode = off`. Production override (opt-in
  DDEV-like live chatbot edits): see
  `Documentation/Configuration/LiveEditsOnDevelopment.rst`.

### Added

- **Capability manifest** (`Configuration/Capabilities.yaml`) declares
  every MCP tool's required subsystems and an outbound-network policy.
  Enforced at runtime via `CapabilityManifestService` inside
  `AbstractTool::execute()` and the network paths of `UploadFileFromUrl`
  and `RenderRecord`. Default `network.outbound: [self]` ships closed —
  operators opt in to public web per deployment.
- **Capability prerequisite chains** — `requires:` map in
  `Capabilities.yaml`. Removing `database:write` automatically disables
  every `file:write`-, `workspace:write`-, `site:write`-, and
  `extension:install`-dependent tool. Removing `file:read` disables
  `file:write`. Adapted from the [capability-manifest article](https://www.webconsulting.at/blog/typo3-extension-security-emdash-capability-manifests)
  and enforced at runtime; rejection messages distinguish "missing
  subsystem" from "subsystem declared but its prerequisite is unmet".
- **`GetCapabilities` tool** — returns the active manifest plus
  DDEV/local-mode runtime detection. Always callable; intended as the
  first call of an MCP session.
- **`GetPreviewUrl` tool** — builds a signed workspace preview URL for a
  page or content element so editors can verify changes outside the
  chat.
- **`RenderRecord` tool** — fetches the rendered frontend HTML for a
  page through the workspace preview URL. Closes the verification loop
  for an LLM editor; outbound HTTP is gated by the manifest, redirects
  are not followed, TLS is verified outside local mode.
- **`LocalModeService`** — single source of truth for "DDEV / local
  development". Auto-detects via `IS_DDEV_PROJECT`, `DDEV_PROJECT`,
  `DDEV_HOSTNAME`, `DDEV_TLD`, and the TYPO3 application context.
  Surfaced via the `localUnsafeMode` extension setting (`auto`/`on`/`off`,
  default `auto`).
- **Workspace and review tools** — `ListWorkspaces`, `WorkspaceReview`,
  `PublishWorkspace`, and `RollbackWorkspace` support the draft-review-publish
  loop. Publish and rollback remain dry-run by default.
- **Record workflow tools** — `BulkWrite`, `CopyContent`, `AttachImage`,
  `ImportContent`, `ImportFromUrl`, `ContentAudit`, `ManageRedirects`,
  `CreateSite`, and `SiteSet` extend the editor-facing surface while retaining
  DataHandler/TCA/workspace behavior where TYPO3 supports it.
- **File and media tools** — sandbox-scoped `BrowseFiles`, `ReadFileMetadata`,
  `WriteFile`, `UploadFile`, and `UploadFileFromUrl`; FAL-wide read tools
  `ListStorages`, `BrowseFolder`, `SearchFile`, and `SearchMedia`.
- **Admin, optional, and dev-site tools** — `InstallExtension`, `SafeCli`,
  `ApplyShadcnPreset`, optional x402 payment tools, and dev-site-only
  `SiteSettings`, `ListViewHelpers`, `GetViewHelperDocumentation`, and
  `CreateLocallang`.
- **MCP TCA resources** — dev-site-only resources `typo3-mcp://tca` and
  `typo3-mcp://tca/{tableName}` expose permission-filtered TCA context to
  clients that support MCP resources.
- **Editor workflow skills installer** — `mcp:install-editor-skills` installs
  the bundled `typo3-content-edit` and `typo3-translate-page` skills into
  `.claude/skills/`.
- **CLI mirror** — every MCP tool is now also a Symfony console command
  (`vendor/bin/typo3 mcp:<tool>`) with `--json` / `--plain` /
  `--no-ansi` output modes, file params via `--param key=@file.json`
  (constrained to project root), and a generic `mcp:tool <Name>` runner.
  `mcp:tool:list` discovers what's registered.
- **Backend module UI** — expanded client setup, token management, endpoint
  diagnostics, and XLIFF 2 ICU labels with German translations.
- **Documentation/manual** — README, technical overview, TYPO3 RST manual,
  troubleshooting, E2E documentation, Cursor testing guide, and full-feature
  chatbot test script.
- **`Documentation/Testing/CursorTesting.md`** — manual end-to-end test
  guide for the Cursor MCP client.

### Changed

- **`WorkspaceContextService::switchToWorkspace()`** accepts
  `workspace_id: 0` (live writes) only when `LocalModeService::allowsLiveWrites()`
  returns true (DDEV / `localUnsafeMode=on`). Production behavior
  unchanged.
- **`McpFileSandboxService`** bypasses the storage/folder boundary check
  when `LocalModeService::allowsUnrestrictedFileAccess()` returns true.
  Path-traversal sanitization still applies; only the
  `1:/mcp/`-jail check relaxes.
- **Table access** now supports configured read-only non-workspace tables
  (`additionalReadOnlyTables`) and configured hidden standalone tables
  (`additionalStandaloneTables`) while continuing to apply backend user
  permissions, TSconfig field restrictions, and workspace capability checks.
- **Language parameters** are exposed only when meaningful site language
  support exists. Tools accept ISO codes where possible; numeric
  `languageId` remains only as documented compatibility input on `GetPage`.
- **Tool descriptions and errors** were reshaped for MCP ergonomics:
  actionable tool errors, pagination hints, schema descriptions, and
  `tools/list` guidance for unknown tool names.

### Security

- Default `network.outbound` policy ships at `[self]` only — public-web
  uploads must be opted in per deployment. The IP-range SSRF check still
  blocks private addresses regardless. **In DDEV / `localUnsafeMode=on`
  both gates are bypassed** so workflows like "fetch this Unsplash image
  into fileadmin" work in dev without operators editing
  `Capabilities.yaml`. Production with the default `localUnsafeMode=auto`
  resolves to `off` and keeps the strict gate.
- `RenderRecord` no longer follows redirects (a single 302 to a private
  IP would have bypassed the host check).
- `RenderRecord` enforces TLS verification outside local mode.
- CLI `--param key=@file.json` is constrained to the TYPO3 project root.
- `enableMcpAuthHeaderDiagnostic` now defaults to `0`; the unauthenticated
  diagnostic is opt-in.
- `allowMcpTokenInQueryString` stays disabled by default.
- MCP request logging redacts authorization headers, cookies, and token query
  parameters.
- OAuth tokens are hashed before storage, and plaintext-token fallback was
  removed.
- Browser-defense headers are added to MCP and OAuth responses.
- `WriteTable` and `BulkWrite` reject system fields such as `t3ver_*`,
  timestamps, permission fields, `deleted`, and `uid`.
- `WriteFile` excludes SVG from the default text-file allowlist.
