<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\X402;

interface X402PaymentVerifierInterface
{
    public function prepareRequirement(
        int $pageUid,
        string $price,
        string $description,
    ): ?X402PaymentRequirement;

    public function verifyAndSettle(
        string $paymentProof,
        X402PaymentRequirement $requirement,
    ): bool;
}
