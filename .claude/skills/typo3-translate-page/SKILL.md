---
name: typo3-translate-page
description: Translate TYPO3 page content with workspace-safe MCP tools
user-invocable: true
---

# TYPO3 Page Translation Skill

Translate page content using workspace-staged MCP tools on TYPO3 v14.

## Prerequisites

1. Confirm language support via `GetCapabilities` / site languages from `GetPage`.
2. Use ISO language codes (for example `de`, `fr`) in tool parameters when the instance supports multiple languages.

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
  data: { translated fields, sys_language_uid: "<target ISO>" }
```

Repeat per content element. Use `translateChildren=false` when you translate nested records manually.

### 4. Review and publish

```
Tools: WorkspaceReview → PublishWorkspace
```

Use `onlyTranslations=true` on `PublishWorkspace` when source-language edits should stay staged.

## Tips

- Translate pages before child records when the schema uses inline relations.
- Keep translations hidden (`hidden=true` on translate) until editorial review is done.
- Run `ContentAudit` on the translated tree to catch missing metadata or alt text.
