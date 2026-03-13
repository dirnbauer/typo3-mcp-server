# TYPO3 Extension Upgrade Audit

Date: 2026-03-13
Scope: `typo3-extension-upgrade` skill recheck for TYPO3 v13/v14 support

## Current state

- Version constraints are already aligned to TYPO3 `^13.4 || ^14.0` and PHP `>=8.2`.
- `rector.php` and `fractor.php` are present.
- `composer.json` now declares `ssch/typo3-rector` and `a9f/typo3-fractor`.
- A DDEV project now exists with PHP `8.3` in `.ddev/config.yaml`.
- `Build/setup-typo3.sh` now creates `public/` and `var/` before writing bootstrap files.

## Findings

### 1. Containerized upgrade runtime is available and working

Severity: Resolved

- `ddev exec composer rector` runs successfully in PHP `8.3`.
- `ddev exec composer fractor` runs successfully in PHP `8.3`.
- `ddev exec composer phpstan` runs successfully in PHP `8.3`.
- `ddev exec composer test` runs successfully in PHP `8.3`.
- Result: the declared upgrade workflow can now be verified from the current checkout.

### 2. Rector applies a meaningful modernization pass and remains green after application

Severity: Resolved

- `ddev exec composer rector` initially reported changes in `76` files.
- The Rector pass has now been applied.
- After applying the changes:
  - `ddev exec composer phpstan` reports no errors.
  - `ddev exec composer test` reports `OK (412 tests, 2156 assertions)`.
- Result: the current Rector rule set is compatible with the extension and does not regress
  the functional suite.

### 3. Fractor is installed and currently clean on the configured paths

Severity: Resolved

- `ddev exec composer fractor` completes with no changes.
- Result: there are no current non-PHP upgrade migrations required on the configured
  `Configuration/` and `Resources/` paths.

### 4. Installed Rector/Fractor packages do not currently expose explicit TYPO3 v14 level sets

Severity: Low

- Installed versions:
  - `ssch/typo3-rector` `v3.13.0`
  - `a9f/typo3-fractor` `v0.4.2`
- The installed packages expose TYPO3 13 level sets, but no explicit TYPO3 14 level set
  identifiers were found in the installed package sources.
- Result: v14 compatibility is currently validated by green PHPStan/tests in a PHP 8.3
  runtime rather than by a dedicated TYPO3 14 Rector/Fractor rule set.

## Recommended changes

1. Keep the DDEV-based PHP `8.3` workflow as the canonical upgrade/runtime verification path.
2. Keep Rector in the QA loop; it now applies cleanly and leaves the suite green after changes.
3. Keep Fractor in the QA loop; it is currently clean and acts as a regression check.
4. Watch for future TYPO3 14-specific Rector/Fractor releases and expand `rector.php` /
   `fractor.php` when such rule sets become available.

## Acceptance criteria for this audit pass

- [x] DDEV project exists and starts successfully.
- [x] TYPO3 setup bootstrap works on a fresh checkout.
- [x] Rector and Fractor can be invoked in a PHP 8.2+ runtime.
- [x] Remaining upgrade issues are captured by command output rather than assumptions.
- [x] `ddev exec composer phpstan` passes.
- [x] `ddev exec composer test` passes.
