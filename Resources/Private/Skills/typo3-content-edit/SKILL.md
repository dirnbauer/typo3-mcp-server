---
name: typo3-content-edit
description: Edit TYPO3 page content safely through MCP workspace tools
user-invocable: true
---

# TYPO3 Content Editing Skill

Guides an AI assistant through safe content edits on a TYPO3 v14 site using EXT:mcp_server.

## Prerequisites

1. Call `GetCapabilities` to confirm the MCP server is reachable. Check `allows_live_writes` and `localMode.enabled`.
2. Choose the write mode before changing records:
   - For review-before-publish, call `ListWorkspaces`, choose a writable draft with an ID greater than `0`, and pass that `workspace_id` to every record-backed call. `ListWorkspaces` does not create a workspace; stop and ask an administrator to create or assign one if none is available.
   - For intentional immediate editing on a trusted local site, confirm `allows_live_writes=true`, omit `workspace_id` (or use `0`), tell the user that records change live, and skip the workspace publish phase.
   - In strict/production mode, an omitted ID selects or creates a draft automatically, but an explicit draft ID is clearer when several workspaces exist.
3. Use full URLs or page UIDs from `GetPageTree` / `GetPage` — do not guess identifiers.

## Workflow

### 1. Understand the page

```
Tools: GetPage or GetPageTree
```

Resolve the target page, note its UID, language, and existing content elements.

### 2. Inspect schema before writing

```
Tool: GetTableSchema
Parameters: table=tt_content, type=<CType if known>
```

For plugins or list types, also call `GetFlexFormSchema` when FlexForm fields matter.

### 3. Write changes in the selected mode

```
Tool: WriteTable
Parameters: action=create|update|translate|move|delete, table, uid/pid, data, workspace_id=<draft ID when staging>
```

Keep using the mode selected in the prerequisites. In particular, do not omit `workspace_id` halfway through a
staged workflow on DDEV/local mode: that would switch the write to live.

### 4. Verify visually

```
Tools: GetPreviewUrl, RenderRecord
```

For a staged workflow, pass the same draft `workspace_id`. Share the preview URL with stakeholders or fetch
rendered HTML to confirm the change. For an immediate local workflow, verify the live page and state that no
publish action remains.

### 5. Publish deliberately

```
Tools: WorkspaceReview → PublishWorkspace (dryRun=true first)
```

Run this phase only for a draft workspace. Pass the selected draft `workspace_id` to both tools and only set
`dryRun=false` when the editor approves publication. Do not call `PublishWorkspace` for intentional local live
edits.

## Tips

- Prefer `ImportContent` / `ImportFromUrl` in `analyze` mode first, then execute or use `BulkWrite`.
- Use `ContentAudit` before SEO-related bulk edits.
- Attach images with `AttachImage` instead of hand-building `sys_file_reference` rows.
