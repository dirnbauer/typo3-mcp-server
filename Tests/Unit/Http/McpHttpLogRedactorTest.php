<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Http;

use Hn\McpServer\Http\McpHttpLogRedactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpHttpLogRedactorTest extends TestCase
{
    #[Test]
    public function redactsSensitiveHeaderValues(): void
    {
        $headers = [
            'Host' => ['example.org'],
            'Authorization' => ['Bearer secret-token'],
            'Cookie' => ['session=abc'],
            'X-Custom' => ['visible'],
        ];

        $out = McpHttpLogRedactor::redactHeadersForLog($headers);

        self::assertSame('example.org', $out['Host']);
        self::assertSame('[REDACTED]', $out['Authorization']);
        self::assertSame('[REDACTED]', $out['Cookie']);
        self::assertSame('visible', $out['X-Custom']);
    }

    #[Test]
    public function redactsTokenQueryParameter(): void
    {
        $params = ['token' => 'supersecret', 'foo' => 'bar'];

        $out = McpHttpLogRedactor::redactQueryParamsForLog($params);

        self::assertSame('[REDACTED]', $out['token']);
        self::assertSame('bar', $out['foo']);
    }
}
