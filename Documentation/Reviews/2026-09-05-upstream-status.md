# Upstream integration status

## 2026-09-12 update: upstream `main` (v0.6.2 line) merged

`upstream/main` `74e8188` was merged into the fork (branch
`merge/upstream-v0.6.2-20260912`). Resolution followed the rule below: every
fork implementation listed under "Behavior already present" was kept, and only
missing behavior was adapted:

- **Upload workflow**: kept the sandboxed `UploadFile` / `UploadFileFromUrl`
  tools and folded upstream's hardening into the new `FileUploadService`
  (executable/server-config refusal, cross-folder deduplication incl. the
  rewritten-content re-check, HTML rejection, `maxFileSizeMb`). The pre-signed
  flow (`UploadFile` without payload → `/mcp_upload`, `FileUploadEndpoint`,
  `tx_mcpserver_upload_tokens`) and YouTube/Vimeo online media were ported onto
  the fork's DI, sandbox, rate limiter, and `BackendUserContextService`.
- **Static-token CLI creation**: `mcp:oauth create` ported onto
  `createDirectAccessToken()` (hashed token, resource binding, `--ttl-days`).
- **Shared backend impersonation**: not imported; the fork's
  `BackendUserContextService` already consolidates HTTP, CLI, Abilities and
  now the upload endpoint. Upstream's uc-preservation and auth endpoint tests
  were adapted to it (`McpEndpointUcPreservationTest`); the stateless/legacy
  wire tests are covered by `McpEndpointProtocolTest`.
- **OAuth**: upstream's confidential-client schema (`client_secret`,
  `client_uid`, seeded well-known client wizard, loopback redirect wildcards)
  was not taken; the fork keeps public-client DCR with PKCE and refresh tokens.
- **Not re-added**: `Build/deploy-classic.sh`, `Build/build-ter.sh`, the
  bundled `Resources/Private/PHP` SDK, `b13/container` dev dependency and its
  container select-item test, `^13.4` TYPO3 constraints.

Checked on 2026-09-05 against freshly fetched remotes:

- Fork `origin/main`: `f4aca8a`; included in the local branch.
- Original `upstream/main`: `74e8188` (2026-08-31).
- Local implementation: `d63f4ed`, plus the preserved developer-tool work.

The fork is current with its own GitHub main branch. It is **not a complete
merge of the original upstream main branch**. Several upstream fixes already
exist here as adapted implementations with different commit IDs. `git cherry`
therefore cannot determine behavioral coverage on its own.

## Behavior already present

| Upstream change | Current fork implementation and evidence |
| --- | --- |
| Record context for select items (`9b8800f`) | Adapted in `418fd7e`; `SelectItemResolver` passes scalar record values into Core form-data processing and keys its cache by those values. `SelectItemResolverRecordContextTest` covers callbacks, validation, and cache isolation. |
| Allow language control fields (`6830569`) | Adapted in `483e8c9`; retained in current `WriteTableTool` validation, with language tests. |
| Apply translated values after localization (`b983469`, `a8aec48`) | Current `WriteTableTool::translateRecord()` applies values through the existing update path, with additional child/hidden handling and cleanup. `WriteTableLanguageTest` and `TranslationHardeningTest` cover the current contract. Do not replace this method with upstream's smaller implementation. |
| Subdirectory HTTP/OAuth routing (`2cdc3f3`) | Current `SiteBaseUrlResolver` and middleware handle application paths and discovery paths. The fork's `90c4dba` release and `SubdirectoryRoutingTest` cover this adaptation. |
| Dynamic client registration and CORS (`7c31d09`, `077c424`) | `OAuthRegisterEndpoint`, `OAuthService`, and the shared CORS trait already implement these features with this fork's exact-origin and OAuth validation rules. HTTP tests cover preflights and rejected origins. |
| Preserve backend user configuration (`3a375ae`) | `McpEndpoint` hydrates stored user configuration; `McpEndpointSecurityTest` verifies both effective settings and unchanged stored `uc`. The shared impersonation refactor in `ce22135` has not been imported wholesale. |
| SDK v2, PSR-7 transport, tools/list result (`29b1bfc`, `c8a53da`, `e1d37d2`) | Existing `logiscape/mcp-sdk-php` 2.0.1, the endpoint's PSR-7 adapter, and `ListToolsResult` in `McpServerFactory` cover these behaviors. Protocol and HTTP tests exercise the fork's supported protocol modes. |
| Credential-safe logging and server-side exception reporting (`da1018a`, `01a5f4c`, `6662947`) | `McpHttpLogRedactor` and the injected TYPO3 logger preserve diagnostics without token prefixes or unredacted credentials. The fork uses TYPO3 logging configuration rather than importing upstream's `error_log` helpers. |

The security release `90c4dba` and repeated Solr queue fix `f4aca8a` were brought
in from **the fork's origin**, not newly ported from the original upstream during
the cleanup. They are already part of the tested local baseline.

## Not imported, or requiring a separate adaptation

- **Upstream's unified upload workflow** (`f29a3a0`, `d9e990e` and follow-up
  upload commits): this fork retains separate sandboxed `UploadFile`,
  `UploadFileFromUrl`, and file-write tools. The upstream pre-signed HTTP upload
  endpoint, online-media flow, and rewritten-content deduplication are not
  included. Any future port must preserve sandbox, FAL permissions, outbound
  host checks, file-type validation, and metadata/workspace behavior. Matching
  tool names do not imply matching implementations.
- **Static-token CLI creation** (`ba4b5c8`): `mcp:oauth` currently provides URL,
  list, and revoke actions; upstream's `create` action is absent. A future port
  must use the fork's hashed-token, resource, and scope model.
- **Shared backend impersonation** (`ce22135`): a possible cleanup, but requires
  preserving existing HTTP and Abilities user-context tests and behavior.
- **Non-Composer SDK bundle** (`b9b6569`): not applicable. This fork distributes
  through Composer/Git and removed the obsolete TER dependency bundle.
- **Upstream upload documentation, deployment scripts, and LLM scenarios**:
  do not copy these while their corresponding workflow is absent or different.

This is a behavior-based status review, not a claim that every upstream line or
test has been imported. No upstream production-code patch was applied during
this README/status update.

## Verification and integration rule

The preceding implementation run passed 118 unit tests and 1,034 functional
tests; the subsequently added trusted-proxy regression also passed in the
six-test limiter class. PHPStan, formatting, protocol smoke, and documentation
checks passed. These checks establish the current tested baseline, not a
guarantee against every possible regression. Rector's remaining suggestion is
in the pre-existing, uncommitted developer-introspection test.

For further upstream work, compare each patch against current services first,
adapt only missing behavior, and add focused regression coverage. Preserve
workspace selection, access control, capability enforcement, local-mode policy,
and file/HTTP safeguards. Never replace an entire fork file merely to make its
diff match upstream.
