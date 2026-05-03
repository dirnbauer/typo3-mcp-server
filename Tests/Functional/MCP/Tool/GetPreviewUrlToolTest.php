<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetPreviewUrlTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;

final class GetPreviewUrlToolTest extends AbstractFunctionalTest
{
    #[Test]
    public function rejectsUnsupportedTable(): void
    {
        $tool = $this->get(GetPreviewUrlTool::class);
        $result = $tool->execute(['table' => 'sys_file', 'uid' => 1]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('"pages" or "tt_content"', (string)$result->content[0]->text);
    }

    #[Test]
    public function rejectsMissingUid(): void
    {
        $tool = $this->get(GetPreviewUrlTool::class);
        $result = $tool->execute(['table' => 'pages']);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('uid', (string)$result->content[0]->text);
    }

    #[Test]
    public function rejectsZeroUid(): void
    {
        $tool = $this->get(GetPreviewUrlTool::class);
        $result = $tool->execute(['table' => 'pages', 'uid' => 0]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('> 0', (string)$result->content[0]->text);
    }

    #[Test]
    public function rejectsMissingContentRow(): void
    {
        $tool = $this->get(GetPreviewUrlTool::class);
        $result = $tool->execute(['table' => 'tt_content', 'uid' => 999_999_999]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('No content element found', (string)$result->content[0]->text);
    }
}
