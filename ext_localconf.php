<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Load bundled libraries autoloader for non-composer installations (TER)
// In composer mode, these classes are already autoloaded via the main autoloader
$bundledAutoloader = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('mcp_server')
    . 'Resources/Private/PHP/vendor/autoload.php';
if (!\TYPO3\CMS\Core\Core\Environment::isComposerMode() && file_exists($bundledAutoloader)) {
    require_once $bundledAutoloader;
}

// Optional, governed REST/OpenAPI projection. The dirnbauer TYPO3 v14 fork of
// sg_apicore supplies backend-user-bound tokens, scopes, tenant context,
// default-deny CORS, rate limiting, request IDs, redacted logs, and OpenAPI.
// MCP tools remain implemented here and retain their workspace/capability
// guards; sg_apicore exposes the shared Abilities projection, not a second
// record-write implementation.
if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('sg_apicore')
    && \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('abilities')
    && class_exists(\SGalinski\SgApiCore\Service\ApiRegistry::class)
    && class_exists(\Webconsulting\Abilities\Registry\AbilitiesRegistry::class)
) {
    $apiRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
        \SGalinski\SgApiCore\Service\ApiRegistry::class,
    );
    $apiCoreConfiguration = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
        \SGalinski\SgApiCore\Configuration\ExtensionConfiguration::class,
    );
    (new \Hn\McpServer\Integration\ApiCore\AbilitiesApiPolicyEnforcer(
        $apiRegistry,
        $apiCoreConfiguration,
    ))->enforce();
}
