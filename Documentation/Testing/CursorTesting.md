# Testing with Cursor

This is the most accessible way to manually verify the MCP server end-to-end:
connect Cursor's chat to your TYPO3 instance and have the LLM exercise the
tools while you watch the workspace fill up.

## Why Cursor?

- The local stdio connection starts this extension's MCP server directly, so
  there is no OAuth ceremony during development.
- DDEV projects can be launched by project name, so Cursor does not need to
  know the container path.
- The chat panel shows every tool call and response, so you can see exactly
  which arguments the LLM picked.
- You can ask Claude or GPT, side by side, against the same MCP — useful
  when chasing model-specific quirks.

## 1. Connect Cursor

1. Open the **MCP Server backend module** (TYPO3 backend → System → MCP
   Server).
2. Click the "Install in Cursor" button. Cursor opens, asks for permission,
   and stores a local stdio MCP config. DDEV projects use `ddev exec -p
   <project> ...`; other local installs use the project-local
   `vendor/bin/typo3` binary with `cwd` set.
3. Verify the connection inside Cursor: open Settings → MCP → the new server
   should appear and list its tools (look for `ReadTable`, `WriteTable`,
   `GetCapabilities`, etc.).

If the install link is not available in your client, use the manual
configuration shown in the Cursor card. It gives you the JSON to paste into
`~/.cursor/mcp.json`.

## 2. Sanity check

In a Cursor chat, type:

```
Use the GetCapabilities MCP tool and tell me which subsystems are declared
and whether local mode is on.
```

You should see:

- A tool call to `GetCapabilities`.
- A response listing the declared subsystems from `Configuration/Capabilities.yaml`.
- `localMode.enabled: true` if the TYPO3 instance is in DDEV (DDEV env vars
  detected) or in the Development context; `false` on production.

If Cursor cannot start the server, check the MCP server log in Cursor
settings. For DDEV, `ddev` must be available on the host and the project name
in the generated config must match `ddev list`.

## 3. Drive the FullFeatureChatbotScript

The file [`FullFeatureChatbotScript.md`](FullFeatureChatbotScript.md) is
designed to be pasted into a chat client verbatim. Cursor handles it well:

1. Open a fresh chat in Cursor.
2. Paste the contents of `FullFeatureChatbotScript.md` as the first message.
3. Add: *"Work through this checklist top-to-bottom. After each phase,
   summarize what you did and what the MCP server returned. Stop and ask
   me before publishing anything (Phase 9)."*
4. Watch the tool calls scroll by. Each successful step leaves a visible
   workspace change — verify in TYPO3 backend → Workspaces module.

## 4. Manual smoke tests (no script)

If you just want to confirm the basics work:

| What you ask Cursor                                              | Tool the LLM should pick |
| ---------------------------------------------------------------- | ------------------------ |
| "Show the page tree two levels deep."                            | `GetPageTree`            |
| "What content elements are on page 1?"                           | `GetPage` / `ReadTable`  |
| "Search for the word 'welcome' across the site."                 | `Search`                 |
| "Create a new content element on page 1 with header 'MCP Test'." | `WriteTable`             |
| "Show me a workspace preview link for that element."             | `GetPreviewUrl`          |
| "Render that page so I can see what it looks like."              | `RenderRecord`           |
| "Discard my pending workspace changes."                          | `RollbackWorkspace`      |

For the destructive ones (`PublishWorkspace`, `RollbackWorkspace`,
`DeleteRedirect`), the tool defaults to **dry-run mode** — Cursor will
show what would happen, then ask the LLM to confirm before re-running with
`dryRun: false`.

## 5. Local-only testing without OAuth

The backend module's Cursor card already uses this mode. For manual setup,
there are two useful variants:

### Option A — `mcp-remote` proxy

The MCP backend module still shows the JSON for this. It runs `npx mcp-remote
https://your-site/mcp` from your machine, exposing it to Cursor via stdio.
This keeps the HTTP transport and therefore still needs a token, but it can
help with clients that only understand stdio.

### Option B — `vendor/bin/typo3 mcp:server` over stdio

For everything-local-on-one-machine, skip OAuth entirely:

```json
// ~/.cursor/mcp.json
{
  "mcpServers": {
    "typo3-local": {
      "command": "ddev",
      "args": ["exec", "./vendor/bin/typo3", "mcp:server"],
      "cwd": "/absolute/path/to/your/typo3-project"
    }
  }
}
```

This bypasses HTTP completely and runs the MCP server inside DDEV via
stdio. The generated backend-module config uses `ddev exec -p <project>` so
it does not depend on Cursor's working directory.

In stdio mode, the server runs as the OS user that owns the DDEV project.
Capability-manifest enforcement and TYPO3 permissions still apply, but
there is no OAuth ceremony.

## 6. Comparing model behavior

A nice debugging trick: open two Cursor chats side by side, one set to
Claude (Opus or Sonnet) and one to GPT-4 / GPT-5, and ask them the same
question. Differences in tool selection or argument shapes usually point
at unclear schemas in the tool definition — fix them in
`Tool->getSchema()` and both models converge.

## 7. What to file when something is wrong

When opening a bug report against the MCP server, include:

- The exact prompt you typed.
- The Cursor settings → MCP server log (it captures every tool call).
- Output of `mcp:get-capabilities --json` (so reviewers know which tools
  are gated).
- The TYPO3 reports module's "Workspaces" entry so reviewers can see what
  ended up staged.

The MCP HTTP middleware redacts Authorization headers, cookies, and the
`token` query parameter from logs by default — so attaching `var/log` is
safe. (See [`Architecture/SecurityAudit.rst`](../Architecture/SecurityAudit.rst).)
