<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Service\DeveloperLogEntryParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeveloperLogEntryParserTest extends TestCase
{
    #[Test]
    public function itKeepsDiagnosisAndTrimsTheConversationHeavyTrace(): void
    {
        $context = json_encode([
            'exception_class' => \RuntimeException::class,
            'exception_code' => 42,
            'file' => '/var/www/html/src/Broken.php',
            'line' => 17,
            'message' => 'Broken request',
            'exception' => "RuntimeException: Broken request\n#0 /a.php(1): first()\n#1 /b.php(2): second()\n#2 /c.php(3): third()",
            'request_url' => 'https://example.test/broken',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $entry = [
            'timestamp' => 'Sat, 15 Aug 2026 10:00:00 +0000 [ERROR]',
            'level' => 'ERROR',
            'message' => 'request="abc" component="TYPO3.CMS.Core.Error.DebugExceptionHandler": failed - ' . $context,
            'file' => 'typo3_test.log',
        ];

        $parsed = (new DeveloperLogEntryParser())->parse($entry, false, 2);

        self::assertSame(\RuntimeException::class, $parsed['exception']['class']);
        self::assertSame(42, $parsed['exception']['code']);
        self::assertCount(2, $parsed['trace']);
        self::assertSame(1, $parsed['traceOmittedFrames']);
        self::assertStringContainsString('full', $parsed['hint']);
        self::assertArrayNotHasKey('raw', $parsed);
    }
}
