# TYPO3 MCP Server

[![Tests](https://github.com/dirnbauer/typo3-mcp-server/actions/workflows/tests.yml/badge.svg)](https://github.com/dirnbauer/typo3-mcp-server/actions/workflows/tests.yml)
[![TYPO3 14](https://img.shields.io/badge/TYPO3-14.3-orange)](https://get.typo3.org/version/14)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%20%7C%208.5-777bb4)](https://www.php.net/)
[![License GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

[Model Context Protocol](https://modelcontextprotocol.io/) server for TYPO3 v14:
workspace-safe, TCA-driven tools for pages, records, schemas, files and the
editorial workflow, served to LLM clients over HTTP (OAuth 2.1 + PKCE) or stdio
and mirrored 1:1 onto the TYPO3 CLI. Maintained fork of
[hauptsacheNet/typo3-mcp-server](https://github.com/hauptsacheNet/typo3-mcp-server)
by Marco Pfeiffer.

## What it is

- **Editor-first tools** — `GetPageTree`, `ReadTable`, `WriteTable`,
  `Search`, `AttachImage`, `ImportFromUrl`, `PublishWorkspace`, … derived from
  TCA, so third-party tables work without adapters.
- **Workspace transparency** — production writes are staged in a TYPO3 draft
  and clients only ever see stable live UIDs; trusted local mode (DDEV /
  Development context) edits live by design.
- **Capability manifest** — every tool declares its subsystems in
  `Configuration/Capabilities.yaml`; removing a subsystem disables the tools
  that need it, outbound HTTP defaults to `self`.
- **Files, sandboxed** — uploads (base64, URL, pre-signed `PUT`) land in
  `fileadmin/mcp/`, are create-only and deduplicated.
- **Dual-era protocol** — MCP `2025-11-25` sessions and stateless
  `2026-07-28` requests from one endpoint; the Abilities registry is projected
  into the same catalog as `ability_*` tools.

## Requirements

| Component | Version |
|---|---|
| TYPO3 | `^14.3` (`cms-core`, `cms-backend`, `cms-workspaces`) |
| PHP | `^8.4` (tested on 8.4 and 8.5) |
| MCP SDK | `logiscape/mcp-sdk-php ^2.0` |
| Abilities | `webconsulting/typo3-abilities ^1.2` (VCS repository, see below) |

## Install

```bash
composer config repositories.typo3-abilities vcs https://github.com/dirnbauer/typo3-abilities
composer require hn/typo3-mcp-server
vendor/bin/typo3 extension:setup
```

Distribution is Composer/Git only — there is no TER release.

## Configure

Open **User → MCP Server** in the backend: the endpoint URL with setup steps
for Claude, Cursor and Codex, your access tokens, a server-side connection
check and the list of tools a client is offered (English and German, light and
dark mode). Extension settings
(`fileSandboxRoot`, `maxFileSizeMb`, `localUnsafeMode`, `allowedOrigins`,
`enforceCapabilityManifest`, `sessionTimeout`, …) are documented in
[Configuration](Documentation/Configuration/Index.rst); hardening means deleting
lines from `Configuration/Capabilities.yaml`.

## Use

```bash
vendor/bin/typo3 mcp:server                                   # local stdio for Cursor
vendor/bin/typo3 mcp:oauth create <backend-user> --ttl-days 30  # static bearer token
vendor/bin/typo3 mcp:tool:list                                # every tool, also as mcp:<name>
vendor/bin/typo3 mcp:read-table --table tt_content --pid 1 --json
```

Remote clients connect to `https://your-site/mcp` and authenticate through the
OAuth flow with their existing backend login. See
[Usage](Documentation/Usage/Index.rst) for an example session and the tool
families, and the [tool reference](Documentation/Tools/Index.rst) for parameters.

## Develop

```bash
composer install            # installs a throw-away TYPO3 (SQLite) for the tests
composer test               # unit + functional
composer phpstan            # level 8, no baseline
composer php-cs-fixer:fix   # TYPO3 coding standards
composer test:protocol      # stable + stateless stdio smoke matrix
```

PHP 8.4+ is required locally; a DDEV project is included. Contributor notes,
repository layout and the upstream-sync workflow are in
[Development](Documentation/Developer/Index.rst).

## Docs

The manual lives in [`Documentation/`](Documentation/Index.rst):
[Introduction](Documentation/Introduction/Index.rst) ·
[Installation](Documentation/Installation/Index.rst) ·
[Usage](Documentation/Usage/Index.rst) ·
[Configuration](Documentation/Configuration/Index.rst) ·
[Tools](Documentation/Tools/Index.rst) ·
[Testing](Documentation/Testing/Index.rst) ·
[Architecture](Documentation/Architecture/Index.rst) ·
[Abilities integration](Documentation/Integration/Abilities.rst) ·
[Troubleshooting](Documentation/Troubleshooting/Index.rst) ·
[Changelog](CHANGELOG.md).

Thanks to [hauptsacheNet](https://github.com/hauptsacheNet) for the original,
to [in2code](https://github.com/in2code-de) (in2mcp) for the rate-limiting and
identity-summary ideas, and to [balatD](https://github.com/balatD/typo3-dev-mcp)
for the developer-introspection inspiration.

## License

GPL-2.0-or-later
