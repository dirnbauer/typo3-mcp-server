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

.. seealso::

   - :doc:`CursorTesting` — manual MCP-end-to-end testing through Cursor.
   - ``FullFeatureChatbotScript.md`` — natural-language test script for any
     MCP-connected chatbot.

.. _testing-overview:

Test layers
===========

The project uses several layers of tests:

- unit tests for pure PHP services and utilities
- functional tests for TYPO3 database, TCA, workspace, and DataHandler
  behavior
- LLM-oriented tests for MCP response ergonomics and model-facing workflows
- Playwright E2E tests for browser-visible backend module workflows
- code-quality checks for PHPStan, PHP CS Fixer, Rector, and Fractor

The E2E suite is intentionally narrow. It verifies the TYPO3 backend module as
an editor sees it, while deeper MCP tool contracts stay in PHP tests.

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

   ddev exec bash Build/runTests.sh -s e2e --no-docker

.. code-block:: bash
   :caption: Render the documentation

   composer docs:check

