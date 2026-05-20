<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetViewHelperDocumentationTool;
use Hn\McpServer\MCP\Tool\ListViewHelpersTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;

final class ViewHelperToolsTest extends AbstractFunctionalTest
{
    use DevSiteTestTrait;

    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
        'fluid',
    ];

    private ListViewHelpersTool $listTool;
    private GetViewHelperDocumentationTool $docTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableDevSiteTools();
        $this->listTool = $this->getService(ListViewHelpersTool::class);
        $this->docTool = $this->getService(GetViewHelperDocumentationTool::class);
    }

    public function testListViewHelpersReturnsTags(): void
    {
        $result = $this->listTool->execute([]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);
        self::assertGreaterThan(0, $data['total']);
        self::assertNotEmpty($data['viewHelpers'][0]['tagName']);
        self::assertNotEmpty($data['viewHelpers'][0]['xmlNamespace']);
    }

    public function testGetViewHelperDocumentationReturnsMarkdown(): void
    {
        $listResult = $this->listTool->execute([]);
        $listData = $this->extractJsonFromResult($listResult);
        $tagName = $listData['viewHelpers'][0]['tagName'];

        $result = $this->docTool->execute(['tagName' => $tagName]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $this->getFirstTextContent($result);
        self::assertStringContainsString('# ' . $tagName, $text);
        self::assertStringContainsString('XML Namespace', $text);
    }

    public function testViewHelperToolsBlockedOutsideDevSiteMode(): void
    {
        $this->disableDevSiteTools();

        $result = $this->listTool->execute([]);
        self::assertTrue($result->isError);
        self::assertStringContainsString('local development mode', $this->getFirstTextContent($result));
    }
}
