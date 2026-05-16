# LLM Tests for MCP Tools

This directory contains high-level tests that use real LLMs against the TYPO3
MCP tool surface. They are not a replacement for functional TYPO3 tests; they
verify a different question: "Does the tool contract make sense to an agent in
realistic editor workflows?"

## Why these tests exist

These tests validate that the current TYPO3 v14 MCP layer is usable by models
without hidden hints:

1. tool names and descriptions are understandable
2. JSON Schema is strong enough for the model to form valid calls
3. models explore responsibly before writing
4. workspace-first TYPO3 behavior remains usable in multi-step flows
5. schema or error-message regressions are caught as user-behavior problems,
  not only as low-level PHP failures

They complement, but do not replace:

- unit tests for focused PHP logic
- functional TYPO3 integration tests
- targeted security tests such as `McpEndpointSecurityTest`,
`UploadFileFromUrlToolTest`, and `McpHttpLogRedactorTest`

## Current suite

The active LLM tests in this directory are:

- `CreatePageTest.php` - page creation and navigation flows
- `ContentElementTest.php` - content editing / creation on existing pages
- `SeoMetaTest.php` - metadata and SEO-style record updates
- `NewsTest.php` - extension compatibility (`georgringer/news`)
- `WriteTableSearchReplaceTest.php` - search/replace style updates through `WriteTable`

The common harness lives in `LlmTestCase.php`.

## Running the tests

### Prerequisites

1. Use PHP 8.2+ for this repository:
  ```bash
   composer test:llm
  ```
2. Set `OPENROUTER_API_KEY` so `LlmTestCase` can initialize `OpenRouterClient`:
  ```bash
   export OPENROUTER_API_KEY="sk-or-v1-..."
  ```
   or in `.env.local`:
3. Run the full LLM suite:
  ```bash
   composer test:llm
  ```
4. Run a targeted test while iterating:
  ```bash
   composer test:llm -- --filter testLlmFixesHeaderSpellingErrors
  ```

## Supported models

`LlmTestCase::MODELS` currently exposes these OpenRouter model keys:


| Key              | OpenRouter model ID          |
| ---------------- | ---------------------------- |
| `haiku`          | `anthropic/claude-3-5-haiku` |
| `gpt-5.2`        | `openai/gpt-5.2`             |
| `gpt-oss`        | `openai/gpt-oss-120b`        |
| `kimi-k2`        | `moonshotai/kimi-k2`         |
| `mistral-medium` | `mistralai/mistral-medium-3` |


Tests that use `#[DataProvider('modelProvider')]` run once per configured model.

## Runtime characteristics

The suite intentionally uses conservative settings:

- temperature: `0`
- default model: `anthropic/claude-3-5-haiku`
- max tokens per call: `4000`
- provider: OpenRouter

Even with temperature `0`, expect some behavioral variation between models and
over time.

## Cost and stability

These tests make real API calls and can incur noticeable cost.

- each test can involve multiple round-trips because the model explores,
calls tools, reads results, and then acts
- multi-model tests multiply the total cost
- the suite is intentionally excluded from the default `composer test` run
- use `--filter` while developing to keep iteration cheap

LLM tests can fail because:

- a model changed behavior
- tool descriptions/schemas became less clear
- a functional regression changed the available TYPO3 context
- a model selected a different but still reasonable exploration path

## Writing new tests

### Principles

1. Extend `LlmTestCase`.
2. Use realistic prompts, not implementation hints.
3. Expect exploration before mutation.
4. Execute the tool calls for real.
5. Treat tool errors as test failures unless the test explicitly expects them.
6. Prefer assertions about behavior and intent over brittle exact phrasing.

### Helpful `LlmTestCase` methods

- `callLlm($prompt)`
- `setModel($key)`
- `executeUntilToolFound($response, $toolName, $maxSteps = ...)`
- `executeToolCall($toolCall)`
- `continueWithToolResult($previousResponse, $toolResult)`
- `assertToolCalled($response, $toolName, $expectedParams = null)`
- `getToolCallHistory()`
- `getToolCallsDebugString()`

### Behavioral expectations

The current tool surface is designed so a model can usually:

- discover location/context with `GetPageTree`, `GetPage`, `ReadTable`, or `Search`
- inspect shape with `GetTableSchema` / `GetFlexFormSchema`
- write only after it has enough context

That pattern is intentional and should be preserved when changing tool
descriptions or parameters.

## Error handling expectations

When you write or update LLM tests:

- fail on MCP tool errors unless the scenario is explicitly negative
- keep assertions tolerant where multiple valid strategies exist
- prefer checking tool choice, key arguments, and successful outcome over exact
final prose from the model

For successful MCP calls in PHP-side integration tests, the project convention is:

```php
$this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
```

## Debugging tips

- inspect `getToolCallHistory()` first
- use `getToolCallsDebugString()` when a model took an unexpected path
- verify fixtures and loaded extensions in `setUp()`
- remember that the TYPO3 v14 tool surface may evolve; update assertions when
the contract improves rather than forcing old behavior

## Future evolution

This suite should evolve together with the MCP surface. In the TYPO3 v14 line,
tool names, descriptions, and schema details may change when that improves LLM
ergonomics or security. When that happens, update these tests to validate the
new contract rather than preserving stale assumptions.