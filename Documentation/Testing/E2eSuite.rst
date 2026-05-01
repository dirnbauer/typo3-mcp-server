.. include:: /Includes.rst.txt

.. _testing-e2e-suite:

=============
The E2E suite
=============

The E2E suite verifies the TYPO3 backend module in a real TYPO3 installation.
It does not mock the module template, JavaScript, AJAX routes, TYPO3 backend
frames, or TYPO3 modal behavior.

.. _testing-e2e-purpose:

What the suite proves
=====================

The suite checks that :guilabel:`User > MCP Server` works after the extension
has been installed into a fresh TYPO3 instance.

It covers:

- backend login with an administrator account
- loading the MCP Server backend module inside the TYPO3 content iframe
- stable setup-tab navigation
- token creation through the name modal and the "shown once" token modal
- token table refresh after AJAX calls
- token revoke confirmation behavior
- endpoint status indicators rendered by the module
- copy-button presence for setup snippets

It does not replace the PHP functional tests for MCP tool behavior. Tool
contracts, workspace transparency, table access, file handling, and language
overlay behavior are covered by PHPUnit and LLM-oriented tests. The E2E suite
exists to catch browser-level regressions that PHP tests cannot see.

.. _testing-e2e-files:

Important files
===============

.. list-table::
   :header-rows: 1
   :widths: 34 66

   * - File
     - Role
   * - :file:`Build/runTests.sh`
     - Orchestrates local and Docker E2E environments.
   * - :file:`Build/package.json`
     - Pins Playwright and exposes helper npm scripts.
   * - :file:`Build/tests/playwright/config.ts`
     - Reads base URL and backend credentials from environment variables.
   * - :file:`Build/tests/playwright/helper/login.setup.ts`
     - Logs into TYPO3 once and stores Playwright authentication state.
   * - :file:`Build/tests/playwright/fixtures/setup-fixtures.ts`
     - Provides page-object helpers for backend navigation and module access.
   * - :file:`Build/tests/playwright/e2e/mcp-module.spec.ts`
     - Contains the backend module workflow assertions.
   * - :file:`.github/workflows/tests.yml`
     - Runs the E2E suite in GitHub Actions.

.. _testing-e2e-command:

Primary command
===============

Run the suite through the project test runner:

.. code-block:: bash
   :caption: Run the E2E suite

   bash Build/runTests.sh -s e2e

When Docker is unavailable, the runner automatically falls back to local mode.
You can request local mode explicitly:

.. code-block:: bash
   :caption: Run E2E without Docker

   bash Build/runTests.sh -s e2e --no-docker

Any arguments after the suite options are passed to Playwright:

.. code-block:: bash
   :caption: Run one Playwright test by title

   bash Build/runTests.sh -s e2e -- --grep "tab navigation works"

.. _testing-e2e-local-mode:

Local no-Docker mode
====================

Local mode is the same mode used by GitHub Actions. It uses the current PHP
environment, SQLite, and the local Playwright browser cache.

The runner performs these steps:

1. Remove stale TYPO3 runtime state from :file:`var/`, :file:`config/system/`,
   and old SQLite files.
2. Run Composer install without scripts.
3. Execute :bash:`vendor/bin/typo3 setup` with SQLite.
4. Create an administrator account named ``admin`` with password
   ``Admin123!``.
5. Relax ``trustedHostsPattern`` and ``devIPmask`` for the temporary test
   server.
6. Start PHP's built-in web server on ``127.0.0.1:8080`` by default.
7. Wait until ``/typo3/`` responds.
8. Install Playwright npm dependencies when :file:`Build/node_modules` is
   missing.
9. Install the Chromium browser if needed.
10. Run :bash:`npx playwright test`.

The port can be changed with ``TYPO3_E2E_PORT``:

.. code-block:: bash
   :caption: Run local E2E on another port

   TYPO3_E2E_PORT=8090 bash Build/runTests.sh -s e2e --no-docker

The local web server writes to:

.. code-block:: text
   :caption: Local E2E web server log

   var/log/typo3-e2e-web.log

.. _testing-e2e-docker-mode:

Docker mode
===========

Docker mode is useful when the host should not provide PHP extensions,
Composer, a database, or browser dependencies. It creates an isolated Docker
network per run and starts:

- a MySQL 8.0 container with a tmpfs data directory
- a PHP 8.4 TYPO3 web container using :file:`public/`
- a Playwright container based on ``mcr.microsoft.com/playwright:v1.52.0-noble``

The Playwright container reaches TYPO3 through the Docker network at
``http://web:8080``. Containers and the temporary network are removed by the
cleanup trap when the script exits.

.. _testing-e2e-configuration:

Runtime configuration
=====================

Playwright reads configuration from :file:`Build/tests/playwright/config.ts`.

.. list-table::
   :header-rows: 1
   :widths: 30 30 40

   * - Variable
     - Default
     - Meaning
   * - ``TYPO3_BASE_URL``
     - ``http://localhost:8080``
     - Base URL for the temporary TYPO3 instance.
   * - ``TYPO3_ADMIN_USER``
     - ``admin``
     - Backend username used by the login setup.
   * - ``TYPO3_ADMIN_PASSWORD``
     - ``Admin123!``
     - Backend password used by the login setup.

The runner sets ``TYPO3_BASE_URL`` automatically. Override credentials only
when targeting a pre-existing TYPO3 instance outside the runner.

.. _testing-e2e-authentication:

Authentication flow
===================

The login setup project is defined in
:file:`Build/tests/playwright/helper/login.setup.ts`. It opens ``/typo3/``,
fills the TYPO3 backend login form, waits for the backend module menu, and
writes Playwright storage state to:

.. code-block:: text
   :caption: Playwright authentication state

   Build/tests/playwright/.auth/login.json

The E2E tests reuse that storage state, so each test starts authenticated
without logging in again. This keeps the module tests focused on MCP Server UI
behavior rather than TYPO3 login mechanics.

.. _testing-e2e-iframe:

TYPO3 iframe handling
=====================

TYPO3 backend modules render inside ``#typo3-contentIframe``. The test suite
therefore uses a Playwright ``FrameLocator``:

.. code-block:: typescript
   :caption: Backend module frame access

   frame = page.frameLocator('#typo3-contentIframe');
   await expect(frame.locator('.module-body')).toBeVisible();

Controls rendered by the MCP module are selected inside that frame. TYPO3
modals are different: the TYPO3 Modal API appends them to the top-level
document, not inside the content iframe. Tests that interact with token modals
therefore use ``page.locator('.modal')`` instead of ``frame.locator()``.

.. _testing-e2e-current-specs:

Current test cases
==================

``module page loads with expected sections``
   Verifies that ``#mcpSetupTabs`` and ``#tokens-container`` are present.

``tab navigation works``
   Clicks ``#local-mcp-remote-tab``, ``#local-cli-tab``, and
   ``#remote-setup-tab`` and verifies that the matching panels become visible.

``create token via central button shows name modal then token modal``
   Creates a token named ``test-token``, verifies the "Token Created" modal,
   checks that the shown token is a 64-character hexadecimal value, closes the
   modal, and confirms that ``test-token`` appears in the token table.

``revoke token shows confirmation modal``
   Uses an existing token when available. If no token exists, the test skips
   itself. Otherwise it opens the revoke confirmation modal and cancels it.

``refresh tokens button works``
   Clicks ``#refresh-tokens-btn`` and verifies that the token container remains
   visible after the AJAX refresh.

``endpoint status indicators exist``
   Confirms that at least one ``.endpoint-status`` indicator is rendered.

``copy buttons exist``
   Confirms that at least one setup copy button is rendered.

