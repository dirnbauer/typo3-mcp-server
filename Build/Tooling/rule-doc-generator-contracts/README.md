# RuleDocGenerator contracts — TYPO3 MCP Server tooling

This package contains the complete runtime source of
`symplify/rule-doc-generator-contracts` 11.2.0, with its original MIT license.
It is a compatibility maintenance fork for tools that still use those contracts,
including Fractor. Its complete source and tests live inside TYPO3 MCP Server; it has no
independent repository or release process.

The original author is Tomas Votruba. The compatibility baseline is upstream
commit `479cfcfd46047f80624aba931d9789e50475b5c6`:
[upstream source](https://github.com/symplify/rule-doc-generator-contracts/tree/11.2.0).

## Scope and compatibility

- All 13 runtime PHP files are included: five interfaces, two exceptions, the
  abstract code sample, four concrete code samples and `RuleDefinition`.
- All `Symplify\RuleDocGenerator` namespaces, public class/interface names,
  constructor parameter names, public methods and signatures are preserved.
- Runtime PHP remains `>=8.1`, with no runtime dependencies. Two private arrays
  assigned only in constructors are now readonly; public behavior is unchanged.
- Composer's `replace` claim is restricted to upstream version **11.2.0**,
  the exact API implemented here. It does not claim compatibility with an
  unknown future API, or remove an audit warning while leaving code missing.
- This package's source and tests are owned and reviewed in the TYPO3 MCP Server repository. It
  is not a dependency alias or an empty package. Updating TYPO3 MCP Server
  tooling includes responsibility for these contracts.

The fixture in `tests/Fixtures/upstream-11.2-api.json` records the public API
reflected from the original package, before modification. The API test compares
every exported symbol with that baseline. Behavioral tests cover normalization,
invalid inputs, configurable examples, supplementary files, metadata lifecycle
and consumers implementing the rule, category and printer interfaces.

## Local validation

```bash
composer install
composer check
```

TYPO3 MCP Server CI runs PHP 8.1, 8.2, 8.3, 8.4 and 8.5 without optional failure
legs. It runs Composer validation and audit, PHP lint, coding standards,
PHPStan with unused-code detection, Rector dry-run, commented-code detection
and the tests. PHPUnit resolves to a maintained compatible line for each PHP
version. No audit exclusion or static-analysis baseline is configured.

The former abandoned development tools are replaced with their maintained
successors: PHP-CS-Fixer for coding standards, ShipMonk's detector for unused
members, and Rector Swiss Knife for commented code. Rector and PHP lint remain
active. The original public API is deliberately kept even where a tool's
consumer is outside this repository.

## TYPO3 MCP Server integration

TYPO3 MCP Server requires this package only in `require-dev` and resolves it from the
committed `Build/Tooling/rule-doc-generator-contracts` path. The path repository
assigns internal package version `1.0.0`; this is independent of TYPO3 MCP Server's
application version. Its full implementation satisfies the exact upstream
11.2.0 contract required by Fractor. Fractor's audit and cold dry-run checks
remain active in CI.

The parent archive configuration retains this package's source, license and
Composer metadata. Tests and development dependencies stay out of dist archives.
The site runtime does not install this tooling package. No separate Packagist
publication is required.
