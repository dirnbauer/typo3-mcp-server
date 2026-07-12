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
    public function redactsSensitiveQueryParametersRecursivelyAndCaseInsensitively(): void
    {
        $params = [
            'token' => 'supersecret',
            'ACCESS_TOKEN' => 'secret-access-token',
            'code_verifier' => 'secret-verifier',
            'nested' => ['client_secret' => 'secret-client', 'visible' => 'yes'],
            'foo' => 'bar',
        ];

        $out = McpHttpLogRedactor::redactQueryParamsForLog($params);

        self::assertSame('[REDACTED]', $out['token']);
        self::assertSame('[REDACTED]', $out['ACCESS_TOKEN']);
        self::assertSame('[REDACTED]', $out['code_verifier']);
        self::assertSame('[REDACTED]', $out['nested']['client_secret']);
        self::assertSame('yes', $out['nested']['visible']);
        self::assertSame('bar', $out['foo']);
    }
}
