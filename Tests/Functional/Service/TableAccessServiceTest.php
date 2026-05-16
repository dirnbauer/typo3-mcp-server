<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\TableAccessService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TableAccessServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private TableAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'off';

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $service = $this->getContainer()->get(TableAccessService::class);
        assert($service instanceof TableAccessService);
        $this->service = $service;
    }

    public function testTranslateLabelReturnsPlainStringUnchanged(): void
    {
        $result = TableAccessService::translateLabel('Plain Label');

        self::assertSame('Plain Label', $result);
    }

    public function testTranslateLabelReturnsFallbackForDeprecatedKeys(): void
    {
        $result = TableAccessService::translateLabel(
            'LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:header_formlabel',
        );

        self::assertSame('Header', $result);
    }

    public function testParseSelectItemsWithAssociativeSyntax(): void
    {
        $items = [
            ['label' => 'Option A', 'value' => 'a'],
            ['label' => 'Option B', 'value' => 'b'],
            ['label' => 'Divider', 'value' => '--div--'],
            ['label' => 'Option C', 'value' => 'c'],
        ];

        $result = $this->service->parseSelectItems($items);

        self::assertSame(['a', 'b', 'c'], $result['values']);
        self::assertSame('Option A', $result['labels']['a']);
    }

    public function testParseSelectItemsWithNumericSyntax(): void
    {
        $items = [
            ['Label One', 'val1'],
            ['Label Two', 'val2'],
        ];

        $result = $this->service->parseSelectItems($items);

        self::assertSame(['val1', 'val2'], $result['values']);
        self::assertSame('Label One', $result['labels']['val1']);
    }

    public function testParseSelectItemsIncludesDividersWhenRequested(): void
    {
        $items = [
            ['label' => 'Option A', 'value' => 'a'],
            ['label' => 'Divider', 'value' => '--div--'],
            ['label' => 'Option B', 'value' => 'b'],
        ];

        $result = $this->service->parseSelectItems($items, false);

        self::assertSame(['a', '--div--', 'b'], $result['values']);
    }

    public function testParseSelectItemsSkipsNonArrayItems(): void
    {
        $items = [
            ['label' => 'Valid', 'value' => 'valid'],
            'invalid_string',
            42,
            null,
        ];

        $result = $this->service->parseSelectItems($items);

        self::assertSame(['valid'], $result['values']);
    }

    public function testParseSelectItemsWithEmptyArray(): void
    {
        $result = $this->service->parseSelectItems([]);

        self::assertSame([], $result['values']);
        self::assertSame([], $result['labels']);
    }

    public function testCanAccessTableForPages(): void
    {
        $result = $this->service->canAccessTable('pages');

        self::assertTrue($result, 'Pages table should be accessible');
    }

    public function testCanAccessTableRejectsNonexistentTable(): void
    {
        $result = $this->service->canAccessTable('table_that_does_not_exist');

        self::assertFalse($result, 'Nonexistent table should not be accessible');
    }

    public function testCanAccessTableRejectsSystemLogTable(): void
    {
        $result = $this->service->canAccessTable('sys_log');

        self::assertFalse($result, 'sys_log should be restricted');
    }

    public function testLocalModeAllowsNonWorkspaceCapableTableAccess(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';

        $accessInfo = $this->service->getTableAccessInfo('be_users');

        self::assertTrue($accessInfo['accessible'], implode(', ', $accessInfo['reasons']));
        self::assertFalse($accessInfo['workspace_capable']);
        self::assertTrue($accessInfo['permissions']['write']);
    }

    public function testGetAccessibleTablesReturnsArray(): void
    {
        $tables = $this->service->getAccessibleTables();

        self::assertIsArray($tables);
        self::assertArrayHasKey('pages', $tables);
        self::assertArrayHasKey('tt_content', $tables);
    }

    public function testGetTableTitleReturnsString(): void
    {
        $title = $this->service->getTableTitle('pages');

        self::assertNotEmpty($title);
    }

    public function testGetTableTitleReturnsTableNameForUnknownTable(): void
    {
        $title = $this->service->getTableTitle('nonexistent_table');

        self::assertSame('nonexistent_table', $title);
    }

    public function testGetTypeFieldNameReturnsCTypeForTtContent(): void
    {
        $typeField = $this->service->getTypeFieldName('tt_content');

        self::assertSame('CType', $typeField);
    }

    public function testGetLabelFieldNameReturnsTitleForPages(): void
    {
        $labelField = $this->service->getLabelFieldName('pages');

        self::assertSame('title', $labelField);
    }

    public function testGetAvailableTypesReturnsTypesForTtContent(): void
    {
        $types = $this->service->getAvailableTypes('tt_content');

        self::assertIsArray($types);
        self::assertArrayHasKey('text', $types);
        self::assertArrayHasKey('textmedia', $types);
    }

    public function testIsDateFieldReturnsTrueForCommonDateFields(): void
    {
        self::assertTrue($this->service->isDateField('pages', 'tstamp'));
        self::assertTrue($this->service->isDateField('pages', 'crdate'));
    }

    public function testIsDateFieldReturnsFalseForNonDateFields(): void
    {
        self::assertFalse($this->service->isDateField('pages', 'title'));
    }

    public function testGetSearchFieldsReturnsFieldsForPages(): void
    {
        $fields = $this->service->getSearchFields('pages');

        self::assertIsArray($fields);
        self::assertNotEmpty($fields);
    }

    public function testGetEssentialFieldsIncludesUidAndPid(): void
    {
        $fields = $this->service->getEssentialFields('pages');

        self::assertContains('uid', $fields);
        self::assertContains('pid', $fields);
    }

    public function testValidateFieldValueReturnsNullForValidData(): void
    {
        $result = $this->service->validateFieldValue('pages', 'title', 'A valid title');

        self::assertNull($result);
    }

    public function testValidateFieldValueReturnsErrorForNonexistentField(): void
    {
        $result = $this->service->validateFieldValue('pages', 'nonexistent_field_xyz', 'value');

        self::assertIsString($result);
        self::assertStringContainsString('does not exist', $result);
    }

    public function testGetSelectFieldAllowedValuesReturnsNullForPagesBackendLayout(): void
    {
        $allowed = $this->service->getSelectFieldAllowedValues('pages', 'backend_layout');

        self::assertNull(
            $allowed,
            'pages.backend_layout uses itemsProcFunc; allowed values must not be inferred from static items only',
        );
    }

    public function testValidateFieldValueAcceptsPagetsBackendLayoutIdentifierForPages(): void
    {
        $result = $this->service->validateFieldValue('pages', 'backend_layout', 'pagets__BlogPost');

        self::assertNull($result);
    }

    public function testGetSelectFieldAllowedValuesIncludesRegistryDoktypesForPages(): void
    {
        $this->registerCustomDoktype(137);

        $allowed = $this->service->getSelectFieldAllowedValues('pages', 'doktype');

        self::assertIsArray($allowed);
        self::assertContains('137', $allowed);
    }

    public function testValidateFieldValueAcceptsRegistryDoktypeForPages(): void
    {
        $this->registerCustomDoktype(137);

        $result = $this->service->validateFieldValue('pages', 'doktype', 137);

        self::assertNull($result);
    }

    /**
     * Register a custom page doktype via TCA (TYPO3 v14-compatible replacement for
     * PageDoktypeRegistry->add(), which is deprecated in v14 and removed in v15).
     */
    private function registerCustomDoktype(int $doktype): void
    {
        $GLOBALS['TCA']['pages']['columns']['doktype']['config']['items'][] = [
            'label' => 'Custom doktype ' . $doktype,
            'value' => $doktype,
        ];
        $GLOBALS['TCA']['pages']['types'][(string)$doktype]['allowedRecordTypes'] = '*';
    }
}
