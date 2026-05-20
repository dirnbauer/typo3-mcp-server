---
name: typo3-content-edit
description: Edit TYPO3 page content safely through MCP workspace tools
user-invocable: true
---

# TYPO3 Content Editing Skill

Guides an AI assistant through safe, workspace-staged content edits on a TYPO3 v14 site using EXT:mcp_server.

## Prerequisites

1. Call `GetCapabilities` to confirm the MCP server is reachable and whether DDEV/local mode is active.
2. Call `ListWorkspaces` if you need an explicit `workspace_id`.
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

### 3. Stage changes (never live)

```
Tool: WriteTable
Parameters: action=create|update|translate|move|delete, table, uid/pid, data
```

All record writes land in a workspace automatically. Review with `WorkspaceReview` before publishing.

### 4. Verify visually

```
Tools: GetPreviewUrl, RenderRecord
```

Share the preview URL with stakeholders or fetch rendered HTML to confirm the change.

### 5. Publish deliberately

```
Tools: WorkspaceReview → PublishWorkspace (dryRun=true first)
```

Only set `dryRun=false` when the editor approves publication.

## Tips

- Prefer `ImportContent` / `ImportFromUrl` in `analyze` mode first, then execute or use `BulkWrite`.
- Use `ContentAudit` before SEO-related bulk edits.
- Attach images with `AttachImage` instead of hand-building `sys_file_reference` rows.
