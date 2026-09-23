.. include:: /Includes.rst.txt

.. _developer:

===========
Development
===========

.. _developer-setup:

Setup
=====

The repository installs a throw-away TYPO3 (SQLite) into ``public/`` and
``var/`` through ``composer install`` (``Build/setup-typo3.sh``). PHP 8.4 or
8.5 is required; a DDEV project (``.ddev/config.yaml``) is included.

.. code-block:: bash

   composer install
   composer test               # unit + functional (paratest, SQLite)
   composer test:protocol      # stable + stateless stdio lifecycles
   composer test:llm           # LLM ergonomics tests (needs OPENROUTER_API_KEY)
   composer phpstan            # level 8, no baseline (Classes, Tests/Unit, Tests/Architecture)
   composer php-cs-fixer:fix   # TYPO3 coding standards
   composer rector             # PHP migrations, dry-run
   composer fractor            # FlexForm/TypoScript/Fluid migrations, dry-run
   composer docs:check         # render this manual (Docker)
   Build/runTests.sh -s e2e    # Playwright E2E for the backend module

CI (``.github/workflows/tests.yml``) runs the documentation render, unit
tests with coverage, the protocol smoke matrix, functional tests on PHP 8.4
and 8.5, PHPStan, PHP CS Fixer and Rector on every push.

.. _developer-layout:

Repository layout
=================

.. code-block:: text

   Classes/
     MCP/            server factory, tool registry, tool implementations
     Service/        workspace, TCA, language, file sandbox, OAuth, manifest, local mode
     Http/           /mcp, /mcp_upload and OAuth endpoints
     Integration/    Abilities bridge, optional x402 adapter
     Controller/     backend module
     Command/        CLI commands (mcp:server, mcp:tool, per-tool shortcuts)
   Configuration/
     Capabilities.yaml   subsystems, per-tool requirements, outbound policy
     Services.yaml       DI, console.command and listener registration
   Documentation/    this manual (reStructuredText)
   Resources/        module templates and JavaScript, XLIFF labels (en, de), bundled skills
   Tests/            Unit, Functional, Architecture (phpat), Llm

.. _developer-rules:

Rules of the codebase
=====================

- Every record-backed tool goes through TYPO3 workspaces explicitly and hides
  version rows from the client; local mode is the only exception.
- Every tool declares its subsystems in :file:`Configuration/Capabilities.yaml`
  and ships a ``mcp:<name>`` command (a ``GenericMcpToolCommand`` entry in
  :file:`Configuration/Services.yaml`).
- Outbound HTTP calls ``CapabilityManifestService::assertUrlAllowed()`` before
  opening a socket.
- Tool contracts may change between releases when that improves LLM
  ergonomics; document the change in the tool description, the tests and
  :doc:`../Tools/Index` together.
- In functional tests assert success with
  ``self::assertFalse($result->isError, json_encode($result->jsonSerialize()));``.

.. _developer-upstream:

Syncing with upstream
=====================

This fork tracks ``hauptsacheNet/typo3-mcp-server`` (remote ``upstream``,
read-only). Review upstream changes individually and adapt them onto the
TYPO3 v14 services and tool contracts; never push to upstream.

.. code-block:: bash

   git fetch upstream
   git log --oneline HEAD..upstream/main
   git show <upstream-commit>

Behaviour is often already present under a different commit id, so check
before porting. Keep workspace selection, permission checks, capability
gates, file sandboxing and HTTP/OAuth security intact, add regression
coverage, then run the tests, PHPStan and the protocol smoke matrix.

.. _developer-release:

Releasing
=========

1. Update :file:`CHANGELOG.md`, :file:`ext_emconf.php`,
   :file:`Documentation/guides.xml` and :file:`Documentation/Includes.rst.txt`.
2. Run ``composer test``, ``composer phpstan``, ``composer php-cs-fixer``.
3. Tag ``vX.Y.Z`` and push ``main`` plus the tag to ``origin`` (GitHub) and
   ``gitlab``. The extension is distributed through Composer/Git only; there
   is no TER release.

.. _developer-tests:

Test layers
===========

See :doc:`../Testing/Index` for the E2E suite, the protocol compatibility
matrix, the tool-context benchmark, the Cursor walkthrough and the
full-feature chatbot script.
