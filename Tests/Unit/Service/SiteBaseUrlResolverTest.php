<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Service\SiteBaseUrlResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;

final class SiteBaseUrlResolverTest extends TestCase
{
    #[Test]
    public function resolveFromRequestPrefersConfiguredReverseProxyBaseUrl(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl'] = 'https://public.example.com/';

        try {
            $resolver = new SiteBaseUrlResolver();
            $request = new ServerRequest(new Uri('https://backend.local/typo3/module'));

            self::assertSame('https://public.example.com', $resolver->resolveFromRequest($request));
            self::assertTrue($resolver->hasConfiguredBaseUrl());
        } finally {
            unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl']);
        }
    }

    #[Test]
    public function resolveFromRequestBuildsFromRequestWhenNotConfigured(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl']);

        $resolver = new SiteBaseUrlResolver();
        $request = new ServerRequest(new Uri('https://example.com:8443/mcp'));

        self::assertSame('https://example.com:8443', $resolver->resolveFromRequest($request));
        self::assertFalse($resolver->hasConfiguredBaseUrl());
    }
}
