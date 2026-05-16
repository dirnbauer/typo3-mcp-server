# Edge Case Tests for TYPO3 MCP Server

This directory contains focused functional tests for failure paths and unusual
runtime conditions in the TYPO3 MCP tool layer. The goal is not only "does it
work", but "does it fail safely, clearly, and without breaking TYPO3 state".

## Current files in this directory

- `DatabaseErrorTest.php`
- `InvalidDataTest.php`
- `PermissionEdgeCaseTest.php`
- `ResourceConstraintTest.php`
- `SystemErrorTest.php`

Together, they exercise edge conditions around validation, permissions,
resource pressure, and internal service failures.

## What these tests are for

These tests help ensure that:

1. tools return actionable errors instead of crashing
2. invalid input is rejected early and predictably
3. permission boundaries remain intact
4. workspace-first guarantees survive failure conditions
5. expensive or unusual requests degrade gracefully

## Test categories

### `DatabaseErrorTest.php`

Database and persistence failure scenarios, such as:

- invalid or unexpected query conditions
- transaction or write failures
- integrity-related failures that must not corrupt TYPO3 state

### `InvalidDataTest.php`

Input validation and hostile/incorrect request handling, such as:

- invalid table names or identifiers
- invalid field names
- malformed values and type mismatches
- attempts that look like injection or broken query expressions

### `PermissionEdgeCaseTest.php`

Authorization and visibility boundaries, such as:

- read-vs-write differences
- restricted tables and fields
- workspace access constraints
- mount/permission combinations that should reduce visible capability

### `ResourceConstraintTest.php`

Inputs that may become expensive or operationally risky, such as:

- very large result sets
- complex filters
- recursive or high-volume operations
- file-related limits and expensive workloads

### `SystemErrorTest.php`

Broken or degraded TYPO3/system state, such as:

- missing or inconsistent TCA
- failing services or configuration
- filesystem and dependency-related failures

## Related tests outside this directory

Recent v14 cleanup and security work added neighboring tests that are not in
this folder but belong to the same "fail safely" philosophy:

- `Tests/Functional/Http/McpEndpointSecurityTest.php`
- `Tests/Functional/MCP/Tool/UploadFileFromUrlToolTest.php`
- `Tests/Unit/Http/McpHttpLogRedactorTest.php`

Those cover MCP endpoint hardening, URL upload restrictions, and request-log
redaction.

## Running the tests

Use PHP 8.2+:

```bash
composer test:functional -- --filter="EdgeCase"
```

Run a specific file or category:

```bash
composer test:functional -- --filter="DatabaseErrorTest"
composer test:functional -- --filter="InvalidDataTest"
composer test:functional -- --filter="PermissionEdgeCaseTest"
composer test:functional -- --filter="ResourceConstraintTest"
composer test:functional -- --filter="SystemErrorTest"
```

## Project-specific expectations

- Error messages should help the client recover.
- Record-backed tools must keep TYPO3 workspaces as the safety boundary.
- File tools must stay inside the MCP file sandbox.
- Validation should happen as early as possible.
- Internal failures should be logged server-side without exposing stack traces
  or filesystem details to MCP clients.

## Notes for maintainers

- If you add a new security or resilience behavior, prefer a dedicated test over
  broad prose-only documentation.
- Keep the assertions strict on `isError` / non-`isError` boundaries.
- When the tool surface changes in TYPO3 v14, update these tests so they verify
  the new contract instead of preserving outdated wording or parameter shapes.