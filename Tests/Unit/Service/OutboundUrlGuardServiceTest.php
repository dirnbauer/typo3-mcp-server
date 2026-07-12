<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\OutboundUrlGuardService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OutboundUrlGuardServiceTest extends TestCase
{
    #[Test]
    public function ipLiteralIsValidatedWithoutDnsPinning(): void
    {
        $service = new OutboundUrlGuardService();

        self::assertNull($service->assertPublicAndCreateCurlResolveEntry('https://8.8.8.8/file'));
    }

    #[Test]
    public function privateIpLiteralIsRejected(): void
    {
        $service = new OutboundUrlGuardService();

        $this->expectException(ValidationException::class);
        $service->assertPublicAndCreateCurlResolveEntry('http://127.0.0.1/private');
    }

    #[Test]
    public function anyPrivateAddressInMixedDnsResponseRejectsTheHost(): void
    {
        $service = new OutboundUrlGuardService(
            static fn(string $host): array => ['8.8.8.8', 'fd00::1'],
        );

        $this->expectException(ValidationException::class);
        $service->assertPublicAndCreateCurlResolveEntry('https://mixed.example/file');
    }

    #[Test]
    public function validatedIpv4AddressIsPinnedToTheRequestedPort(): void
    {
        $service = new OutboundUrlGuardService(
            static fn(string $host): array => ['8.8.4.4'],
        );

        self::assertSame(
            'public.example:8443:8.8.4.4',
            $service->assertPublicAndCreateCurlResolveEntry('https://public.example:8443/file'),
        );
    }

    #[Test]
    public function validatedIpv6AddressUsesCurlBracketSyntax(): void
    {
        $service = new OutboundUrlGuardService(
            static fn(string $host): array => ['2001:4860:4860::8888'],
        );

        self::assertSame(
            'ipv6.example:443:[2001:4860:4860::8888]',
            $service->assertPublicAndCreateCurlResolveEntry('https://ipv6.example/file'),
        );
    }
}
