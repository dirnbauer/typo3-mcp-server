.. include:: /Includes.rst.txt

.. _testing-e2e-ci-debugging:

====================
E2E CI and debugging
====================

This page documents how the Playwright suite runs in GitHub Actions and how to
debug failures from CI artifacts or local logs.

.. _testing-e2e-ci:

GitHub Actions
==============

The workflow job is named ``E2E Tests`` in :file:`.github/workflows/tests.yml`.
It runs on ``ubuntu-latest`` with PHP 8.4, Node.js 22, SQLite, and Playwright
1.52.0.

The CI job performs these steps:

1. Check out the repository.
2. Install PHP with ``sqlite3``, ``pdo_sqlite``, and ``intl``.
3. Install Composer dependencies.
4. Run :bash:`npm ci` in :file:`Build/`.
5. Install Chromium with Playwright system dependencies.
6. Run :bash:`bash Build/runTests.sh -s e2e --no-docker`.
7. Upload :file:`Build/playwright-report` and :file:`Build/test-results` when
   the job fails.

The uploaded artifact is named ``playwright-report`` and is retained for seven
days. Use it to inspect screenshots, traces, and Playwright's
``error-context.md`` files.

.. _testing-e2e-debugging:

Debugging failures
==================

When E2E fails, check these sources in order:

1. GitHub Actions job log for the failing Playwright assertion.
2. The ``playwright-report`` artifact for screenshots, traces, and page
   snapshots.
3. :file:`Build/test-results/**/error-context.md` for the accessibility-style
   page snapshot at the failure point.
4. :file:`var/log/typo3-e2e-web.log` for local TYPO3 web-server errors.
5. Browser network entries in the Playwright trace for failed AJAX requests.

.. _testing-e2e-common-failures:

Common failure patterns
=======================

Missing iframe selector
   The module did not load, the backend route changed, or the login setup did
   not produce a valid backend session.

Missing tab selector
   The template changed without keeping stable IDs such as
   ``#local-mcp-remote-tab``.

Missing "Token Created" modal
   The create-token AJAX request failed. Inspect the trace network tab for the
   ``/typo3/ajax/mcp-server/create-token`` request and its JSON response.

Top-level modal not found
   The test may be looking in the iframe even though TYPO3 moved the modal to
   the top document.

Browser dependency failure
   Chromium could not start because the local environment is missing Linux
   browser libraries. Install Playwright dependencies for that environment, or
   use Docker mode.

.. _testing-e2e-artifacts:

Artifact workflow
=================

On CI failure, download the ``playwright-report`` artifact from the failed run.
It contains:

- :file:`Build/playwright-report` with the HTML report
- :file:`Build/test-results` with screenshots, traces, and error contexts
- per-test :file:`error-context.md` files with page snapshots
- trace archives that include network requests and DOM snapshots

Network traces are especially useful for token-management failures. The token
creation flow should send a POST request to
``/typo3/ajax/mcp-server/create-token`` and receive JSON with ``success: true``
and a ``token`` value.

.. _testing-e2e-maintenance:

Maintenance rules
=================

Keep E2E tests user-facing and stable:

- Prefer stable IDs for module controls that tests must click.
- Keep TYPO3 modal assertions at top-page level.
- Keep module-content assertions inside ``#typo3-contentIframe``.
- Do not assert decorative markup or translated implementation details unless
  the text is part of the workflow contract.
- Add E2E coverage when a change affects backend module navigation, token
  management, AJAX route wiring, health checks, or TYPO3 modal behavior.
- Keep deeper MCP tool semantics in PHPUnit functional tests; browser tests
  should not duplicate large record-tool scenarios.

.. _testing-e2e-local-environment-notes:

Local environment notes
=======================

Local no-Docker mode depends on browser libraries being present on the machine
that runs Playwright. If Chromium cannot start, install Playwright's browser
dependencies for the local environment, or use Docker mode.

Node.js should satisfy the range in :file:`Build/package.json`:
``>=22.18.0 <23.0.0``. CI uses Node.js 22.

The local web server is temporary and is stopped by the cleanup trap in
:file:`Build/runTests.sh`. If a run is interrupted and port ``8080`` remains
busy, stop the stale PHP process or run with a different ``TYPO3_E2E_PORT``.

