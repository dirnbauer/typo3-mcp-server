<?php

declare(strict_types=1);

use Hn\McpServer\Integration\X402\X402PaymentVerifierAdapter;
use Hn\McpServer\Service\X402\NullX402PaymentVerifier;
use Hn\McpServer\Service\X402\X402PaymentVerifierInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Webconsulting\X402Paywall\Configuration\ConfigurationProvider;
use Webconsulting\X402Paywall\Configuration\PaywallConfiguration;
use Webconsulting\X402Paywall\Domain\Model\PaymentRequirement;

/**
 * Integration services kept in PHP configuration because the optional x402
 * adapter still needs class-based conditional registration.
 */
return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();
    $services->defaults()->private()->autowire()->autoconfigure();

    // Services.php is loaded before Services.yaml in TYPO3. Establish the
    // fail-closed alias here, then replace it only when every optional API used
    // by the adapter is autoloadable. The concrete null service is registered
    // by the normal Hn\McpServer\ resource scan in Services.yaml afterwards.
    $services->alias(
        X402PaymentVerifierInterface::class,
        NullX402PaymentVerifier::class,
    );

    // webconsulting/typo3-abilities is a production requirement: the catalog
    // abilities below are collected by its registry through the
    // "abilities.ability" tag, and AbilityToolBridge projects every
    // MCP-exposed ability back into the ToolRegistry. AbilityTool instances
    // are created by the bridge, never by the container.
    $services->load(
        'Hn\\McpServer\\Integration\\Abilities\\',
        __DIR__ . '/../Classes/Integration/Abilities/',
    )->exclude([
        __DIR__ . '/../Classes/Integration/Abilities/AbilityTool.php',
    ]);

    if (
        class_exists(ConfigurationProvider::class)
        && class_exists(PaywallConfiguration::class)
        && class_exists(PaymentRequirement::class)
    ) {
        // Composer may expose the optional classes even when focused test
        // containers do not load the extension's Services.yaml. The provider
        // depends only on TYPO3 core services, so registering it explicitly is
        // safe; the adapter still fails closed for missing/invalid site config.
        $services->set(ConfigurationProvider::class);
        $services->load(
            'Hn\\McpServer\\Integration\\X402\\',
            __DIR__ . '/../Classes/Integration/X402/',
        );
        $services->alias(
            X402PaymentVerifierInterface::class,
            X402PaymentVerifierAdapter::class,
        );
    }
};
