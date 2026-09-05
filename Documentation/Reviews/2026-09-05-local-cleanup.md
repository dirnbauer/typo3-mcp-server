# Local implementation and GitHub cleanup review

Reviewed on 2026-09-05. This first pass prioritizes the local checkout and
`dirnbauer/typo3-mcp-server`, as requested. Upstream and in2mcp integration
decisions are deferred until the local baseline is settled.

## Baseline and preservation

- Original local `main`: `8402ea7`, with 10 modified tracked files and 16
  untracked files. The pending changes form a coherent developer-introspection
  feature plus a tool-context benchmark; they are not abandoned fragments.
- GitHub `origin/main`: [`f4aca8a`](https://github.com/dirnbauer/typo3-mcp-server/commit/f4aca8ae62022414780630b32d9df090d5d90847).
  Local was two commits behind: security release `90c4dba` and the repeatable
  Solr queue fix `f4aca8a`. [GitHub CI passed](https://github.com/dirnbauer/typo3-mcp-server/actions/runs/33149318440).
- Created local branch `codex/local-cleanup-20260905`, fast-forwarded it to
  `origin/main`, and restored the pending work without conflicts. Original
  `main` and the existing security worktree were not rewritten.
- Recovery stash retained: `30e2be3967fd68af1b10548c40530f13513a8961`.
  All 16 originally untracked files were verified byte-for-byte unchanged.
- Installed the existing GitHub lock file using `composer install --no-scripts`.
  This now tests TYPO3 14.3.6 and MCP SDK 2.0.1. Scripts were intentionally
  skipped because `Build/setup-typo3.sh` runs forced installation setup.

Here, **used** means registered, reachable, or referenced by code, documentation,
tests, or CI. This is not evidence of production invocation frequency; no
production usage telemetry was examined.

## Used: retain

| Component | Evidence and decision |
| --- | --- |
| Native editorial and operational tools | Runtime discovery reports all 52 native tools in the local development environment. Keep the workspace, permission, language, FAL, and capability services they use. |
| Seven new developer tools | All are registered via the tool interface, declared in `Capabilities.yaml`, mirrored by CLI services, and covered by registry/functional tests. Keep as pending work; see readiness findings below. |
| Tool benchmark and three new services | The command calls `ToolContextBenchmarkService`; `LastError` uses `DeveloperLogReader` and `DeveloperLogEntryParser`. None is dead code. |
| `Configuration/Services.yaml` and `Services.php` | These provide actual v14 service registration. YAML handles native tools/commands; PHP handles integration registration and the optional x402 verifier. Both are needed. |
| Abilities and `sg_apicore` | They are production Composer requirements, not unused optional dependencies. REST exposure is opt-in, but the Abilities registry/projections are active. The local catalog contains `ability_system_site-info` in addition to the 52 native tools. |
| x402 integration and null verifier | Conditional integration is intentional. The null implementation preserves a safe result when the optional extension is missing; absence on this instance does not make it dead code. |
| File enrichment listener and ability classes | Attribute registration makes them reachable even without a direct class-name reference elsewhere. Do not remove them using a text-reference count. |
| Per-tool CLI wrappers and generic dispatcher | They are public entry points. All 62 `mcp:*` commands remain discoverable through the native text command listing after cleanup. |
| `mcp:test` | Still registered and documented. It overlaps `mcp:tool`, but is not unused. A later consolidation should retain a compatible alias or update callers/documentation deliberately. |
| Build/test automation | `setup-typo3.sh`, `runTests.sh`, protocol smoke, Playwright, PHPStan x402 stubs, and LLM result/statistics helpers still have consumers in Composer, configuration, or CI. Retain. |

## Unused or obsolete: removed in this pass

1. **`Configuration/Commands.php`:** obsolete duplicate map. Installed TYPO3
   v14's command registry is populated by DI tags; the map is not read. Removed
   its README inventory entry. `Services.yaml` remains the registration source.
   The old map's `schedulable: false` values were also ineffective; audit desired
   scheduler exposure separately instead of assuming those values were enforced.
2. **TER bundle:** removed `Resources/Private/PHP/composer.json`, which still
   requested SDK 1.2, and the non-Composer autoloader branch in `ext_localconf.php`.
   This fork already abandoned TER distribution. Removed the related CS Fixer
   exclusion and ignore rules; no dependency bundle is built or shipped.
3. **Completed one-off scripts:** removed the four record extraction/rewiring
   scripts under `Build/Scripts/`, plus the archived XLIFF migration scripts and
   their README. They have no active callers. Several use historical source line
   numbers and would damage current code if rerun. Git history retains them.
4. **`Classes/Exception/ConfigurationException.php`:** no throw sites, catches,
   references, tests, or registrations requiring it were found. Removed the
   unused exception rather than maintaining a speculative abstraction.
5. **Duplicate ignore entries:** removed repeated `/typo3temp` and `/index.php`
   entries and renamed the build-artifact comment to reflect current usage.

No active tool, integration, user file, branch, or existing worktree was deleted.

## Pending local work: what should be used, and what needs finishing

| Addition | Recommendation |
| --- | --- |
| `ApplicationInfo` | Keep. Useful compact runtime inventory; complete Composer inventory is explicitly requested. |
| `MiddlewareStack` | Keep. Uses the resolved runtime stack and supports filtering. |
| `PageTsConfig` | Keep. Uses Core resolution and page access checks. Add non-admin/negative-input coverage, and consider sharing its duplicated path-reading helper with `TypoScript`. |
| `TypoScript` | Keep, but clarify the contract before treating it as the exact frontend result. Both compiler calls receive empty expression-matcher variables; request-, language-, and user-dependent conditions are not represented. The current functional test proves an unconditional template only. |
| `ListEvents` | Keep. Pagination bounds output, but `withListenersOnly=true` still scans event files when no listener filter is supplied. Skip that scan when registered listeners alone answer the request. |
| `ContentBlocks` | Keep as an optional integration. The checked-in functional test only covers package absence. Add a real installed-package fixture covering list, name/type lookup, and fields before claiming the integration is fully verified. |
| `LastError` | Keep pending, but harden access before broader use. It has only `DevSiteOnly`, no `AdminOnly`; a non-admin development user can retrieve installation-wide logs, including raw entries with `full=true`. Add authorization and redaction tests. Also fix newest-error selection: file mtime ordering plus early return can select an older error from a recently appended log over a newer error in another log. |
| `mcp:benchmark-tools` | Keep for schema measurements. Its probe description says read-only, but lines 88–89 dispatch any supplied tool through the catalog without a read-only restriction. It is a general executor today. Define/enforce an explicit probe policy before recommending arbitrary probes, and preflight the whole probe set before executing any. |

Other benchmark gaps: error results and budget overruns still exit successfully;
baseline response measurements are keyed only by tool name, so repeated probes
with different arguments overwrite one another. Treat it as a reporting utility,
not a CI gate, until those semantics are explicit.

## Recommended next cleanup order

1. Keep this GitHub-aligned dependency/base state. Do not reimplement the already
   shipped subdirectory routing/security fixes or Solr repeat-run change.
2. Finish the pending log/benchmark behavior and the missing developer-tool
   coverage, then commit those additions as a separate feature from dead-code
   cleanup.
3. Consolidate backend-user configuration hydration shared between
   `McpEndpoint` and `AbilityBackendUserContextService`, preserving the existing
   authentication, permission, workspace, and UC-preservation tests.
4. Audit actual `console.command` scheduler metadata and decide whether to
   consolidate `mcp:test` with `mcp:tool`.
5. Reduce default catalog cost with measured tool profiles or focused schema
   changes. The current development catalog is 77,943 bytes before description
   optimization and 54,839 bytes afterwards (29.64% saving); `CreateSite` exceeds
   the benchmark's 4,096-byte per-tool budget. These are local JSON byte counts,
   not model-token measurements or production traffic statistics.
6. Resume upstream/in2mcp evaluation against this cleaned baseline. Initial
   candidates remain shared inline relation discrimination, delete retry tests,
   authentication rate limiting, and compact user-permission introspection.
   No external implementation was imported in this cleanup.

## Verification

- Before reconciliation: 116 unit tests, 1,019 functional tests, PHPStan pass.
- After reconciliation/cleanup: 118 unit tests (937 assertions), 1,024 functional
  tests (6,208 assertions), PHPStan and PHP CS Fixer pass.
- Protocol smoke passes legacy, auto, and modern modes, including tools,
  prompts, resources, and structured content.
- Native runtime catalog preserved: no removed tools; 53 total with the installed
  Abilities projection. Native text CLI listing exposes 62 MCP commands.
- `git diff --check` passes. No production endpoint was changed or published.
- LLM tests were not run; this pass does not claim a measured improvement in
  model task success.
