<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\X402;

use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\LocalModeService;
use Hn\McpServer\Service\OutboundUrlGuardService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Performs the x402 v2 facilitator /verify + /settle exchange under MCP's
 * outbound policy. Content is released only after settlement succeeds.
 */
final readonly class X402FacilitatorVerificationService
{
    private const MAX_RESPONSE_BYTES = 64 * 1024;

    /**
     * @param (\Closure(string, string, array<string, mixed>): ResponseInterface)|null $request Test seam
     */
    public function __construct(
        private RequestFactory $requestFactory,
        private CapabilityManifestService $capabilityManifest,
        private LocalModeService $localMode,
        private OutboundUrlGuardService $outboundUrlGuard,
        private ?\Closure $request = null,
    ) {}

    /**
     * @param array<string, mixed> $paymentRequirement
     */
    public function verifyAndSettle(
        string $facilitatorUrl,
        string $paymentProof,
        array $paymentRequirement,
    ): bool {
        try {
            $paymentPayload = $this->decodePaymentPayload($paymentProof);
            if (($paymentPayload['x402Version'] ?? null) !== 2) {
                return false;
            }

            $requestBody = [
                'x402Version' => 2,
                'paymentPayload' => $paymentPayload,
                'paymentRequirements' => $paymentRequirement,
            ];
            $verification = $this->postToFacilitator($facilitatorUrl, 'verify', $requestBody);
            if (($verification['isValid'] ?? null) !== true) {
                return false;
            }

            $settlement = $this->postToFacilitator($facilitatorUrl, 'settle', $requestBody);
        } catch (\Throwable) {
            return false;
        }

        return ($settlement['success'] ?? null) === true
            && is_string($settlement['transaction'] ?? null)
            && $settlement['transaction'] !== '';
    }

    /**
     * @param array<string, mixed> $requestBody
     * @return array<string, mixed>
     */
    private function postToFacilitator(string $facilitatorUrl, string $operation, array $requestBody): array
    {
        $url = rtrim($facilitatorUrl, '/') . '/' . $operation;
        $this->capabilityManifest->assertUrlAllowed($url);
        $curlResolveEntry = $this->localMode->allowsUnrestrictedOutbound()
            ? null
            : $this->outboundUrlGuard->assertPublicAndCreateCurlResolveEntry($url);

        $options = [
            'json' => $requestBody,
            'timeout' => 30,
            'http_errors' => false,
            'allow_redirects' => false,
            'stream' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];
        if ($curlResolveEntry !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$curlResolveEntry]];
        }

        $response = $this->request instanceof \Closure
            ? ($this->request)($url, 'POST', $options)
            : $this->requestFactory->request($url, 'POST', $options);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('The x402 facilitator rejected the ' . $operation . ' request.');
        }
        $declaredLength = $response->getHeaderLine('Content-Length');
        if (ctype_digit($declaredLength) && (int)$declaredLength > self::MAX_RESPONSE_BYTES) {
            throw new \LengthException('x402 facilitator response exceeded the maximum size.');
        }

        $body = json_decode(
            $this->readBoundedBody($response->getBody()),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        if (!is_array($body)) {
            throw new \UnexpectedValueException('The x402 facilitator returned a non-object response.');
        }

        $normalized = [];
        foreach ($body as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }

    /** @return array<string, mixed> */
    private function decodePaymentPayload(string $paymentProof): array
    {
        $decoded = base64_decode($paymentProof, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('PAYMENT-SIGNATURE is not valid base64.');
        }
        $payload = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('PAYMENT-SIGNATURE does not contain an object.');
        }

        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }

    private function readBoundedBody(StreamInterface $stream): string
    {
        $body = '';
        while (!$stream->eof()) {
            $remaining = self::MAX_RESPONSE_BYTES - strlen($body);
            if ($remaining < 0) {
                throw new \LengthException('x402 verification response exceeded the maximum size.');
            }
            $chunk = $stream->read(min(8192, $remaining + 1));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                throw new \LengthException('x402 verification response exceeded the maximum size.');
            }
        }

        return $body;
    }
}
