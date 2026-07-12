<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\X402;

use Hn\McpServer\Service\X402\X402FacilitatorVerificationService;
use Hn\McpServer\Service\X402\X402PaymentRequirement as McpPaymentRequirement;
use Hn\McpServer\Service\X402\X402PaymentVerifierInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use Webconsulting\X402Paywall\Configuration\ConfigurationProvider;
use Webconsulting\X402Paywall\Configuration\PaywallConfiguration;
use Webconsulting\X402Paywall\Domain\Model\PaymentRequirement;

/** @internal Loaded only when webconsulting/typo3-x402-paywall is installed. */
final readonly class X402PaymentVerifierAdapter implements X402PaymentVerifierInterface
{
    public function __construct(
        private ConfigurationProvider $configurationProvider,
        private SiteFinder $siteFinder,
        private X402FacilitatorVerificationService $facilitatorVerification,
    ) {}

    public function prepareRequirement(
        int $pageUid,
        string $price,
        string $description,
    ): ?McpPaymentRequirement {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
            $configuration = $this->configurationProvider->getForSite($site->getIdentifier());
            if (!$configuration->isValid()) {
                return null;
            }

            $base = $site->getBase();
            $basePath = rtrim($base->getPath(), '/');
            $resource = (string)$base
                ->withPath($basePath . '/api/v1/content/' . $pageUid)
                ->withQuery('')
                ->withFragment('');
            $requirement = PaymentRequirement::fromConfig(
                $configuration,
                $resource,
                $price,
                $description,
            );

            return new McpPaymentRequirement(
                encoded: $requirement->toHeaderValue(),
                data: $requirement->toArray(),
                currency: $configuration->currency,
                network: $configuration->network,
                nativeConfiguration: $configuration,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    public function verifyAndSettle(
        string $paymentProof,
        McpPaymentRequirement $requirement,
    ): bool {
        if (!$requirement->nativeConfiguration instanceof PaywallConfiguration) {
            return false;
        }

        return $this->facilitatorVerification->verifyAndSettle(
            $requirement->nativeConfiguration->facilitatorUrl,
            $paymentProof,
            $requirement->data,
        );
    }
}
