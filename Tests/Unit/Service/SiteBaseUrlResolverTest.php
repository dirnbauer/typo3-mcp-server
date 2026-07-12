<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Service\SiteBaseUrlResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;
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

    #[Test]
    public function resolveFromRequestPreservesHttpDefaultPortOnHttpsUri(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl']);

        $resolver = new SiteBaseUrlResolver();
        $request = new ServerRequest(new Uri('https://example.com:80/mcp'));

        self::assertSame('https://example.com:80', $resolver->resolveFromRequest($request));
    }

    #[Test]
    public function resolveFromRequestPreservesHttpsDefaultPortOnHttpUri(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl']);

        $resolver = new SiteBaseUrlResolver();
        $request = new ServerRequest(new Uri('http://example.com:443/mcp'));

        self::assertSame('http://example.com:443', $resolver->resolveFromRequest($request));
    }

    #[Test]
    public function resolveFromRequestPreservesBracketedIpv6Authority(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl']);

        $resolver = new SiteBaseUrlResolver();
        $request = new ServerRequest(new Uri('https://[2001:db8::1]:8443/mcp'));

        self::assertSame('https://[2001:db8::1]:8443', $resolver->resolveFromRequest($request));
    }

    #[Test]
    public function resolveFromRequestBracketsIpv6HostFromPsrUri(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl']);

        $uri = self::createStub(UriInterface::class);
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getHost')->willReturn('2001:db8::1');
        $uri->method('getPort')->willReturn(8443);

        $resolver = new SiteBaseUrlResolver();
        $request = new ServerRequest($uri);

        self::assertSame('https://[2001:db8::1]:8443', $resolver->resolveFromRequest($request));
    }

    #[Test]
    public function resolveFromRequestOmitsSchemeSpecificDefaultPorts(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl']);

        $resolver = new SiteBaseUrlResolver();

        self::assertSame(
            'http://example.com',
            $resolver->resolveFromRequest(new ServerRequest(new Uri('http://example.com:80/mcp'))),
        );
        self::assertSame(
            'https://example.com',
            $resolver->resolveFromRequest(new ServerRequest(new Uri('https://example.com:443/mcp'))),
        );
    }

    #[Test]
    public function resolveConfiguredOrPlaceholderUsesPlaceholderWhenUnset(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl']);

        $resolver = new SiteBaseUrlResolver();

        self::assertSame('https://your-domain.com', $resolver->resolveConfiguredOrPlaceholder());
    }
}
