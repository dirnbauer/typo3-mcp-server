<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Http;

use Hn\McpServer\Http\AjaxRequestBodyParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;

final class AjaxRequestBodyParserTest extends TestCase
{
    #[Test]
    public function parseStringFieldsReadsParsedBody(): void
    {
        $request = (new ServerRequest(new Uri('https://example.com/ajax'), 'POST'))
            ->withParsedBody(['csrfToken' => 'abc', 'tokenId' => '5']);

        $parser = new AjaxRequestBodyParser();

        self::assertSame(
            ['csrfToken' => 'abc', 'tokenId' => '5'],
            $parser->parseStringFields($request),
        );
    }

    #[Test]
    public function parseStringFieldsFallsBackToJsonBody(): void
    {
        $resource = fopen('php://temp', 'rw');
        self::assertIsResource($resource);
        fwrite($resource, '{"csrfToken":"json-token","clientName":"Cursor"}');
        rewind($resource);

        $request = new ServerRequest(new Uri('https://example.com/ajax'), 'POST', $resource);

        $parser = new AjaxRequestBodyParser();

        self::assertSame(
            ['csrfToken' => 'json-token', 'clientName' => 'Cursor'],
            $parser->parseStringFields($request),
        );
    }
}
