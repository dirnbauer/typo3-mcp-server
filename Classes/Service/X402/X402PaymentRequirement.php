<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\X402;

/**
 * Prepared by the optional typo3-x402-paywall integration.
 *
 * The native configuration is deliberately opaque here so mcp_server can be
 * installed without loading any classes from the optional paywall package.
 */
final readonly class X402PaymentRequirement
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $encoded,
        public array $data,
        public string $currency,
        public string $network,
        public object $nativeConfiguration,
    ) {}
}
