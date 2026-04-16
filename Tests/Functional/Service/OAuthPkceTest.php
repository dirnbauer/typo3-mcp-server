<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests for PKCE S256 enforcement
 */
class OAuthPkceTest extends AbstractFunctionalTest
{
    private OAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = GeneralUtility::makeInstance(OAuthService::class);
    }

    public function testPkceMustBeProvidedForCodeExchange(): void
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = $this->service->createAuthorizationCode(1, 'test-client', '', $challenge, 'S256');
        $result = $this->service->exchangeCodeForToken($code);

        self::assertNull($result, 'Exchange without verifier must fail when challenge was set');
    }

    public function testPkceWithCorrectVerifierSucceeds(): void
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = $this->service->createAuthorizationCode(1, 'test-client', '', $challenge, 'S256');
        $result = $this->service->exchangeCodeForToken($code, $verifier);

        self::assertNotNull($result);
        self::assertArrayHasKey('access_token', $result);
    }

    public function testPkceWithWrongVerifierFails(): void
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = $this->service->createAuthorizationCode(1, 'test-client', '', $challenge, 'S256');
        $result = $this->service->exchangeCodeForToken($code, 'wrong-verifier');

        self::assertNull($result);
    }

    public function testPkceWithEmptyStringVerifierFails(): void
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = $this->service->createAuthorizationCode(1, 'test-client', '', $challenge, 'S256');
        $result = $this->service->exchangeCodeForToken($code, '');

        self::assertNull($result, 'Empty string verifier must fail');
    }

    public function testPkceWithUnsupportedChallengeMethodFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createAuthorizationCode(1, 'test-client', '', 'some-plain-challenge', 'plain');
    }

    public function testCodeExchangeSucceedsWithoutPkceChallenge(): void
    {
        $code = $this->service->createAuthorizationCode(1, 'test-client');
        $result = $this->service->exchangeCodeForToken($code);

        self::assertNotNull($result, 'Code without PKCE should still exchange');
    }

    public function testMetadataOnlyAdvertisesS256(): void
    {
        $metadata = $this->service->getMetadata('https://example.com');

        self::assertEquals(['S256'], $metadata['code_challenge_methods_supported']);
    }
}
