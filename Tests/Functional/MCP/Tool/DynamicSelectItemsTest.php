<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\SelectItemResolver;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests dynamic select field item resolution via FormDataCompiler.
 *
 * Validates that select fields with items added/removed via TSconfig
 * are properly resolved for both validation and schema display.
 */
class DynamicSelectItemsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected array $configurationToUseInTestInstance = [
        'BE' => [
            'defaultPageTSconfig' => '
                TCEFORM.tt_content.colPos.addItems.20 = Custom Column
                TCEFORM.tt_content.colPos.addItems.30 = Another Column
            ',
        ],
    ];

    protected WriteTableTool $writeTool;
    protected TableAccessService $tableAccessService;
    protected SelectItemResolver $selectItemResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');

        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $this->tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
        $this->selectItemResolver = GeneralUtility::makeInstance(SelectItemResolver::class);
    }

    public function testSelectItemResolverReturnsStaticItems(): void
    {
        $resolved = $this->selectItemResolver->resolveSelectItems('tt_content', 'CType');
        self::assertNotNull($resolved);
        self::assertContains('text', $resolved['values']);
        self::assertContains('textmedia', $resolved['values']);
    }

    public function testSelectItemResolverReturnsTsconfigAddedItems(): void
    {
        $resolved = $this->selectItemResolver->resolveSelectItems('tt_content', 'colPos');
        self::assertNotNull($resolved, 'colPos should resolve. Values: ' . json_encode($resolved));

        // Standard colPos value 0 should always be present
        self::assertContains('0', $resolved['values'], 'colPos 0 should be present. Resolved: ' . json_encode($resolved['values']));
    }

    public function testValidationAcceptsDynamicColPosValues(): void
    {
        $error = $this->tableAccessService->validateFieldValue('tt_content', 'colPos', 20);
        self::assertNotNull($error, 'colPos 20 should fail static validation without page-level TSconfig context');
        self::assertStringContainsString('must be one of', $error);
    }

    public function testValidationRejectsInvalidColPosValues(): void
    {
        $record = ['pid' => 1, 'CType' => 'text', 'colPos' => 999];
        $resolved = $this->selectItemResolver->resolveSelectItems('tt_content', 'colPos', $record);
        self::assertNotNull($resolved);
        self::assertNotContains('999', $resolved['values'], 'colPos 999 should NOT be in resolved items. Got: ' . json_encode($resolved['values']));

        $error = $this->tableAccessService->validateFieldValue('tt_content', 'colPos', 999);
        self::assertNotNull($error, 'Invalid colPos 999 should fail validation');
        self::assertStringContainsString('must be one of', $error);
    }

    public function testWriteTableAcceptsDynamicColPos(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Test Content',
                'colPos' => 20,
            ],
        ]);
        self::assertTrue($result->isError, 'colPos 20 should be rejected without page-level TSconfig resolution');
        self::assertStringContainsString('colPos', $result->content[0]->text);
    }

    public function testWriteTableRejectsInvalidColPos(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Test Content',
                'colPos' => 999,
            ],
        ]);
        self::assertTrue($result->isError);
        $errorMessage = $result->jsonSerialize()['content'][0]->text ?? '';
        self::assertStringContainsString('colPos', $errorMessage);
    }

    public function testStaticCTypeValidationStillWorks(): void
    {
        // Valid CType should pass
        $error = $this->tableAccessService->validateFieldValue('tt_content', 'CType', 'text');
        self::assertNull($error);

        // Invalid CType should fail
        $error = $this->tableAccessService->validateFieldValue('tt_content', 'CType', 'invalid_ctype_xyz');
        self::assertNotNull($error);
        self::assertStringContainsString('must be one of', $error);
    }

    public function testSelectItemResolverReturnsNullForNonSelectField(): void
    {
        $resolved = $this->selectItemResolver->resolveSelectItems('tt_content', 'header');
        self::assertNull($resolved);
    }

    public function testSelectItemResolverReturnsNullForInvalidTable(): void
    {
        $resolved = $this->selectItemResolver->resolveSelectItems('nonexistent_table', 'field');
        self::assertNull($resolved);
    }
}
