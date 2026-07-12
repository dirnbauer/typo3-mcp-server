# Full-Feature Chatbot Test Script

A natural-language TODO list you can paste into any MCP-connected chatbot
(Claude Desktop, Cursor, n8n, Manus, MCP Inspector) that has the TYPO3 MCP
Server attached. Walking end-to-end exercises the bundled MCP surface,
including optional tools when the target instance has the required extensions
or frontend project files.

The chatbot should work through the list top-to-bottom, confirming success
after each step. This script deliberately uses one explicit draft workspace so
its record writes can be reviewed and published in Phase 9 on both strict and
local-mode installations. Site configuration, site settings, Composer changes,
XLF/frontend project files, and physical FAL file writes are not
workspace-versioned and can take effect immediately. Run this only on a
disposable staging or local instance with a clean backup or Git checkout.

> **Admin rights required** for `CreateSite`, `SiteSet`, `InstallExtension`,
> `SafeCli`, `SolrIndexQueue`, `SiteSettings`, `CreateLocallang`, and
> `ApplyShadcnPreset`. Several are also dev-site-only. Run against a disposable
> TYPO3 v14 staging/local install — never production.

---

## Phase 0 — Sanity check the server

1. Ask the chatbot to list its available MCP tools and confirm it can see
   at least: `GetCapabilities`, `GetPageTree`, `ListWorkspaces`,
   `CreateSite`, `WriteTable`, `UploadFileFromUrl`, and `PublishWorkspace`.
2. Call `GetCapabilities` and record `allows_live_writes`,
   `localMode.enabled`, outbound HTTP policy, dev-site tools, and optional x402
   availability.
3. Call `ListWorkspaces`. This call is read-only and **never creates a
   workspace**. Choose a writable draft with an ID greater than `0` and store
   it as `$WORKSPACE`. If none is available, stop and ask the operator to
   create or assign a draft workspace in TYPO3; do not continue with writes.
4. For every record-backed call below, pass
   `"workspace_id": $WORKSPACE` whenever the tool schema exposes that
   parameter. Never omit it during this script. This is mandatory in local
   mode because omission would write live; it also makes strict-mode results
   deterministic. File, site-YAML, site-settings, Composer, and project-file
   changes remain immediate even when a workspace ID is present.
5. Call `GetPageTree` with `startPage: 0`, `depth: 2`, and
   `workspace_id: $WORKSPACE` to see the
   current root-level structure.

## Phase 1 — Create an English-only site

1. Call `CreateSite` with:

   - `action: "create"`
   - `identifier: "mcp-news-demo"`
   - `workspace_id: $WORKSPACE`
   - `rootPageId`: the UID of an existing root page prepared for this test.
     In strict mode, do not pass a workspace-only draft page here; site
     configuration is not workspace-versioned.
   - `base: "https://mcp-news-demo.ddev.site/"`
   - `defaultLanguage: { title: "English", locale: "en_US.UTF-8", iso-639-1: "en", flag: "us" }`
   - No `languages` yet — Phase 5 exercises `addLanguage` separately.
2. Call `GetPageTree` again with `startPage: 0` and
   `workspace_id: $WORKSPACE` to confirm the prepared site root remains
   visible. Store the `rootPageId` used above as `$ROOT`.

## Phase 2 — Build a small page tree

1. Use `ListTables` — confirm the schema layer is live and that you
  can see `pages`, `tt_content`, and `sys_file_reference`.
2. Use `GetTableSchema` for `pages` and review which fields are
  editable.
3. Create three pages under `$ROOT` with `WriteTable`
  (`table: "pages"`, `action: "create"`, `pid: $ROOT`,
  `workspace_id: $WORKSPACE`):

   - "Home" (`doktype: 1`, `nav_title: "Home"`, slug `/home`)
   - "World News" (`doktype: 1`, slug `/world-news`)
   - "About" (`doktype: 1`, slug `/about`)

   Capture the returned live-facing UIDs as `$HOME`, `$WORLD`, `$ABOUT`.
4. Call `GetPage` with `uid: $HOME` (or
   `url: "https://mcp-news-demo.ddev.site/home"`) to confirm it resolves.

## Phase 3 — Import a news article from a respected source

Pick one publicly accessible article URL that you own, that is public domain,
or whose license permits this test. Respect copyright: import only content the
license permits and preserve source, author, license, and attribution metadata.

1. Call `ImportFromUrl` in `mode: "analyze"` first:

   - `url: "<chosen article URL>"`
   - `targetPid: $WORLD`
   - `mode: "analyze"`
   - `workspace_id: $WORKSPACE`

   Review the proposed content elements. This should be read-only. In strict
   mode the source host must be allowed by `network.outbound`; redirects are
   not followed.
2. If the proposal looks sensible, call `ImportFromUrl` again with
   `mode: "execute"` and `workspace_id: $WORKSPACE` — this creates a draft
   page with `tt_content` elements inside `$WORLD`. Capture the new page UID as
   `$ARTICLE`.
3. Call `ReadTable` (`table: "tt_content"`, `pid: $ARTICLE`) to list
  the created elements.
4. Call `ImportContent` with `targetPid: $ARTICLE`,
   `mode: "execute"`, `workspace_id: $WORKSPACE`, and a short raw Markdown summary. Confirm the new
   element lands in the workspace.

## Phase 4 — Fetch images and attach copyright

1. Call `ListStorages` to see available FAL storages and note the
  default upload storage.
2. Call `BrowseFolder` on the root folder of that storage, then
  `BrowseFiles` in an empty subfolder (or `/` of the sandbox) to
    confirm the file sandbox is configured.
3. For each image URL the article uses, call `UploadFileFromUrl`:

   - `url: "<image URL>"`
   - `path: "mcp-demo/images/<slug>.jpg"`
   - `metadata: { title: "<image title>", alternative: "<alt text describing image>", description: "<short caption>", copyright: "<author, source, and license>" }`
4. Use `SearchMedia` with `keyword: "mcp-demo"` or
   `folder: "/mcp-demo/images/"` to verify the uploaded files are indexed.
   Use `SearchFile` with `folder: "mcp-demo/images"` to find files by
   path fragment.
5. Call `ReadFileMetadata` on one of the uploads and confirm the
  copyright field survived the round-trip. If anything is missing or
    wrong, call `WriteFile` with a `metadata` block to patch it (no
    `content` — metadata-only update).
6. Also generate a small Markdown note with `WriteFile`:

   - `path: "mcp-demo/notes/source-credit.md"`
   - `content: "# Source credit\n\nDemo notes for the imported article."`
   - `metadata: { title: "MCP News Demo Notes", description: "Demo source notes", copyright: "CC0 — demo asset" }`
7. Upload a small binary file via `UploadFile` (base64 content) to
  confirm that path works too, e.g. a 1x1 transparent PNG at
    `mcp-demo/images/pixel.png`.
8. For each image uploaded in step 3 call `AttachImage`:

   - `table: "tt_content"`
   - `uid`: a suitable text element UID from the `ReadTable` result
   - `field: "image"`
   - `source: { "sys_file_uid": <uid returned from UploadFileFromUrl> }`
   - `reference: { "alternative": "<alt text>", "copyright": "<copyright>" }`
   - `workspace_id: $WORKSPACE`

   This creates a workspace-safe `sys_file_reference` via DataHandler.

## Phase 5 — Add translations (DE, ZH, HE, ES)

1. Call `CreateSite` with `action: "addLanguage"`,
   `identifier: "mcp-news-demo"`, `workspace_id: $WORKSPACE`, and:

   - `language: { title: "Deutsch", locale: "de_DE.UTF-8", iso-639-1: "de", flag: "de", base: "/de/", fallbackType: "fallback" }`
2. Repeat `CreateSite addLanguage` for Chinese:
  `{ title: "中文", locale: "zh_CN.UTF-8", iso-639-1: "zh",   flag: "cn", base: "/zh/", fallbackType: "fallback" }`.
3. Repeat for Hebrew (right-to-left — still just another language entry):
  `{ title: "עברית", locale: "he_IL.UTF-8", iso-639-1: "he",   flag: "il", base: "/he/", fallbackType: "fallback" }`.
4. Repeat for Spanish:
  `{ title: "Español", locale: "es_ES.UTF-8", iso-639-1: "es",   flag: "es", base: "/es/", fallbackType: "fallback" }`.
5. Confirm that `GetTableSchema` and the relevant record-tool schemas now
   expose language fields/parameters (the server hides translation-specific
   inputs when the installation has no meaningful language support).
6. For `$HOME`, `$WORLD`, `$ABOUT`, and `$ARTICLE`, call `WriteTable`:

   - `table: "pages"`, `action: "translate"`, `uid: <live UID>`
   - `workspace_id: $WORKSPACE`
   - `data: { sys_language_uid: "de", title: "<German title>", nav_title: "<German nav>", description: "<German meta>" }`

   Repeat the call four times per page — once each for `de`, `zh`, `he`, and
   `es`. Use ISO-639-1 codes directly; the server maps them to TYPO3 language
   UIDs.
7. For each content element on `$ARTICLE`, call `WriteTable`
  (`table: "tt_content"`, `action: "translate"`, `uid: <element UID>`,
    `workspace_id: $WORKSPACE`,
    `data: { sys_language_uid: "<iso>", header: ..., bodytext: ... }`)
    for all four languages.
8. Optional stress test: call `BulkWrite` with one batch containing
  up to 10 `translate` operations for tt_content elements at once
    (the tool caps at 50).

## Phase 6 — Copy, move, and reorganise content

1. Call `CopyContent` to duplicate one of the article's tt_content
  elements to `$ABOUT` (`table: "tt_content"`, `uid: <element UID>`,
  `targetPid: $ABOUT`).
2. Use `WriteTable action: "move"` to move the copied element to
  position `top` inside `$ABOUT`.
3. Use `WriteTable action: "update"` on the copy to change its
  header, and `WriteTable action: "delete"` to remove it again so
    you exercise the full CRUD surface.

## Phase 7 — Audit, search, schema inspection

1. Call `ContentAudit` with `rootPageId: $ROOT`, `depth: 3`,
  `workspace_id: $WORKSPACE`, and
  `checks: ["missing_meta_description", "missing_alt_text", "missing_page_title"]`.
  Patch findings via `WriteTable` in the same workspace as needed.
2. Call `Search` with a phrase from the imported article to verify
  full-text search across tables returns the expected matches.
3. Call `GetFlexFormSchema` for a well-known plugin CType (e.g.
  `list` / `felogin_pi1` if present) to confirm FlexForm introspection
    works.
4. Call `GetSystemLog` with a recent time window to confirm the
  server can surface TYPO3's sys_log. Look for your own writes.

## Phase 8 — Redirects, site administration, extras

1. Call `ManageRedirects` with `action: "list"` and verify the
  tool is available (it will return empty on a clean install). On standard
  TYPO3 installs, create/delete returns the documented workspace-safety error
  because `sys_redirect` is not workspace-capable, unless trusted local mode
  permits live writes.
2. Call `InstallExtension` to install a small, well-known extension
  (e.g. `georgringer/news` on a staging system) — but only if that is
    desired for this run. This exercises the admin-gated tool. Skip on
    systems where you don't want composer writes.
3. Call `SiteSet` with `action: "find"` to list available Site Sets.
   If you have a known harmless Site Set on the staging instance, add and
   remove it on `mcp-news-demo`; otherwise keep this as a read-only discovery
   check.
4. Call `SafeCli` with an allowlisted TYPO3 command such as
   `extension:list` to exercise the sandboxed CLI.
5. Call `SolrIndexQueue` with `action: "list"`. If EXT:solr and an enabled
   Index Queue scheduler task are present, optionally run that specific task
   once with `action: "run"`, its `taskUid`, and `runs: 1`; otherwise accept
   the documented unavailable/no-task result and do not run arbitrary tasks.
6. If dev-site tools are available, call `SiteSettings` with
   `action: "listDefinitions"`, `identifier: "mcp-news-demo"`, then with
   `action: "get"`. Keep this check read-only unless the disposable site has a
   specifically approved setting to change; an update writes `settings.yaml`
   immediately.
7. If dev-site tools are available, call `ListViewHelpers`, choose a returned
   tag such as `f:for`, and pass that exact `tagName` to
   `GetViewHelperDocumentation`.
8. On a clean disposable Git checkout with a known test extension, call
   `CreateLocallang` once with that `extensionKey`, a unique XLF `fileName`,
   and one `transUnits` item containing `id` and `source`. This writes a project
   file immediately; inspect the diff and remove or revert the test fixture
   after the run. Skip only when no disposable extension/project checkout is
   available.
9. If the x402 paywall surface is enabled, exercise
   `ListPaidContent`, `GetPaidContent`, and `GetPaymentStats`. On stock installs
   these cleanly report "not available" — that is the correct negative-path
   check.
10. If the TYPO3 project contains a frontend app that already uses shadcn/ui,
   call `ApplyShadcnPreset` with a harmless preset and `only: "theme"`.
   Otherwise skip it; this tool intentionally rewrites frontend project files.

## Phase 9 — Workspace review, publish, rollback

1. Call `ListWorkspaces` and confirm `$WORKSPACE` is still available. Remember
   that this only lists workspaces; it does not prove that one contains changes.
2. Call `WorkspaceReview` with `workspace_id: $WORKSPACE` and scroll the diff.
   Record-backed changes from phases 1–8 should appear as pending. Physical
   files, site YAML/settings, Composer operations, XLF files, and frontend files
   are immediate and will not appear in this review.
3. Call `GetPreviewUrl` for `$ARTICLE` with `table: "pages"` and
   `uid: $ARTICLE`, `workspace_id: $WORKSPACE`, then open the returned
   workspace preview URL.
4. Call `RenderRecord` with `pageId: $ARTICLE`,
   `workspace_id: $WORKSPACE`, `mode: "text"`, and a reasonable `maxLength`
   to verify the frontend can render the draft.
5. Call `PublishWorkspace` with `workspace_id: $WORKSPACE` and
  `dryRun: true`. Read the report — no live rows change yet.
6. If the dry-run report is correct, call `PublishWorkspace` again
  with `workspace_id: $WORKSPACE` and `dryRun: false` to publish the reviewed
  record changes to live.
7. After publishing, create one more throwaway change
  (e.g. `WriteTable` update on `$ABOUT` setting a silly title) with
  `workspace_id: $WORKSPACE`. Call `RollbackWorkspace` with that workspace and
  `dryRun: true`, inspect the report, then repeat with `dryRun: false` to verify
  the rollback path.
8. Call `GetPageTree` once for each supported language code (`de`, `zh`, `he`,
   `es`) and then call `GetPage` on the corresponding translated page URLs to
   confirm the overlays and translated slugs resolve.

## Phase 10 — Teardown (optional)

1. Call `WriteTable action: "delete"` on `$ARTICLE`, then `$WORLD`, `$HOME`,
  and `$ABOUT`, always with `workspace_id: $WORKSPACE`. Do not delete `$ROOT`:
  it was a pre-existing root and the immediate site configuration still
  references it. Each requested record delete stages in the workspace.
2. Call `PublishWorkspace` with `workspace_id: $WORKSPACE` (dry-run, then
   `dryRun: false`) to finalize the record teardown.
3. Re-run `GetPageTree` — the demo child pages should be gone and the prepared
   `$ROOT` should remain.
4. `SafeCli` → `cache:flush` to remove any stale caches.
5. Remove the `mcp-news-demo` site configuration and any generated project-file
   fixtures through the normal TYPO3/Git workflow; `CreateSite` intentionally
   has no destructive delete action.

---

## Expected coverage


| #   | Tool                                               | Phase       |
| --- | -------------------------------------------------- | ----------- |
| 1   | `ListWorkspaces`                                   | 0, 9        |
| 2   | `GetCapabilities`                                  | 0           |
| 3   | `GetPageTree`                                      | 0, 1, 9     |
| 4   | `GetPage`                                          | 2, 9        |
| 5   | `ListTables`                                       | 2, 5        |
| 6   | `GetTableSchema`                                   | 2, 5        |
| 7   | `GetFlexFormSchema`                                | 7           |
| 8   | `ReadTable`                                        | 3           |
| 9   | `WriteTable` (create/update/move/translate/delete) | 2, 5, 6, 10 |
| 10  | `BulkWrite`                                        | 5           |
| 11  | `CopyContent`                                      | 6           |
| 12  | `ImportContent`                                    | 3           |
| 13  | `ImportFromUrl`                                    | 3           |
| 14  | `AttachImage`                                      | 4           |
| 15  | `ContentAudit`                                     | 7           |
| 16  | `Search`                                           | 7           |
| 17  | `GetSystemLog`                                     | 7           |
| 18  | `ManageRedirects`                                  | 8           |
| 19  | `CreateSite`                                       | 1, 5        |
| 20  | `SiteSet`                                          | 8           |
| 21  | `InstallExtension`                                 | 8           |
| 22  | `SafeCli`                                          | 8, 10       |
| 23  | `ApplyShadcnPreset`                                | 8 optional  |
| 24  | `ListStorages`                                     | 4           |
| 25  | `BrowseFolder`                                     | 4           |
| 26  | `BrowseFiles`                                      | 4           |
| 27  | `SearchFile`                                       | 4           |
| 28  | `SearchMedia`                                      | 4           |
| 29  | `ReadFileMetadata`                                 | 4           |
| 30  | `UploadFile`                                       | 4           |
| 31  | `UploadFileFromUrl`                                | 4           |
| 32  | `WriteFile`                                        | 4           |
| 33  | `GetPreviewUrl`                                    | 9           |
| 34  | `RenderRecord`                                     | 9           |
| 35  | `WorkspaceReview`                                  | 9           |
| 36  | `PublishWorkspace`                                 | 9, 10       |
| 37  | `RollbackWorkspace`                                | 9           |
| 38  | `ListPaidContent`                                  | 8           |
| 39  | `GetPaidContent`                                   | 8           |
| 40  | `GetPaymentStats`                                  | 8           |
| 41  | `SolrIndexQueue`                                   | 8           |
| 42  | `SiteSettings`                                     | 8 optional  |
| 43  | `ListViewHelpers`                                  | 8 optional  |
| 44  | `GetViewHelperDocumentation`                       | 8 optional  |
| 45  | `CreateLocallang`                                  | 8 optional  |


## Success criteria

- No MCP call returns `isError: true` except for intentional
  negative-path checks (x402 on stock installs, unavailable Solr prerequisites,
  and redirect create/delete on standard non-workspace-capable `sys_redirect`
  tables).
- The published page tree shows 4 translations per page and all images
carry copyright metadata (`ReadFileMetadata` confirms it).
- `WorkspaceReview` before `PublishWorkspace` lists all staged record changes;
after publish it is empty.
- `RollbackWorkspace` on a fresh change leaves live data untouched.
- Every record-backed call used `$WORKSPACE > 0`; no call silently fell back to
  local live workspace `0`.
