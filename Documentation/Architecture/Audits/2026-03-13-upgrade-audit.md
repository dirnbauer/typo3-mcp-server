# TYPO3 Extension Upgrade Audit

Date: 2026-03-13
Scope: `typo3-extension-upgrade` skill recheck for TYPO3 v13/v14 support

## Current state

- Version constraints are already aligned to TYPO3 `^13.4 || ^14.0` and PHP `>=8.2`.
- `rector.php` and `fractor.php` are present.
- `composer.json` now declares `ssch/typo3-rector` and `a9f/typo3-fractor`.

## Findings

### 1. Tooling is declared but not installed in the current lock/vendor state

Severity: Medium

- `vendor/bin` currently does not include `rector`, `fractor`, or `phpstan`.
- The repository has a `composer.lock`, but the upgrade QA tools are not available in
  the installed vendor state yet.
- Result: the upgrade workflow cannot be verified from the current checkout.

### 2. No project container/runtime exists yet for the required PHP version

Severity: Medium

- Host PHP is `8.1.33`.
- TYPO3 v14 packages require PHP 8.2+, so host-side verification is insufficient.
- The repository is not a DDEV project yet (`.ddev/config.yaml` missing).
- Result: Rector, Fractor, PHPStan, and the TYPO3 test bootstrap cannot be run in the
  intended runtime without first creating a containerized environment.

### 3. `Build/setup-typo3.sh` assumes `public/` already exists

Severity: Medium

- The script writes `public/index.php` but does not create `public/` first.
- On a fresh checkout this can fail during Composer post-install/post-update hooks.
- Result: test bootstrap is fragile and may fail before TYPO3 setup completes.

## Recommended changes

1. Add a minimal `.ddev/` configuration using PHP 8.3 for extension QA.
2. Reconcile dependency installation inside DDEV so the declared QA tools are actually
   available in `vendor/bin`.
3. Harden `Build/setup-typo3.sh` by creating required directories up front.
4. Run the real upgrade checks in containerized PHP:
   - `ddev composer install`
   - `ddev composer rector`
   - `ddev composer fractor`
   - `ddev composer phpstan`
   - `ddev composer test`

## Acceptance criteria for this audit pass

- DDEV project exists and starts successfully.
- TYPO3 setup bootstrap works on a fresh checkout.
- Rector and Fractor can be invoked in a PHP 8.2+ runtime.
- Remaining upgrade issues are captured by command output rather than assumptions.
