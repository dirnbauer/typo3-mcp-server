# Testing with Cursor

This is the most accessible way to manually verify the MCP server end-to-end:
connect Cursor's chat to your TYPO3 instance and have the LLM exercise the
tools while you watch the workspace fill up.

## 1. Connect Cursor

1. Open the **MCP Server backend module** (TYPO3 backend → User → MCP
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
4. Watch the tool calls scroll by. The script first requires an explicit draft
   workspace and passes it to record-backed calls; verify those pending records
   in TYPO3 backend → Workspaces. Physical files, site YAML/settings, Composer,
   XLF, and frontend-project changes are immediate and do not appear there.

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

`PublishWorkspace` and `RollbackWorkspace` default to **dry-run mode**. Inspect
the report before asking Cursor to repeat either call with `dryRun: false`.
Redirect deletion is `ManageRedirects` with `action: "delete"`; it has no
`dryRun` parameter. Use it only after explicit confirmation on a disposable
environment, and only where workspace-safe redirects or trusted local mode make
the write available.

## 5. Choose the transport

Use the backend module's generated Cursor configuration for local stdio. It
includes the DDEV project name or the absolute TYPO3 binary path and working
directory. Local stdio runs as its launching OS user without OAuth; TYPO3
permissions and capability checks still apply.

To test OAuth and HTTP, choose the remote configuration in the module. A
stdio-to-HTTP proxy such as `mcp-remote` still authenticates through HTTP.
Connection examples and the host-security boundary are maintained in the
[installation manual](../Installation/Index.rst).

## 6. What to file when something is wrong

When opening a bug report against the MCP server, include:

- The exact prompt you typed.
- The Cursor settings → MCP server log (it captures every tool call).
- Output of `mcp:get-capabilities --json` (so reviewers know which tools
  are gated).
- The TYPO3 reports module's "Workspaces" entry so reviewers can see what
  ended up staged.

Share only the relevant log excerpt after reviewing it for credentials,
personal data, and content. MCP HTTP logging redacts sensitive headers and
token-shaped query values, but that does not sanitize all TYPO3 or third-party
logs. See the [security audit](../Architecture/SecurityAudit.rst).
