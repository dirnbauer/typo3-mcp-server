<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\X402;

/**
 * Fail-closed verifier used when typo3-x402-paywall is not installed.
 */
final readonly class NullX402PaymentVerifier implements X402PaymentVerifierInterface
{
    public function prepareRequirement(
        int $pageUid,
        string $price,
        string $description,
    ): ?X402PaymentRequirement {
        return null;
    }

    public function verifyAndSettle(
        string $paymentProof,
        X402PaymentRequirement $requirement,
    ): bool {
        return false;
    }
}
