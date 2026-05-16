<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\OAuthTokenEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class CorsHeadersTest extends AbstractFunctionalTest
{
    private mixed $previousRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        parent::tearDown();
    }

    private function createEndpoint(): OAuthTokenEndpoint
    {
        return new OAuthTokenEndpoint(
            GeneralUtility::makeInstance(LogManager::class)->getLogger(OAuthTokenEndpoint::class),
            $this->getContainer()->get(OAuthService::class),
        );
    }

    public function testCorsReflectsRequestOriginNotWildcard(): void
    {
        $endpoint = $this->createEndpoint();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp_oauth/token'),
            'OPTIONS',
            'php://input',
            ['Origin' => 'https://my-mcp-client.example.com']
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $endpoint($request);

        $origin = $response->getHeaderLine('Access-Control-Allow-Origin');
        self::assertNotEquals('*', $origin, 'CORS must NOT use wildcard origin');
        self::assertEquals('https://my-mcp-client.example.com', $origin);
    }

    public function testCorsWithoutOriginHeaderSkipsHeaders(): void
    {
        $endpoint = $this->createEndpoint();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp_oauth/token'),
            'OPTIONS'
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $endpoint($request);

        self::assertFalse(
            $response->hasHeader('Access-Control-Allow-Origin'),
            'No CORS headers should be set for non-CORS requests'
        );
    }
}
