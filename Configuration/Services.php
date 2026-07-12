<?php

declare(strict_types=1);

use Hn\McpServer\Integration\X402\X402PaymentVerifierAdapter;
use Hn\McpServer\Service\X402\NullX402PaymentVerifier;
use Hn\McpServer\Service\X402\X402PaymentVerifierInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ApiRegistry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Registry\AbilityInterface;
use Webconsulting\X402Paywall\Configuration\ConfigurationProvider;
use Webconsulting\X402Paywall\Configuration\PaywallConfiguration;
use Webconsulting\X402Paywall\Domain\Model\PaymentRequirement;

/**
 * Conditional integrations whose parent interfaces are optional Composer
 * suggestions. Keeping them out of Services.yaml preserves a clean install
 * when the integrations are absent.
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

    if (
        class_exists(ApiRegistry::class)
        && class_exists(ExtensionConfiguration::class)
        && class_exists(AbilitiesRegistry::class)
    ) {
        // The optional package may be present through Composer while its
        // extension service configuration is not part of a focused TYPO3
        // functional-test container. Register the two small dependencies
        // explicitly so this conditional integration remains compilable.
        $services->set(ApiRegistry::class)->public();
        $services->set(ExtensionConfiguration::class);
        $services->set(AbilitiesRegistry::class);
        $services->load(
            'Hn\\McpServer\\Integration\\ApiCore\\',
            __DIR__ . '/../Classes/Integration/ApiCore/',
        );
    }

    if (interface_exists(AbilityInterface::class)) {
        $services->load(
            'Hn\\McpServer\\Integration\\Abilities\\',
            __DIR__ . '/../Classes/Integration/Abilities/',
        );
    }

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
