<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests for WriteTable tool search-and-replace functionality
 */
final class SearchReplaceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private WriteTableTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/tt_content.csv');
        $this->setUpBackendUser(1);

        $workspaceContextService = GeneralUtility::makeInstance(WorkspaceContextService::class);
        $workspaceContextService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $this->tool = GeneralUtility::makeInstance(WriteTableTool::class);
    }

    public function testSearchReplaceOnlyWorksForUpdateAction(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => [['search' => 'old', 'replace' => 'new']],
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('update', $result->content[0]->text);
    }

    public function testSearchReplaceRejectsEmptySearchString(): void
    {
        $this->createContentWithHeader('Test Header');

        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 1,
            'data' => [
                'header' => [['search' => '', 'replace' => 'new']],
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('empty search string', $result->content[0]->text);
    }

    private function createContentWithHeader(string $header): int
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => $header,
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $json = json_decode($result->content[0]->text, true);
        return (int) ($json['uid'] ?? 0);
    }
}
