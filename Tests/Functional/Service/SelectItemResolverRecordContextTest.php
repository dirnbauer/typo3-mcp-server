<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\SelectItemResolver;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Tests\Functional\Fixtures\RecordContextItemsProcFunc;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SelectItemResolverRecordContextTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private SelectItemResolver $selectItemResolver;
    private TableAccessService $tableAccessService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $this->registerContextDependentSelectField();

        $this->selectItemResolver = GeneralUtility::makeInstance(SelectItemResolver::class);
        $this->tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
    }

    public function testItemsProcFuncReceivesRecordContext(): void
    {
        $resolved = $this->selectItemResolver->resolveSelectItems(
            'tt_content',
            'tx_mcp_context_select',
            [
                'pid' => 1,
                'CType' => 'text',
                'tx_mcp_context_parent' => 42,
                'tx_mcp_context_select' => 'context-42',
            ],
        );

        self::assertNotNull($resolved);
        self::assertContains('default', $resolved['values']);
        self::assertContains('context-42', $resolved['values']);
    }

    public function testSeededInvalidValueIsNotReturnedAsAllowedItem(): void
    {
        $resolved = $this->selectItemResolver->resolveSelectItems(
            'tt_content',
            'tx_mcp_context_select',
            [
                'pid' => 1,
                'CType' => 'text',
                'tx_mcp_context_parent' => 42,
                'tx_mcp_context_select' => 'context-9001',
            ],
        );

        self::assertNotNull($resolved);
        self::assertContains('context-42', $resolved['values']);
        self::assertNotContains('context-9001', $resolved['values']);
    }

    public function testCacheDoesNotCollideAcrossRecordContextsOnSamePage(): void
    {
        $first = $this->selectItemResolver->resolveSelectItems(
            'tt_content',
            'tx_mcp_context_select',
            [
                'pid' => 1,
                'CType' => 'text',
                'tx_mcp_context_parent' => 42,
                'tx_mcp_context_select' => 'context-42',
            ],
        );
        $second = $this->selectItemResolver->resolveSelectItems(
            'tt_content',
            'tx_mcp_context_select',
            [
                'pid' => 1,
                'CType' => 'text',
                'tx_mcp_context_parent' => 99,
                'tx_mcp_context_select' => 'context-99',
            ],
        );

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertContains('context-42', $first['values']);
        self::assertNotContains('context-42', $second['values']);
        self::assertContains('context-99', $second['values']);
    }

    public function testValidationUsesRecordContextForDynamicSelectItems(): void
    {
        $validRecord = [
            'pid' => 1,
            'CType' => 'text',
            'tx_mcp_context_parent' => 42,
            'tx_mcp_context_select' => 'context-42',
        ];
        $error = $this->tableAccessService->validateFieldValue(
            'tt_content',
            'tx_mcp_context_select',
            'context-42',
            $validRecord,
        );
        self::assertNull($error);

        $invalidRecord = [
            'pid' => 1,
            'CType' => 'text',
            'tx_mcp_context_parent' => 42,
            'tx_mcp_context_select' => 'context-9001',
        ];
        $error = $this->tableAccessService->validateFieldValue(
            'tt_content',
            'tx_mcp_context_select',
            'context-9001',
            $invalidRecord,
        );
        self::assertNotNull($error);
        self::assertStringContainsString('must be one of', $error);
    }

    private function registerContextDependentSelectField(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['tx_mcp_context_parent'] = [
            'label' => 'Context Parent',
            'config' => [
                'type' => 'input',
            ],
        ];
        $GLOBALS['TCA']['tt_content']['columns']['tx_mcp_context_select'] = [
            'label' => 'Context Select',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [],
                'itemsProcFunc' => RecordContextItemsProcFunc::class . '->itemsProcFunc',
            ],
        ];

        $showitem = (string)($GLOBALS['TCA']['tt_content']['types']['text']['showitem'] ?? '');
        $GLOBALS['TCA']['tt_content']['types']['text']['showitem'] = $showitem
            . ',tx_mcp_context_parent,tx_mcp_context_select';
    }
}
