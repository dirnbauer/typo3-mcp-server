<?php

declare(strict_types=1);

namespace Webconsulting\X402Paywall\Configuration;

final class PaywallConfiguration
{
    public string $currency;
    public string $network;
    public string $facilitatorUrl;

    public function isValid(): bool {}
}

final class ConfigurationProvider
{
    public function getForSite(string $siteIdentifier): PaywallConfiguration {}
}

namespace Webconsulting\X402Paywall\Domain\Model;

use Webconsulting\X402Paywall\Configuration\PaywallConfiguration;

final class PaymentRequirement
{
    public static function fromConfig(
        PaywallConfiguration $config,
        string $requestUri,
        string $price,
        string $contentDescription = '',
    ): self {}

    public function toHeaderValue(): string {}

    /** @return array<string, mixed> */
    public function toArray(): array {}
}
