# Changelog

All notable changes to this fork are documented here. The fork tracks
[hauptsacheNet/typo3-mcp-server](https://github.com/hauptsacheNet/typo3-mcp-server)
upstream and adds the items below.

The project follows [Keep a Changelog](https://keepachangelog.com/) and
SemVer once it leaves the experimental surface.

## 0.9.3 - 2026-09-23

### Fixed

- **Every tool sees the `/mcp` request as a backend request.** 0.9.2 did
  this for the write tools only. A new parity test reads a workspace draft
  of a file reference on a nested inline child and a draft of a file's
  metadata over the HTTP middleware and over the stdio handler: ReadTable,
  GetPage, Search, SearchMedia and SearchFile answered the same (apart from
  absolute URLs that carry the HTTP host), ReadFileMetadata did not. Over
  HTTP TYPO3's frontend-only metadata overlay showed the workspace draft,
  over the CLI and stdio the live metadata. Now core file APIs behave as
  for the backend user on every transport, and the endpoint gets its own
  request back after each tool.
- ReadFileMetadata overlays a file's metadata with the user's workspace
  itself, so the draft is read on every transport, as ReadTable does.
- While the tools see a backend request, image processing stays immediate:
  the backend would defer it to a later request, and SearchFile reads its
  thumbnails in the same call.

## 0.9.2 - 2026-09-23

### Fixed

- **Rich text with `t3://` links over the CLI and stdio transports.** With
  `security.backend.htmlSanitizeRte` enabled, DataHandler's RTE sanitizer
  needs a PSR-7 request, and the `mcp:*` commands and `mcp:server` had none:
  WriteTable, BulkWrite, ImportContent, ImportFromUrl and CopyContent (with
  overrides) failed with "Operation failed". These tools now publish a
  request for the site of the written record while they run: the site
  `SiteFinder::getSiteByPageId()` finds for the record's page (the target
  page on create, the stored pid on update, the page itself for `pages`),
  on the base of that site's default language. A record on a page outside
  every site falls back to the first site, and the result says so in
  `siteContext`. The request is removed after the call, so the stdio server
  never carries one call's site into the next (`SiteRequestContext`,
  `SiteRequestAwareToolInterface`).
- Over HTTP the same tools saw the `/mcp` endpoint's frontend-stack request
  since 0.9.0, which switched FileRepository and the storages to frontend
  behaviour in the middle of a backend-user write: updating a live file
  reference of a nested inline child in a workspace failed. The tools now
  see that request as a backend request; the endpoint gets its own request
  back afterwards.

### Changed

- **FlexForm updates merge.** An update used to replace every stored
  setting with the ones sent. Now fields that are sent are set, fields that
  are not sent keep their value and sheet, and a field sent as `null` is
  removed (a `null` group such as `{"settings": {"media": null}}` removes
  every field below it). A value an older write stored in the wrong sheet
  moves to the sheet its DataStructure declares (#131). A FlexForm XML
  string still replaces the whole value. Documented in the WriteTable tool
  description and the manual.

### Removed

- `ext_emconf.php`, also in the two test fixture extensions. The fork is
  distributed through Composer and Git only, and TYPO3 v14 reads the
  metadata from `composer.json`: `extra.typo3/cms.version` and an empty
  `extra.typo3/cms.Package.providesPackages` (deprecation #108345), the
  title from the `"MCP Server - …"` description. The former `beta` state
  label is gone; the 0.x version says the same.

## 0.9.1 - 2026-09-23

### Fixed

- The E2E test "copy elements exist" matched the hidden "Copy URL of this
  record" element of the docheader's shortcut dropdown first and failed; it is
  scoped to the module's setup tab now. No change to the extension itself.

## 0.9.0 - 2026-09-23

A rebuilt backend module, the relevant open upstream fixes, PHPStan level 8
over every line of own PHP and current dependencies. Upstream `main`
(`74e8188`) is still fully merged; it has no newer commits. The public PHP
API other extensions use (`ToolRegistry`, `McpToolCatalogService`,
`CapabilityManifestService`, `ToolResultNormalizer`, `ToolInterface`,
`AbstractTool::isAdminOnly()`, `CompatibleToolAdapter`, `#[AdminOnly]`) is
unchanged.

### Changed

- **Backend module rebuilt from TYPO3 v14 core patterns.** Module layout
  with a docheader "Create access token" action, reload and shortcut; core
  tabs for *Connect a client*, *Access tokens*, *Connection check* and a new
  *Tools* tab (every tool a client is offered, filterable, badged read-only /
  changes data / administrators only / development only); client setup as
  collapsible panels with `<typo3-copy-to-clipboard>`; infobox empty states;
  `table-fit` tables with captions and scoped headers. The token list and
  the connection check are Fluid partials (`McpModulePartialRenderer`,
  replacing `McpDiagnosticsPanelRenderer`) that the AJAX actions re-render,
  so the JavaScript builds no markup. The JavaScript uses core modules only
  and reads its labels from the v14 `~labels/mcp_server.mod` module; async
  results are announced in a live region. The extension stylesheet is gone.
  The AJAX route names and payload fields are unchanged (`getUserTokens`
  adds `count` and `html`, `runDiagnostics` adds `overallStatus`).
- Labels are XLIFF 2.0 with 2-space indentation, English and German for
  every string; unused labels are gone; the module registration uses the v14
  keys in `Resources/Private/Language/Modules/mcp_server.xlf`; every
  extension setting and option label is translated and split into title and
  description.
- PHP 8.4 idioms (Rector `UP_TO_PHP_84`, reviewed): typed class constants,
  `new Foo()->bar()`, `??=`, `array_any()`/`array_all()`/`array_find()` with
  typed callbacks.
- PHPStan level 8 analyses all own PHP — `Classes`, `Configuration`, every
  test suite including the fixture extensions and the LLM tests, `Build/`
  scripts, `ext_emconf.php` and the tool configurations — without a
  baseline. Tests that could not fail now assert something; the documented
  success idiom is `json_encode($result->jsonSerialize(),
  JSON_THROW_ON_ERROR)`.
- Dependencies: PHPUnit 12.5 → 13.3 (the platform floor moves to PHP 8.4.1
  for it), paratest 7.20 → 7.24, `typo3/coding-standards` dev-main → 0.9,
  php-cs-fixer 3.95.27, Rector 2.6.7, `webconsulting/typo3-abilities` ^1.0
  → ^1.2 (locked 1.3.0); Playwright 1.52 → 1.63 on Node.js 24; CI actions
  checkout 7.0.1, setup-node 7.0.0, upload-artifact 7.0.1, setup-php 2.37.2.

### Added

- Tests: the module renders (four tabs, the token partial through its AJAX
  action, German), every label the templates, the JavaScript and the
  controller reference exists in English and German, every key the
  connection check can emit is translated, and regression tests for each
  adopted upstream fix.
- `.gitattributes` keeps development files out of the Composer dist archive.

### Fixed

- WriteTable stores FlexForm values in the sheet their DataStructure
  declares and keeps dotted field names such as `settings.media.maxWidth`
  (adapted from upstream #131).
- WriteTable over HTTP can save rich text with `t3://` links while
  `security.backend.htmlSanitizeRte` is enabled: `/mcp` and `/mcp_upload`
  publish `$GLOBALS['TYPO3_REQUEST']` (adapted from upstream #129).
- Workspace deletes: repeating a delete in WriteTable or BulkWrite no longer
  restores the record (adapted from upstream #68), and deleting a record
  that has a workspace draft deletes it instead of discarding the draft.
- ReadTable rejects filter values its operator cannot bind (arrays for
  scalar operators, empty or nested `in` lists) instead of silently
  returning nothing (adapted from upstream #29).
- Browser clients can read the `WWW-Authenticate` challenge of a 401
  (adapted from upstream branch `claude/stoic-brahmagupta-cx7skz`).
- The connection check showed an empty fix hint for a disabled
  Authorization-header probe; the GetPageTree description missed a space.

### Removed

- `Resources/Public/Css/mcp-module.css`, `McpDiagnosticsPanelRenderer`, the
  empty `ext_localconf.php`, `mcp_setup.png` and the unreferenced
  `Build/Examples/sites` demo; `clearCacheOnLoad` in `ext_emconf.php`.

### Upstream pull requests reviewed and not adopted

- #76 (delete cascade warnings): does not reproduce on TYPO3 14.3.7.
- #99 (WriteTable description): says the tool cannot publish, which is
  wrong for this fork.
- `feature/compassionate-lamport-gg83nx` (field-name suggestions): a
  feature, not a fix.
- #20 and `claude/stoic-brahmagupta-t8h49s` were already covered; #20's
  tests were added.

## 0.8.0 - 2026-09-18

Behaviour-preserving overhaul of the fork-only code paths, static analysis at
level 8 without a baseline, and a rewritten manual. Upstream `main` (`74e8188`)
was already merged; no new upstream commits existed at release time.

### Changed

- **One upload write path.** `FileUploadService::storeUpload()` validates the
  name, resolves storage and folder, reserves the randomized name, stores the
  file, indexes image metadata and applies `sys_file_metadata` for
  `UploadFile`, `UploadFileFromUrl` and `/mcp_upload` alike. The service also
  owns `describeUpload()`, `sanitizeMetadata()`, `applyMetadata()`,
  `withTemporaryFile()` and the size-limited `bufferStreamToFile()`, which
  replaced three copies of the chunked download/PUT loop. `WriteFileTool` and
  `AttachImageTool` reuse `resolveStorage()`/`ensureFolder()`.
- **Tool attributes live on the tool.** `AbstractTool::isAdminOnly()` /
  `isDevSiteOnly()` read `#[AdminOnly]` / `#[DevSiteOnly]`;
  `CompatibleToolAdapter` resolves them on the wrapped class, so third-party
  tools carrying the attributes are now gated at execution time too.
  `ToolRegistry` stores `AbstractTool` instances and filters dev-site tools
  through that method; `DevSiteToolService` no longer reads the adapter's
  private property by reflection.
- `AbstractTool::createJsonResult()` (invalid UTF-8 substituted, compact JSON)
  replaces private JSON helpers in `AbstractRecordTool`, `SolrIndexQueue` and
  the x402 tools. The x402 tools and `SolrIndexQueue` therefore return compact
  instead of pretty-printed JSON.
- `SolrIndexQueue` discovers tasks from `tx_scheduler_task` only. It no longer
  spawns `scheduler:list` (60 s timeout) or parses its text output; `list`
  results drop the `schedulerList` block and the tool fails closed with a hint
  when the scheduler table is missing.
- `CapabilityManifestService` reads the bundled manifest from its fixed path
  (the `typo3conf/ext` fallback of a TER install this fork never had is gone)
  and takes tool/prerequisite policy from `x-mcp` only; the top-level
  `capabilities.tools` / `capabilities.requires` fallbacks were removed.
- `BackendUserUtility::getUserId()` replaces twenty ad-hoc
  `$GLOBALS['BE_USER']->user['uid']` extractions in the fork's code paths.
- `WriteTableTool` uses its injected event dispatcher, `RecordFieldReadConverter`
  receives `FlexFormService` through DI, `RecordSearchExecutor` uses Doctrine's
  non-deprecated column introspection.
- PHPStan runs at level 8 on `Classes`, `Tests/Unit` and `Tests/Architecture`
  with `phpstan-typo3`, `phpstan-phpunit`, `phpstan-deprecation-rules` and
  `phpat`; the 745-entry baseline and `phpstan-strict-rules` are gone.

### Added

- Adapted fixes from open upstream pull requests: file fields on nested inline
  children resolve through the shared DataHandler map (replace, update,
  delete, deeper nesting); shared inline child tables are scoped by their
  owning table on read and write; field visibility uses the record's actual
  page; page moves walk the staged ancestry and reject cycles before any
  change; a failing online media helper no longer blocks the remaining
  helpers (warnings carry only the extension and exception class); the idle
  HTTP session timeout is configurable through `sessionTimeout` (default
  four hours).
- `UploadTooLargeException` (a `ValidationException`) for payloads over
  `maxFileSizeMb`; the upload endpoint maps it to HTTP 413.
- Tests for the attribute helpers on adapted tools, dev-site filtering in the
  registry, the missing-manifest and `x-mcp`-only policy cases, and the
  fail-closed `SolrIndexQueue` list.

### Removed

- `mcp:test` (`McpTestCommand`) duplicated `mcp:tool <Name>`.
- Unused `TableAccessService` TSconfig helpers, `GetFlexFormSchemaTool::getAvailableFlexForms()`,
  the `ListTablesTool` `ConnectionPool` and `WriteTableTool`
  `FileMetadataIndexService` dependencies, and the undefined `$result` branch
  in `GetFlexFormSchemaTool` (now an explicit exception).
- `.github/workflows/claude.yml`, `Documentation/Reviews/*.md`,
  `Documentation/Changelog/Modernization2026.rst`, the committed
  `.claude/skills` and `.agents/skills` copies of `Resources/Private/Skills`.

### Documentation

- `README.md` is a 110-line overview; the manual gained `Usage/Index.rst`
  (example session, tool families, files, translations, CLI) and
  `Developer/Index.rst` (setup, layout, upstream sync, releasing).
  `Testing/CursorTesting.md` and `Testing/FullFeatureChatbotScript.md` are
  now reStructuredText pages; `TECHNICAL_OVERVIEW.md` and `CLAUDE.md` point
  at `Documentation/` and `AGENTS.md`. Stale PHP 8.3 statements were corrected
  to the required PHP 8.4.

## 0.7.2 - 2026-09-13

### Fixed

- Register the `mcp` ability category. All five catalog abilities named it, but
  nothing declared it, so the registry logged five warnings on every boot and
  the category was missing from the REST and backend listings that group by it.

## 0.7.1 - 2026-09-12

### Changed

- Require `webconsulting/typo3-abilities` `^1.0` now that the abilities registry is released;
  the 0.7.0 requirement on `dev-main` is gone.

## 0.7.0 - 2026-09-12

Upstream `v0.6.2` is merged into this fork; the entries previously listed as
unreleased ship with this version and are folded in below.

### Added

- **Abilities are MCP tools again, through the new `McpProjection` API.**
  `webconsulting/typo3-abilities` 1.0 removed `Projection\Mcp\AbilityMcpTool`
  and the compiler pass that tagged one `mcp.tool` service per ability; the
  registry now exposes the protocol-neutral
  `Projection\Mcp\McpProjection` instead, and this extension owns the bridge:
  - `Integration\Abilities\AbilityToolBridge` returns one
    `Integration\Abilities\AbilityTool` per `McpProjection::descriptors()`
    entry, so ability `system/site-info` is the MCP tool
    `ability_system_site-info`. Descriptor name, description, input schema and
    annotations become the tool schema; `AbilityResult::toArray()` is returned
    as JSON text content with `isError` mirroring a failed result.
  - Execution uses `ExecutionContext::mcp($backendUserUid)` for the
    authenticated backend user. MCP is a trusted abilities surface, so scope
    checks are skipped while the abilities policy, the ability's own permission
    check, schema validation and traces still run. No backend user, no
    execution.
  - `MCP\ToolRegistry` gained a lazy `mcp.tool_provider` tag
    (`MCP\Tool\ToolProviderInterface`), resolved on first catalog access
    rather than in the constructor — this extension's catalog abilities read
    the registry that lists them, which would otherwise be circular.
  - The four read-only `typo3-mcp/*` catalog abilities are exposed to `mcp`
    again. `typo3-mcp/execute-tool` deliberately is not: projecting it into the
    catalog it executes would duplicate every native tool.
- **Manifest policy for bridged tools.** Ability tools are gated by the side
  effects they declare in the registry, mapped onto the manifest's own
  subsystem vocabulary (`CapabilityManifestService::assertAbilityToolAllowed()`).
  Read-only abilities need no subsystem, `network:outbound` requires a
  `network.outbound` host rule, and an explicit `x-mcp.tools` /
  `x-mcp.external_tools` entry pins a stricter requirement. The new
  `x-mcp.integrations.abilities.mcp_bridge` flag (default on) removes every
  bridged ability when disabled.
- Unit and functional coverage for the bridge, plus `composer test:protocol`
  assertions that count native and bridged tools separately, require the seven
  bridged tools, refuse `ability_typo3-mcp_execute-tool`, and call
  `ability_system_site-info` over the wire.

### Removed

- **The `sg_apicore` integration.** `Classes/Integration/ApiCore/*`
  (`AbilitiesApiPolicyEnforcer`, `AbilitiesApiPolicyMiddleware`,
  `AbilitiesOpenApiAugmenter`), their unit tests, the conditional
  `ext_localconf.php` and `Configuration/RequestMiddlewares.php` wiring, the
  manifest integration entry, and `Documentation/Integration/SgApiCore.rst` are
  gone. The REST projection of the abilities registry now ships natively with
  `webconsulting/typo3-abilities` at `/abilities/v1`, and the lab moves to the
  upstream `sgalinski/sg-apicore` 3.1 line without this fork's abilities
  controller.
- `capabilities.x-mcp.external_tools` no longer needs an
  `ability_system_site-info` entry and ships empty; it remains available to pin
  requirements for third-party `mcp.tool` services and bridged abilities.

### Changed

- `MCP\Tool\AbstractTool` gained the `assertAllowedByManifest()` hook so
  bridged tools can carry their own requirement metadata while the fail-closed
  manifest-service lookup stays shared.
- `Integration\Abilities\AbstractMcpAbility` no longer re-hydrates the backend
  user on the MCP surface; the endpoint or CLI bootstrap already did, and
  repeating it would reset the session's read-workspace selection.

### Requirements

- Removed `sgalinski/sg-apicore` from `require` and its Composer repository
  entry.
- `webconsulting/typo3-abilities` stays on `dev-main` for now.
  <!-- TODO: switch to "^1.0" once the 1.0.0 tag is published. -->

### Also in this release

#### Changed

- Share backend-user initialization across MCP HTTP, CLI, and Abilities, removing
  duplicated permission, preference, language, and workspace setup.

- Verified TYPO3 14.3.6 remains the latest available TYPO3 14 release; all Core
  packages stay on that patch. Require stable `logiscape/mcp-sdk-php` 2.x,
  retaining the installed 2.0.1 release.
- Replace 18 constant-only CLI wrapper classes with existing generic command
  registrations, preserving command names and options.
- Use TYPO3 JSON responses for HTTP errors and diagnostics. Simplify developer
  tool traversal and skip unnecessary event scans and TypoScript setup builds.
- Consolidate duplicated documentation, remove the superseded local cleanup
  report, and document current protocol support and testing limits.

#### Fixed

- Benchmark commands reject unreadable baselines and non-finite token ratios
  before executing any response probes.

- `LastError` selects the newest error by entry timestamp across bounded log
  tails and requires an administrator, including in development mode.
- Benchmark probes validate the entire set before execution, require declared
  read-only tools, and reject duplicate tool names. Failed probes return a
  failure exit status; budget overages remain report-only.
- A missing dev-site guard service now fails the tool call instead of bypassing
  the guard.

#### Added

- Cache-backed authentication failure limits for `/mcp` and `/mcp_oauth/token`,
  using TYPO3's rate limiter with independent per-IP budgets and HTTP 429 /
  `Retry-After` responses. Defaults: 20 failures per 15 minutes per endpoint.
- `GetCapabilities` now summarizes the connected user's identity, current
  workspace, page mounts, and common table permissions through existing guards.
  It does not change workspace context or disclose credentials.

These features take inspiration from in2mcp's rate-limiting and caller-context
ideas. They use this extension's architecture and `logiscape/mcp-sdk-php`;
in2mcp and `mcp/sdk` are not dependencies.

## 0.6.2 - 2026-08-28

### Fixed

- Force execution of the explicitly selected EXT:solr scheduler task so
  `SolrIndexQueue` can process more than one batch in a single `runs` request.
  The task UID remains validated as Solr-related and no unrelated due tasks are
  executed.

## 0.5.1 - 2026-06-15

### Changed

- **Declared TYPO3 support floor raised to `^14.3`** across `composer.json`
  (runtime and dev `typo3/cms-*` requirements), `ext_emconf.php`
  constraints, the CI matrix, the README, and `ForkChanges.rst`. The
  previous `^14.0` always resolved to the latest 14.x, so the fork was only
  ever installed and tested on 14.3 — this makes the declared floor match
  the tested reality and aligns it with the rest of the stack. No runtime
  behaviour change.

## 0.5.0 - 2026-06-15

_The fork version skips from 0.3.0 to 0.5.0: the `0.4.x` tag namespace is
held by the upstream `hauptsacheNet/typo3-mcp-server` releases this fork is
built on, so the fork's own line jumps past it to avoid colliding tags._

### Added

- **Per-site editor access on `CreateSite`.** When the `CreateSite` MCP tool
  (and the `mcp:create-site` CLI) add a website, they now provision a
  dedicated backend editor group "Editors: &lt;root page title&gt;" mounted at
  the new root, with content-editing permissions (pages/tt_content tables,
  editor pagetypes, Page + List modules, and an `explicit_allowdeny`
  allow-list covering every tt_content CType — required because CType uses
  authMode). The group becomes the non-destructive owner of the root page, and
  named `editors` can be added to it, so non-admins can edit the new site
  without granting access to every existing editor team. New
  `SiteEditorGroupService` owns this logic.
- Page-tree-restricted workspaces are extended to cover the new root for
  staging (`WorkspaceContextService`); unrestricted workspaces are left
  untouched. The `create` response reports all of this under `access`. Tests,
  README, and docs were updated alongside.

## 0.3.0 - 2026-06-08

### Changed

- **Lean `tools/list` by default (context-window optimization).** The tool
  catalog is injected into the model's context on every MCP session, so long
  tool/field descriptions were a fixed token cost paid per conversation
  (~17k tokens for the 44 bundled tools). A new `ToolSchemaOptimizer` now
  condenses verbose descriptions down to their leading sentences while
  preserving critical gotchas (`REQUIRED`, `MUST`, `REPLACES`, …), trimming the
  `tools/list` payload by roughly a third. Structural JSON Schema keywords
  (types, enums, `required`, …) are never touched. Controlled by the new
  `schemaDetail` extension setting (`concise` default, `full` restores the old
  verbatim output). The complete, untrimmed schema of any tool stays available
  on demand — see below.
- **Local development default workspace (major behaviour change).** On DDEV /
  trusted local mode, record tools now default to the **live workspace** when
  `workspace_id` is omitted — AI edits update the published local copy
  immediately instead of auto-creating a draft workspace. **Production is
  unchanged** (draft-first). Per-user opt-out via User TSconfig
  `options.mcpServer.localUnsafeMode = off`. Production override (opt-in
  DDEV-like live chatbot edits): see
  `Documentation/Configuration/LiveEditsOnDevelopment.rst`.

### Added

- **`GetCapabilities` full-schema lookup** — pass `{"tool": "WriteTable"}` to
  retrieve a single tool's full, untrimmed schema/description on demand. This
  is the recovery path for the concise `tools/list` default: nothing is lost,
  detail is just fetched only when the model actually needs it.
- **`schemaDetail` extension setting** (`concise` default | `full`) controls
  `tools/list` verbosity for context-window budgeting.
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
