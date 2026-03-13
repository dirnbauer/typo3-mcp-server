# TYPO3 Testing Audit

Date: 2026-03-13
Scope: `typo3-testing` skill pass for TYPO3 v13/v14

## Summary

Status: Improved baseline with dedicated unit testing now active alongside the existing functional suite.

Verified command results:

- `ddev exec vendor/bin/phpunit -c Tests/UnitTests.xml`: passes with `OK (2 tests, 8 assertions)`
- `ddev exec composer test`: passes with unit and functional suites
- `ddev exec composer php-cs-fixer`: passes
- `ddev exec composer phpstan`: passes

## Findings

### 1. Unit test infrastructure existed only implicitly

Severity: Medium

- The repository already had a `Tests/Unit/` directory, but no dedicated PHPUnit config.
- `composer test` only executed the functional suite.
- Result: pure logic checks were easy to miss and CI had no unit-test lane.

Resolution:

- Added `Tests/UnitTests.xml`.
- Added `composer test:unit`.
- Updated `composer test` to run unit tests before the functional suite.

### 2. CI did not exercise the unit suite or generate coverage

Severity: Medium

- CI only executed the functional matrix and code-quality checks.
- There was no unit-test job and no coverage artifact generation.

Resolution:

- Added a `unit-tests` GitHub Actions job.
- The job runs PHPUnit with Xdebug coverage enabled and writes `var/log/unit-coverage.xml`.

### 3. Existing unit coverage included a stale test against a removed API

Severity: Low

- `ToolAnnotationsTest` asserted a `getAnnotations()` method that no longer exists on the
  current tool interface.
- Result: enabling the unit suite surfaced false failures unrelated to current behavior.

Resolution:

- Removed the stale test.
- Added `Tests/Unit/Service/OAuthServiceTest.php` to cover current OAuth metadata and
  authorization URL behavior instead.

## Conformance state after remediation

- Dedicated unit test config present
- Unit tests runnable locally and in CI
- `composer test` now exercises unit and functional suites together
- Architecture rules remain active through PHPat + PHPStan
- Functional TYPO3 integration coverage remains green

## Residual gaps

These remain good next steps, but were not required to make the testing baseline operational:

- Very small unit-test surface area compared to the functional suite
- No browser/E2E workflow yet
- No mutation-testing setup
- No minimum coverage threshold enforced yet

## Assessment

Status: Testing foundation is now broader and more explicit, with clear room to deepen unit and end-to-end coverage later.
