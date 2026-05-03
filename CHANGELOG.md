# Changelog

All notable changes to this fork are documented here. The fork tracks
[hauptsacheNet/typo3-mcp-server](https://github.com/hauptsacheNet/typo3-mcp-server)
upstream and adds the items below.

The project follows [Keep a Changelog](https://keepachangelog.com/) and
SemVer once it leaves the experimental surface.

## Unreleased

### Added

- **Capability manifest** (`Configuration/Capabilities.yaml`) declares
  every MCP tool's required subsystems and an outbound-network policy.
  Enforced at runtime via `CapabilityManifestService` inside
  `AbstractTool::execute()` and the network paths of `UploadFileFromUrl`
  and `RenderRecord`. Default `network.outbound: [self]` ships closed —
  operators opt in to public web per deployment.
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
- **CLI mirror** — every MCP tool is now also a Symfony console command
  (`vendor/bin/typo3 mcp:<tool>`) with `--json` / `--plain` /
  `--no-ansi` output modes, file params via `--param key=@file.json`
  (constrained to project root), and a generic `mcp:tool <Name>` runner.
  `mcp:tool:list` discovers what's registered.
- **`typo3-mcp-cli` claude-code skill** — recipe for adding a new
  per-tool CLI shortcut in 30 seconds.
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

### Security

- Default `network.outbound` policy ships at `[self]` only — public-web
  uploads must be opted in per deployment. The IP-range SSRF check still
  blocks private addresses regardless.
- `RenderRecord` no longer follows redirects (a single 302 to a private
  IP would have bypassed the host check).
- `RenderRecord` enforces TLS verification outside local mode.
- CLI `--param key=@file.json` is constrained to the TYPO3 project root.
