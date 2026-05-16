# Full-Feature Chatbot Test Script

A natural-language TODO list you can paste into any MCP-connected chatbot
(Claude Desktop, Cursor, n8n, Manus, MCP Inspector) that has the TYPO3 MCP
Server attached. Walking end-to-end exercises the bundled MCP surface,
including optional tools when the target instance has the required extensions
or frontend project files.

The chatbot should work through the list top-to-bottom, confirming success
after each step. Record writes land in a TYPO3 workspace and are published in
Phase 9. Site configuration changes and physical file writes are immediate in
TYPO3, so run this only on a disposable staging or local instance.

> **Admin rights required** for `CreateSite`, `SiteSet`, `InstallExtension`,
> `SafeCli`, and `ApplyShadcnPreset`. Run against a staging TYPO3 v14 install
> — never production.

---

## Phase 0 — Sanity check the server

1. Ask the chatbot to list its available MCP tools and confirm it can see
   at least: `GetCapabilities`, `GetPageTree`, `ListWorkspaces`,
   `CreateSite`, `WriteTable`, `UploadFileFromUrl`, and `PublishWorkspace`.
2. Call `GetCapabilities` and note whether local mode, outbound HTTP, and
   optional x402 tools are available.
3. Call `ListWorkspaces` — note the active workspace ID. The MCP layer
   will auto-create a workspace called "MCP" if none exists; that is
   expected.
4. Call `GetPageTree` with `startPage: 0`, `depth: 2` to see the
   current root-level structure.

## Phase 1 — Create an English-only site

1. Call `CreateSite` with:
  - `action: "create"`
  - `identifier: "mcp-news-demo"`
  - `rootPageId`: the UID of an existing root page prepared for this test.
    In strict mode, do not pass a workspace-only draft page here; site
    configuration is not workspace-versioned.
  - `base: "https://mcp-news-demo.ddev.site/"`
  - `defaultLanguage: { title: "English", locale: "en_US.UTF-8", iso-639-1: "en", flag: "us" }`
  - No `additionalLanguages` yet — we will add them later so we can
  verify both the create-then-add and replace-all flows.
2. Call `GetPageTree` again, `startPage: 0`, to confirm the new root
  appears. Capture the root page UID as `$ROOT`.

## Phase 2 — Build a small page tree

1. Use `ListTables` — confirm the schema layer is live and that you
  can see `pages`, `tt_content`, and `sys_file_reference`.
2. Use `GetTableSchema` for `pages` and review which fields are
  editable.
3. Create three pages under `$ROOT` with `WriteTable`
  (`table: "pages"`, `action: "create"`, `pid: $ROOT`):
  - "Home" (`doktype: 1`, nav_title "Home", slug `/`)
  - "World News" (`doktype: 1`, slug `/world-news`)
  - "About" (`doktype: 1`, slug `/about`)
   Capture the returned live UIDs as `$HOME`, `$WORLD`, `$ABOUT`.
4. Call `GetPage` with `uid: $HOME` (or `url: "https://mcp-news-demo.ddev.site/"`) to confirm it resolves.

## Phase 3 — Import a news article from a respected source

Pick one publicly accessible article URL from a reputable outlet that you
have permission to quote (BBC News, Reuters, AP News, NASA, European
Commission, etc.). Respect copyright: this is a demo — only quote short
excerpts and always attach source + copyright metadata to the images.

1. Call `ImportFromUrl` in `mode: "analyze"` first:
  - `url: "<chosen article URL>"`
    - `targetPid: $WORLD`
    - `mode: "analyze"`
    Review the proposed content elements. This should be read-only.
2. If the proposal looks sensible, call `ImportFromUrl` again with
  `mode: "execute"` — this creates a draft page with `tt_content`
    elements inside `$WORLD`. Capture the new page UID as `$ARTICLE`.
3. Call `ReadTable` (`table: "tt_content"`, `pid: $ARTICLE`) to list
  the created elements.
4. Call `ImportContent` with `targetPid: $ARTICLE`,
   `mode: "execute"`, and a short raw Markdown summary. Confirm the new
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
    - `metadata: { title: "<image title>", alternative: "<alt text describing image>", description: "<short caption>", copyright: "© <outlet> — reproduced for demonstration under fair use" }`
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
    This creates a workspace-safe `sys_file_reference` via DataHandler.

## Phase 5 — Add translations (DE, ZH, HE, ES)

1. Call `CreateSite` with `action: "addLanguage"`,
  `identifier: "mcp-news-demo"`, and:
  - `language: { title: "Deutsch", locale: "de_DE.UTF-8", iso-639-1: "de", flag: "de", base: "/de/", fallbackType: "fallback" }`
2. Repeat `CreateSite addLanguage` for Chinese:
  `{ title: "中文", locale: "zh_CN.UTF-8", iso-639-1: "zh",   flag: "cn", base: "/zh/", fallbackType: "fallback" }`.
3. Repeat for Hebrew (right-to-left — still just another language entry):
  `{ title: "עברית", locale: "he_IL.UTF-8", iso-639-1: "he",   flag: "il", base: "/he/", fallbackType: "fallback" }`.
4. Repeat for Spanish:
  `{ title: "Español", locale: "es_ES.UTF-8", iso-639-1: "es",   flag: "es", base: "/es/", fallbackType: "fallback" }`.
5. Confirm that `ListTables` and `GetTableSchema` now expose the
  `sys_language_uid` parameter (the server only surfaces translation
    fields when the site is multilingual).
6. For `$HOME`, `$WORLD`, `$ABOUT`, and `$ARTICLE`, call `WriteTable`:
  - `table: "pages"`, `action: "translate"`, `uid: <live UID>`
    - `data: { sys_language_uid: "de", title: "<German title>", nav_title: "<German nav>", description: "<German meta>" }`
    Repeat the same call four times per page — once each for
    `de`, `zh`, `he`, `es`. Use ISO-639-1 codes directly; the server
    maps them to TYPO3 language UIDs.
7. For each content element on `$ARTICLE`, call `WriteTable`
  (`table: "tt_content"`, `action: "translate"`, `uid: <element UID>`,
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

1. Call `ContentAudit` with `startPage: $ROOT`, `depth: 3`, and
  `checks: ["missingMetaDescription", "missingAltText",   "missingSeoTitle"]`. Patch findings via `WriteTable` as needed.
2. Call `Search` with a phrase from the imported article to verify
  full-text search across tables returns the expected matches.
3. Call `GetFlexFormSchema` for a well-known plugin CType (e.g.
  `list` / `felogin_pi1` if present) to confirm FlexForm introspection
    works.
4. Call `GetSystemLog` with a recent time window to confirm the
  server can surface TYPO3's sys_log. Look for your own writes.

## Phase 8 — Redirects, site administration, extras

1. Call `ManageRedirects` with `action: "list"` and verify the
  tool is available (it will return empty on a clean install). Note:
    create/delete of redirects is intentionally disabled via MCP; the
    tool is read-only here.
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
5. If the x402 paywall surface is enabled, exercise
  `ListPaidContent`, `GetPaidContent`, and
    `GetPaymentStats`. On stock installs these will cleanly report
    "not available" — that is the correct negative-path check.
6. If the TYPO3 project contains a frontend app that already uses shadcn/ui,
   call `ApplyShadcnPreset` with a harmless preset and `only: "theme"`.
   Otherwise skip it; this tool intentionally rewrites frontend project files.

## Phase 9 — Workspace review, publish, rollback

1. Call `ListWorkspaces` — confirm the MCP workspace still holds
  your changes.
2. Call `WorkspaceReview` with `workspace_id: <MCP workspace>` and
  scroll the diff. Everything from phases 1–8 should appear as
    pending.
3. Call `GetPreviewUrl` for `$ARTICLE` with `table: "pages"` and
   `uid: $ARTICLE`, then open the returned workspace preview URL.
4. Call `RenderRecord` with `pageId: $ARTICLE`, `mode: "text"`, and a
   reasonable `maxLength` to verify the frontend can render the draft.
5. Call `PublishWorkspace` with `workspace_id: <MCP workspace>` and
  `dryRun: true`. Read the report — no live rows change yet.
6. If the dry-run report is correct, call `PublishWorkspace` again
  with `dryRun: false` to publish everything to live.
7. After publishing, create one more throwaway change
  (e.g. `WriteTable` update on `$ABOUT` setting a silly title), then
    call `RollbackWorkspace` to verify the rollback path works.
8. Final `GetPageTree` with all four language flags to eyeball the
  result, then `GetPage` on `https://mcp-news-demo.ddev.site/de/`
    and `/zh/`, `/he/`, `/es/` variants to confirm translated slugs
    resolve.

## Phase 10 — Teardown (optional)

1. `WriteTable action: "delete"` on `$ARTICLE`, `$WORLD`, `$HOME`,
  `$ABOUT`, then `$ROOT`. Each delete stages in the workspace.
2. `PublishWorkspace` (dry-run, then live) to finalize.
3. Re-run `GetPageTree` — the demo tree should be gone.
4. `SafeCli` → `cache:flush` to remove any stale caches.

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


## Success criteria

- No MCP call returns `isError: true` except for intentional
negative-path checks (x402 on stock installs, redirect
create/delete).
- The published page tree shows 4 translations per page and all images
carry copyright metadata (`ReadFileMetadata` confirms it).
- `WorkspaceReview` before `PublishWorkspace` lists all staged changes;
after publish it is empty.
- `RollbackWorkspace` on a fresh change leaves live data untouched.
