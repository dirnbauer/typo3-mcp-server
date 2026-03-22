# TYPO3 MCP Server Agent Guide

- TYPO3 v14-only MCP extension for editor-friendly, workspace-safe content operations.
- Preferred commands: `ddev exec composer test`, `ddev exec composer phpstan`, `ddev exec composer test:llm` (needs `OPENROUTER_API_KEY`), `composer docs:check` (host Docker, not DDEV PHP).
- Read first: `TECHNICAL_OVERVIEW.md`, `Documentation/Architecture/Index.rst`, `Documentation/Architecture/SecurityAudit.rst`, `Documentation/Tools/Index.rst`.
- MCP tool contracts may change when that improves LLM ergonomics; backward compatibility is not required for tool names or parameters.
- Every record-backed tool must use TYPO3 workspaces explicitly. Live rows must never be edited directly, but workspace internals should stay invisible to the MCP client.
- Auto-select or create a writable workspace when needed. This behavior is intentional and must keep working in tests.
- In functional MCP tests, assert success with `$this->assertFalse($result->isError, json_encode($result->jsonSerialize()));`.
- Prefer TYPO3 core APIs and TCA-driven behavior over custom low-level handling (`DataHandler`, `PageRepository`, FAL, language/site APIs, schema factories).
- If the instance has no meaningful language support, hide translation-specific parameters and fields. Use `LanguageService` as the source of truth.
- Language overlays use TYPO3's `PageRepository` API; workspace overlays use custom transparency logic. See `Documentation/Architecture/LanguageOverlays.rst`.
- MCP HTTP security defaults matter: sensitive headers must not be logged, query-string bearer token auth stays disabled unless explicitly enabled, and the auth diagnostic remains minimal/configurable.
- File tools are restricted to the MCP file sandbox; physical files are not workspace-versioned and therefore take effect immediately once written.
- Tool descriptions, annotations, pagination hints, and recoverable errors should stay aligned with the public `mcp-builder` guidance.