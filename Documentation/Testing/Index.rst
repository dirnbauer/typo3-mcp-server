.. include:: /Includes.rst.txt

.. _testing:

=======
Testing
=======

This section documents project-specific test workflows for TYPO3 MCP Server.
It focuses on the browser E2E suite because that suite has its own temporary
TYPO3 installation, Playwright setup, CI artifacts, and maintenance rules.

.. toctree::
   :maxdepth: 1

   E2eSuite
   E2eCiDebugging
   ProtocolCompatibility
   ToolContextBenchmark

.. seealso::

   - ``Testing/CursorTesting.md`` — manual MCP-end-to-end testing through
     Cursor (Markdown source — see the file in the repository).
   - ``Testing/FullFeatureChatbotScript.md`` — natural-language test script
     for any MCP-connected chatbot.

.. _testing-overview:

Test layers
===========

The project uses several layers of tests:

- unit tests for pure PHP services and utilities
- functional tests for TYPO3 database, TCA, workspace, and DataHandler
  behavior
- LLM-oriented tests for MCP response ergonomics and model-facing workflows
- deterministic A/B measurements for tool-schema tax and response payloads
- Playwright E2E tests for browser-visible backend module workflows
- code-quality checks for PHPStan, PHP CS Fixer, Rector, and Fractor
- dual-era wire checks for MCP ``2025-11-25`` and ``2026-07-28``
- bundled Abilities CLI plus the MCP bridge that projects the registry into
  the tool catalog

The E2E suite is intentionally narrow. It verifies the TYPO3 backend module as
an editor sees it, while deeper MCP tool contracts stay in PHP tests.

Developer-tool coverage limits
==============================

The Content Blocks fixture currently covers package absence; an installed
package must be tested before claiming complete integration coverage.
TypoScript introspection compiles using an empty condition-variable context;
request-, language-, and user-dependent conditions can differ from a frontend
request. Schema benchmarks measure bytes and estimate tokens, not model task
success. Use the LLM suite for the latter.

.. _testing-primary-commands:

Primary commands
================

Use these commands during normal development:

.. code-block:: bash
   :caption: Run the full PHP test suite

   ddev exec composer test

.. code-block:: bash
   :caption: Run static analysis

   ddev exec composer phpstan

.. code-block:: bash
   :caption: Run the E2E suite

   bash Build/runTests.sh -s e2e

.. code-block:: bash
   :caption: Render the documentation

   composer docs:check
