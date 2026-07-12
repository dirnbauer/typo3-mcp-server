---
name: typo3-translate-page
description: Translate TYPO3 page content with workspace-safe MCP tools
user-invocable: true
---

# TYPO3 Page Translation Skill

Translate page content safely in either a draft workspace or an explicitly chosen local live workflow on TYPO3 v14.

## Prerequisites

1. Call `GetCapabilities`, then confirm language support from `GetPage` and check `allows_live_writes` / `localMode.enabled`.
2. Choose the write mode before translating:
   - For review-before-publish, call `ListWorkspaces`, choose a writable draft with an ID greater than `0`, and pass that `workspace_id` to every record-backed call. `ListWorkspaces` never creates a workspace; stop and ask an administrator to create or assign one if none is available.
   - For intentional immediate editing on a trusted local site, confirm `allows_live_writes=true`, omit `workspace_id` (or use `0`), tell the user translations change live, and skip workspace review/publish.
   - In strict/production mode an omitted ID selects or creates a draft automatically, but an explicit draft ID avoids ambiguity.
3. Use ISO language codes (for example `de`, `fr`) in tool parameters when the instance supports multiple languages.

## Workflow

### 1. Load source page context

```
Tool: GetPage
Parameters: uid=<pageUid>, language=<source ISO code if needed>
```

Collect source content element UIDs and fields that need translation.

### 2. Inspect translatable fields

```
Tool: GetTableSchema
Parameters: table=tt_content
```

Note which fields exist on each CType before calling `WriteTable` with `action=translate`.

### 3. Create translations

```
Tool: WriteTable
Parameters:
  action: translate
  table: tt_content
  uid: <source live UID>
  workspace_id: <draft ID when staging>
  data: { translated fields, sys_language_uid: "<target ISO>" }
```

Repeat per content element and keep using the selected write mode. On DDEV/local mode, omitting `workspace_id`
during a staged workflow would write that translation live. Use `translateChildren=false` when you translate
nested records manually.

### 4. Review and publish

```
Tools: WorkspaceReview → PublishWorkspace
```

Run this phase only for a draft workflow. Pass the selected draft `workspace_id` to both tools. Use
`onlyTranslations=true` on `PublishWorkspace` when source-language edits should stay staged, and use a dry run
before publication. Do not publish intentional local live translations.

## Tips

- Translate pages before child records when the schema uses inline relations.
- Keep translations hidden (`hidden=true` on translate) until editorial review is done.
- Run `ContentAudit` on the translated tree to catch missing metadata or alt text.
