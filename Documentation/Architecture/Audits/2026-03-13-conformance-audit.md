# TYPO3 Conformance Audit

Date: 2026-03-13
Scope: `typo3-conformance` skill pass for TYPO3 v13/v14

## Summary

Status: Improved, with core quality gates now enforced in-repo and in CI.

Verified command results:

- `ddev exec composer php-cs-fixer`: passes
- `ddev exec composer phpstan`: passes
- `ddev exec composer test`: passes with `OK (412 tests, 2156 assertions)`

## Findings

### 1. Architecture rules were not enforced

Severity: Medium

- The repository had no `Tests/Architecture/` rules.
- PHPat was not installed or wired into the analysis pipeline.
- Result: architectural boundaries were implicit only.

Resolution:

- Added `phpat/phpat` as a dev dependency.
- Included `vendor/phpat/phpat/extension.neon` in `phpstan.neon`.
- Added `Tests/Architecture/ArchitectureTest.php` with initial boundary rules for
  service and MCP tool layers.
- Added `Tests/Architecture` to the analyzed PHPStan paths so architecture rules run
  as part of the main static-analysis gate.

### 2. Existing coding-style configuration was present but not enforced

Severity: Medium

- `.php-cs-fixer.php` already existed, but `friendsofphp/php-cs-fixer` was not installed.
- `composer.json` had no script for running the fixer.
- CI did not execute the fixer, Rector, or Fractor.

Resolution:

- Added `friendsofphp/php-cs-fixer` as a dev dependency.
- Added `composer php-cs-fixer` and `composer php-cs-fixer:fix` scripts.
- Added CI steps for:
  - PHP CS Fixer
  - Rector dry-run
  - Fractor dry-run
- Fixed the stale `strict_types` fixer rule name in `.php-cs-fixer.php`.

### 3. Bootstrap metadata files were missing `strict_types`

Severity: Low

- `ext_emconf.php` and `ext_localconf.php` did not declare strict types.
- Result: the extension missed one of the skill’s baseline PHP-file conformance checks.

Resolution:

- Added `declare(strict_types=1);` to both files.

## Conformance state after remediation

### Active gates

- PHP 8.2+ minimum declared
- TYPO3 `^13.4 || ^14.0` declared
- PHPStan level 9 active
- PHPat architecture rules active via PHPStan
- PHP CS Fixer active
- Rector dry-run active
- Fractor dry-run active
- Functional test matrix present in CI

### Residual gaps

These are not blocking for this pass, but they keep the repository short of a
fully maxed-out score:

- No dedicated `Tests/Unit/` suite yet
- No coverage threshold or coverage report gate in CI
- No E2E browser test suite
- No mutation-testing setup

## Assessment

Score: 88/122
Grade: A
Status: Production ready, with residual quality improvements still possible in testing depth.
