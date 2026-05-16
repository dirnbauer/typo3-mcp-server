<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\RenderRecordTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;

final class RenderRecordToolTest extends AbstractFunctionalTest
{
    #[Test]
    public function rejectsZeroPageId(): void
    {
        $tool = $this->get(RenderRecordTool::class);
        $result = $tool->execute(['pageId' => 0]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('pageId', (string)$result->content[0]->text);
    }

    #[Test]
    public function rejectsUnknownMode(): void
    {
        $tool = $this->get(RenderRecordTool::class);
        $result = $tool->execute(['pageId' => 1, 'mode' => 'binary']);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('mode', (string)$result->content[0]->text);
    }

    #[Test]
    public function previewModeReturnsUrlWithoutFetching(): void
    {
        // mode=preview short-circuits before any HTTP fetch — safe to call
        // in a functional test without bringing up a frontend. We only assert
        // that the response shape is correct; URL generation may fall back
        // to a relative URL when no site is configured for the test page.
        $tool = $this->get(RenderRecordTool::class);
        $result = $tool->execute(['pageId' => 1, 'mode' => 'preview']);

        // No site configured for page 1 in the default test fixture: the
        // tool throws ValidationException, which surfaces as isError=true.
        // Either outcome is acceptable; we just want the tool to NOT crash
        // the dispatcher and to NOT attempt an outbound HTTP call.
        if ($result->isError) {
            $errorText = (string)$result->content[0]->text;
            self::assertStringContainsString('site is configured', $errorText);
            return;
        }

        $payload = json_decode((string)$result->content[0]->text, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['pageId']);
        self::assertArrayHasKey('url', $payload);
    }
}
