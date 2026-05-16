<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Controller;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests that token creation, validation, and listing work correctly
 * with hashed storage.
 */
class McpServerModuleControllerTokenResponseTest extends AbstractFunctionalTest
{
    private OAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = GeneralUtility::makeInstance(OAuthService::class);
    }

    public function testCreateDirectAccessTokenReturns64CharHexString(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $plainToken,
            'createDirectAccessToken must return a 64-char hex string'
        );
    }

    public function testPlainTokenCanBeValidated(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');
        $result = $this->service->validateToken($plainToken);

        self::assertNotNull($result, 'Plain token returned by createDirectAccessToken must validate');
        self::assertEquals(1, $result['be_user_uid']);
        self::assertEquals('test-client', $result['client_name']);
    }

    public function testGetUserTokensReturnsHashedTokenNotPlainToken(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');
        $expectedHash = hash('sha256', $plainToken);

        $tokens = $this->service->getUserTokens(1);

        self::assertNotEmpty($tokens, 'getUserTokens must return at least one token');

        $latestToken = $tokens[0];
        self::assertArrayHasKey('token', $latestToken, 'Token row must have a "token" column');
        self::assertEquals(
            $expectedHash,
            $latestToken['token'],
            'The "token" field in getUserTokens must be the SHA-256 hash, not the plain token'
        );
        self::assertNotEquals(
            $plainToken,
            $latestToken['token'],
            'getUserTokens must never expose the plain token'
        );
    }
}
