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

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $service = $this->getContainer()->get(TableAccessService::class);
        \assert($service instanceof TableAccessService);
        $this->service = $service;
    }

    public function testTranslateLabelReturnsPlainStringUnchanged(): void
    {
        $result = TableAccessService::translateLabel('Plain Label');

        $this->assertSame('Plain Label', $result);
    }

    public function testTranslateLabelReturnsFallbackForDeprecatedKeys(): void
    {
        $result = TableAccessService::translateLabel(
            'LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:header_formlabel',
        );

        $this->assertSame('Header', $result);
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

        $this->assertSame(['a', 'b', 'c'], $result['values']);
        $this->assertSame('Option A', $result['labels']['a']);
    }

    public function testParseSelectItemsWithNumericSyntax(): void
    {
        $items = [
            ['Label One', 'val1'],
            ['Label Two', 'val2'],
        ];

        $result = $this->service->parseSelectItems($items);

        $this->assertSame(['val1', 'val2'], $result['values']);
        $this->assertSame('Label One', $result['labels']['val1']);
    }

    public function testParseSelectItemsIncludesDividersWhenRequested(): void
    {
        $items = [
            ['label' => 'Option A', 'value' => 'a'],
            ['label' => 'Divider', 'value' => '--div--'],
            ['label' => 'Option B', 'value' => 'b'],
        ];

        $result = $this->service->parseSelectItems($items, false);

        $this->assertSame(['a', '--div--', 'b'], $result['values']);
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

        $this->assertSame(['valid'], $result['values']);
    }

    public function testParseSelectItemsWithEmptyArray(): void
    {
        $result = $this->service->parseSelectItems([]);

        $this->assertSame([], $result['values']);
        $this->assertSame([], $result['labels']);
    }

    public function testCanAccessTableForPages(): void
    {
        $result = $this->service->canAccessTable('pages');

        $this->assertTrue($result, 'Pages table should be accessible');
    }

    public function testCanAccessTableRejectsNonexistentTable(): void
    {
        $result = $this->service->canAccessTable('table_that_does_not_exist');

        $this->assertFalse($result, 'Nonexistent table should not be accessible');
    }

    public function testCanAccessTableRejectsSystemLogTable(): void
    {
        $result = $this->service->canAccessTable('sys_log');

        $this->assertFalse($result, 'sys_log should be restricted');
    }

    public function testGetAccessibleTablesReturnsArray(): void
    {
        $tables = $this->service->getAccessibleTables();

        $this->assertIsArray($tables);
        $this->assertArrayHasKey('pages', $tables);
        $this->assertArrayHasKey('tt_content', $tables);
    }

    public function testGetTableTitleReturnsString(): void
    {
        $title = $this->service->getTableTitle('pages');

        $this->assertNotEmpty($title);
    }

    public function testGetTableTitleReturnsTableNameForUnknownTable(): void
    {
        $title = $this->service->getTableTitle('nonexistent_table');

        $this->assertSame('nonexistent_table', $title);
    }

    public function testGetTypeFieldNameReturnsCTypeForTtContent(): void
    {
        $typeField = $this->service->getTypeFieldName('tt_content');

        $this->assertSame('CType', $typeField);
    }

    public function testGetLabelFieldNameReturnsTitleForPages(): void
    {
        $labelField = $this->service->getLabelFieldName('pages');

        $this->assertSame('title', $labelField);
    }

    public function testGetAvailableTypesReturnsTypesForTtContent(): void
    {
        $types = $this->service->getAvailableTypes('tt_content');

        $this->assertIsArray($types);
        $this->assertArrayHasKey('text', $types);
        $this->assertArrayHasKey('textmedia', $types);
    }

    public function testIsDateFieldReturnsTrueForCommonDateFields(): void
    {
        $this->assertTrue($this->service->isDateField('pages', 'tstamp'));
        $this->assertTrue($this->service->isDateField('pages', 'crdate'));
    }

    public function testIsDateFieldReturnsFalseForNonDateFields(): void
    {
        $this->assertFalse($this->service->isDateField('pages', 'title'));
    }

    public function testGetSearchFieldsReturnsFieldsForPages(): void
    {
        $fields = $this->service->getSearchFields('pages');

        $this->assertIsArray($fields);
        $this->assertNotEmpty($fields);
    }

    public function testGetEssentialFieldsIncludesUidAndPid(): void
    {
        $fields = $this->service->getEssentialFields('pages');

        $this->assertContains('uid', $fields);
        $this->assertContains('pid', $fields);
    }

    public function testValidateFieldValueReturnsNullForValidData(): void
    {
        $result = $this->service->validateFieldValue('pages', 'title', 'A valid title');

        $this->assertNull($result);
    }

    public function testValidateFieldValueReturnsErrorForNonexistentField(): void
    {
        $result = $this->service->validateFieldValue('pages', 'nonexistent_field_xyz', 'value');

        $this->assertIsString($result);
        $this->assertStringContainsString('does not exist', $result);
    }
}
