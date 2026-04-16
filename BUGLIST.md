# Bug List

This file tracks the repository-wide audit started on 2026-04-14.

## Fixed


| Area                  | Finding                                                                                                                                                                                                                                                                                   | Verification                                                                                                                                                                                |
| --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GetTableSchemaTool`  | `getTypeConfig()` returned arrays that violated its declared `array<string, mixed>` contract and failed PHPStan.                                                                                                                                                                          | Covered by existing `GetTableSchemaToolTest`; PHPStan re-run after fix.                                                                                                                     |
| `AttachImageToolTest` | Functional test triggered `Undefined global variable $LANG` because workspace setup happened before language initialization.                                                                                                                                                              | Focused functional regression run including `AttachImageToolTest`.                                                                                                                          |
| PHPUnit metadata      | `AttachImageToolTest` still used docblock metadata (`@covers`), which is deprecated in PHPUnit 11 and removed in PHPUnit 12.                                                                                                                                                              | Replaced with `#[CoversClass(...)]`; full suite now runs without PHPUnit deprecation notices.                                                                                               |
| x402 tools            | `ListPaidContent` and `GetPaidContent` assumed x402 columns existed on `pages` and could fail on instances without the paywall extension.                                                                                                                                                 | New `X402ToolsTest` covers the configuration-info fallback path.                                                                                                                            |
| Redirect management   | `ManageRedirects` was always registered but degraded to a raw table-access error when `sys_redirect` was unavailable, and later overstated write support even though TYPO3 keeps `sys_redirect` outside workspaces.                                                                       | Tool now returns configuration guidance when the extension surface is missing, lists redirects when it is present, and returns an explicit workspace-safety error for create/delete writes. |
| Quality tooling       | `composer phpstan` used too little memory for this checkout and failed before reporting real findings.                                                                                                                                                                                    | Composer script updated to include `--memory-limit=1G`; PHPStan now reaches code analysis.                                                                                                  |
| Product spec drift    | README / docs did not fully match the implemented tool surface (`AttachImage`, `ImportContent` execute mode, x402 tools, `SafeCli` allowlist, optional capabilities, `ManageRedirects` query handling, `RollbackWorkspace` filter rules, `Search` limits, `WriteFile` extension support). | README, TYPO3 docs, and `TECHNICAL_OVERVIEW.md` updated; new `Documentation/Introduction/IntendedBehavior.rst` added and the tool reference was corrected again during the follow-up audit. |


## Added coverage


| Area                                   | Added test coverage                                              |
| -------------------------------------- | ---------------------------------------------------------------- |
| Workspace rollback                     | `Tests/Functional/MCP/Tool/RollbackWorkspaceToolTest.php`        |
| Extension install validation           | `Tests/Functional/MCP/Tool/InstallExtensionToolTest.php`         |
| URL import validation / SSRF guards    | `Tests/Functional/MCP/Tool/ImportFromUrlToolTest.php`            |
| Optional x402 capability handling      | `Tests/Functional/MCP/Tool/X402ToolsTest.php`                    |
| Optional redirects capability handling | `Tests/Functional/MCP/Tool/ManageRedirectsToolTest.php`          |
| Installed redirects listing and write guard | `Tests/Functional/MCP/Tool/ManageRedirectsToolHappyPathTest.php` |
| Request-aware site URL fallbacks       | `Tests/Functional/Service/SiteInformationServiceTest.php`        |


## Still environment-dependent


| Area      | Current status                                                                                                              |
| --------- | --------------------------------------------------------------------------------------------------------------------------- |
| LLM tests | Not executed in this session because `OPENROUTER_API_KEY` is not set. Deterministic unit/functional tests were run instead. |


## Current verification status

- `composer test`: passes
- `composer phpstan`: passes
- `composer docs:check`: passes

