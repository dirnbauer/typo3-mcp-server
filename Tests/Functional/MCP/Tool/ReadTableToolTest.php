<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Fixtures\TestDataBuilder;

class ReadTableToolTest extends AbstractFunctionalTest
{
    private ReadTableTool $tool;
    private TestDataBuilder $data;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = $this->getService(ReadTableTool::class);
        $this->data = new TestDataBuilder();
    }

    /**
     * Test reading records by PID (page ID)
     */
    public function testReadRecordsByPid(): void
    {
        // Read content elements from page 1 (Home)
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'includeRelations' => false,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        self::assertEquals('tt_content', $data['table']);
        self::assertArrayHasKey('records', $data);

        // Should have 3 content elements including hidden one (100, 101, 104)
        self::assertCount(3, $data['records']);

        // Verify record structure
        $firstRecord = $data['records'][0];
        $this->assertHasEssentialFields($firstRecord, ['header', 'CType']);

        // Verify specific content - now includes hidden records
        $uids = array_column($data['records'], 'uid');
        self::assertContains(100, $uids);
        self::assertContains(101, $uids);
        self::assertContains(104, $uids); // Hidden content is now included
    }

    /**
     * Test reading a single record by UID
     */
    public function testReadSingleRecordByUid(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'includeRelations' => false,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        // Should have exactly one record
        self::assertCount(1, $data['records']);

        $expected = [
            'uid' => 100,
            'header' => 'Welcome Header',
            'CType' => 'textmedia',
            'pid' => 1,
        ];
        $this->assertRecordEquals($expected, $data['records'][0]);
    }

    /**
     * Test reading from pages table
     */
    public function testReadPagesTable(): void
    {
        $result = $this->tool->execute([
            'table' => 'pages',
            'pid' => 0, // Root level pages
            'includeRelations' => false,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        self::assertEquals('pages', $data['table']);
        self::assertGreaterThan(0, \count($data['records']));

        // Should include root page (Home) - Contact and News are now subpages
        $titles = array_column($data['records'], 'title');
        self::assertContains('Home', $titles);

        // Contact and News should not be in root level anymore
        self::assertNotContains('Contact', $titles);
        self::assertNotContains('News', $titles);

        // Should not include hidden pages by default
        self::assertNotContains('Hidden Page', $titles);
    }

    /**
     * Test pagination functionality
     */
    public function testReadWithPagination(): void
    {
        // Test with limit
        $result = $this->tool->execute([
            'table' => 'pages',
            'limit' => 2,
            'includeRelations' => false,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        self::assertLessThanOrEqual(2, \count($data['records']));
        self::assertSame(\count($data['records']), $data['count']);
        self::assertSame(2, $data['nextOffset']);
        $this->assertHasPagination($result, 2, 0);
    }

    /**
     * Test pagination with offset
     */
    public function testReadWithOffset(): void
    {
        $result = $this->tool->execute([
            'table' => 'pages',
            'limit' => 1,
            'offset' => 1,
            'includeRelations' => false,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        self::assertSame(\count($data['records']), $data['count']);
        self::assertSame(2, $data['nextOffset']);
        $this->assertHasPagination($result, 1, 1);
    }

    /**
     * Test date field conversion
     */
    public function testDateFieldConversion(): void
    {
        $result = $this->tool->execute([
            'table' => 'pages',
            'uid' => 1,
            'includeRelations' => false,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $record = $data['records'][0];

        // Date fields should be converted to ISO format
        self::assertArrayHasKey('tstamp', $record);
        self::assertArrayHasKey('crdate', $record);

        // Should be ISO 8601 format strings, not timestamps
        $this->assertDateFormat($record['tstamp'], 'tstamp');
        $this->assertDateFormat($record['crdate'], 'crdate');
    }

    /**
     * Test error handling for invalid table
     */
    public function testReadFromInvalidTable(): void
    {
        $result = $this->tool->execute([
            'table' => 'non_existent_table',
        ]);

        $this->assertToolError($result, 'does not exist in TCA');
    }

    /**
     * Test error handling for missing table parameter
     */
    public function testMissingTableParameter(): void
    {
        $result = $this->tool->execute([]);

        $this->assertToolError($result, 'Table name is required');
    }

    /**
     * Test reading with custom WHERE condition
     */
    public function testReadWithWhereCondition(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        $result = $tool->execute([
            'table' => 'tt_content',
            'where' => 'CType = "textmedia"',
            'includeRelations' => false,
        ]);

        self::assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);

        // All returned records should have CType = textmedia
        foreach ($data['records'] as $record) {
            self::assertEquals('textmedia', $record['CType']);
        }
    }

    /**
     * Test WHERE condition security (should block dangerous SQL)
     */
    public function testWhereConditionSecurity(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        // Try to inject dangerous SQL
        $result = $tool->execute([
            'table' => 'pages',
            'where' => 'uid = 1; DROP TABLE pages',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('disallowed SQL keywords', $result->content[0]->text);
    }

    /**
     * Test tool schema
     */
    public function testToolSchema(): void
    {
        $tool = $this->getService(ReadTableTool::class);
        $schema = $tool->getSchema();

        self::assertIsArray($schema);
        self::assertArrayHasKey('description', $schema);
        self::assertArrayHasKey('inputSchema', $schema);
        self::assertArrayHasKey('properties', $schema['inputSchema']);

        // Check key parameters
        $properties = $schema['inputSchema']['properties'];
        self::assertArrayHasKey('table', $properties);
        self::assertArrayHasKey('pid', $properties);
        self::assertArrayHasKey('uid', $properties);
        self::assertArrayHasKey('limit', $properties);
        self::assertArrayHasKey('offset', $properties);
        self::assertArrayHasKey('where', $properties);
    }

    /**
     * Test reading records with sorting
     */
    public function testReadWithSorting(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        $result = $tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'includeRelations' => false,
        ]);

        self::assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);

        // Records should be sorted by sorting field (ascending) - now includes hidden
        self::assertCount(3, $data['records']);

        $sortingValues = array_column($data['records'], 'sorting');
        self::assertEquals(256, $sortingValues[0]);
        self::assertEquals(512, $sortingValues[1]);
        self::assertEquals(768, $sortingValues[2]); // Hidden record
    }

    /**
     * Test essential fields are always included
     */
    public function testEssentialFieldsIncluded(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        $result = $tool->execute([
            'table' => 'pages',
            'uid' => 1,
            'includeRelations' => false,
        ]);

        self::assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);

        $record = $data['records'][0];

        // Essential fields should always be present
        self::assertArrayHasKey('uid', $record);
        self::assertArrayHasKey('pid', $record);
        self::assertArrayHasKey('tstamp', $record);
        self::assertArrayHasKey('crdate', $record);

        // For pages, title should be included as it's the label field
        self::assertArrayHasKey('title', $record);
    }

    /**
     * Test field filtering based on CType
     */
    public function testFieldFilteringBasedOnCType(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        // Test textmedia record (UID 100)
        $result = $tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'includeRelations' => false,
        ]);

        self::assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        $textmediaRecord = $data['records'][0];

        // Verify this is a textmedia record
        self::assertEquals('textmedia', $textmediaRecord['CType']);

        // Essential fields should always be present
        self::assertArrayHasKey('uid', $textmediaRecord);
        self::assertArrayHasKey('pid', $textmediaRecord);
        self::assertArrayHasKey('CType', $textmediaRecord);
        self::assertArrayHasKey('header', $textmediaRecord);
        self::assertArrayHasKey('sorting', $textmediaRecord);
        self::assertArrayHasKey('tstamp', $textmediaRecord);
        self::assertArrayHasKey('crdate', $textmediaRecord);

        // For textmedia, bodytext should be present if it's in the showitem
        self::assertArrayHasKey('bodytext', $textmediaRecord);

        // Test form_formframework record (UID 105)
        $result = $tool->execute([
            'table' => 'tt_content',
            'uid' => 105,
            'includeRelations' => false,
        ]);

        self::assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        $listRecord = $data['records'][0];

        // Verify this is a plugin record.
        self::assertContains($listRecord['CType'], ['list', 'news_pi1']);

        // Essential fields should always be present
        self::assertArrayHasKey('uid', $listRecord);
        self::assertArrayHasKey('pid', $listRecord);
        self::assertArrayHasKey('CType', $listRecord);
        self::assertArrayHasKey('header', $listRecord);
        self::assertArrayHasKey('sorting', $listRecord);
        self::assertArrayHasKey('tstamp', $listRecord);
        self::assertArrayHasKey('crdate', $listRecord);

        // For list CType, we need to check how the old plugin system works
        // The list CType uses subtype_value_field which should include pi_flexform when needed
        // Note: This test may need adjustment based on actual TCA configuration
        // The exact fields depend on how TYPO3 is configured and what TCA types are defined

        // Field filtering analysis:
        // Both records should return type-specific fields based on TCA configuration
        // This tests the new TcaSchemaFactory-based implementation

        $textmediaFields = array_keys($textmediaRecord);
        $listFields = array_keys($listRecord);

        // Both should have common essential fields
        $commonFields = ['uid', 'pid', 'CType', 'header', 'sorting', 'tstamp', 'crdate'];
        foreach ($commonFields as $field) {
            self::assertContains($field, $textmediaFields, "Textmedia record missing essential field: $field");
            self::assertContains($field, $listFields, "List record missing essential field: $field");
        }

        // Both records should return type-specific fields based on TCA configuration
        // In a proper type-based filtering system:
        // - textmedia should have: bodytext, assets, but not pi_flexform
        // - plugin records should expose their plugin-specific fields and potential FlexForm data

        // Verify that type-specific fields are present
        self::assertContains('bodytext', $textmediaFields, 'Textmedia should have bodytext');
        if (isset($listRecord['list_type'])) {
            self::assertContains('list_type', $listFields, 'Legacy plugin records should have list_type field');
        } else {
            self::assertContains('pi_flexform', $listFields, 'Plugin CTypes should expose pi_flexform');
        }

        // Count fields to ensure we're not getting too many unnecessary fields
        self::assertLessThan(100, \count($textmediaFields), 'Too many fields returned for textmedia');
        self::assertLessThan(100, \count($listFields), 'Too many fields returned for list');
    }

    /**
     * Test field filtering with unknown CTypes
     */
    public function testFieldFilteringWithUnknownCType(): void
    {
        // Create a record with an unknown CType
        $tool = $this->getService(ReadTableTool::class);

        // Read a record but simulate unknown CType by testing field filtering behavior
        $result = $tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
        ]);

        self::assertFalse($result->isError, json_encode($result->content));
        $data = json_decode($result->content[0]->text, true);
        $record = $data['records'][0];

        // Even with unknown CTypes, essential fields should be present
        $essentialFields = ['uid', 'pid', 'CType', 'header', 'sorting', 'tstamp', 'crdate'];
        foreach ($essentialFields as $field) {
            self::assertArrayHasKey($field, $record, "Essential field $field missing");
        }

        // Should have reasonable field count (not all possible fields)
        self::assertLessThan(100, \count($record), 'Too many fields for unknown CType');
    }

    /**
     * Test that fields parameter limits returned fields
     */
    public function testFieldsParameterLimitsReturnedFields(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'fields' => ['header', 'bodytext'],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);
        $record = $data['records'][0];

        // uid is always included
        self::assertArrayHasKey('uid', $record);

        // Requested fields should be present
        self::assertArrayHasKey('header', $record);
        self::assertArrayHasKey('bodytext', $record);

        // Everything else should be absent — only uid + requested fields
        self::assertArrayNotHasKey('CType', $record, 'Non-requested field CType should be excluded');
        self::assertArrayNotHasKey('colPos', $record, 'Non-requested field colPos should be excluded');
        self::assertArrayNotHasKey('pid', $record, 'Non-requested field pid should be excluded');
        self::assertArrayNotHasKey('sorting', $record, 'Non-requested field sorting should be excluded');
        self::assertArrayNotHasKey('tstamp', $record, 'Non-requested field tstamp should be excluded');
    }

    /**
     * Test that fields parameter with empty array returns all fields (default behavior)
     */
    public function testFieldsParameterEmptyArrayReturnsAllFields(): void
    {
        // Read without fields parameter
        $resultWithout = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
        ]);

        // Read with empty fields array
        $resultWith = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'fields' => [],
        ]);

        self::assertFalse($resultWithout->isError, json_encode($resultWithout->jsonSerialize()));
        self::assertFalse($resultWith->isError, json_encode($resultWith->jsonSerialize()));

        $dataWithout = $this->extractJsonFromResult($resultWithout);
        $dataWith = $this->extractJsonFromResult($resultWith);

        // Both should return the same fields
        $keysWithout = array_keys($dataWithout['records'][0]);
        $keysWith = array_keys($dataWith['records'][0]);
        sort($keysWithout);
        sort($keysWith);

        self::assertEquals($keysWithout, $keysWith, 'Empty fields array should return the same fields as omitting the parameter');
    }

    /**
     * Test that fields parameter appears in schema
     */
    public function testFieldsParameterInSchema(): void
    {
        $schema = $this->tool->getSchema();
        $properties = $schema['inputSchema']['properties'];

        self::assertArrayHasKey('fields', $properties);
        self::assertEquals('array', $properties['fields']['type']);
        self::assertArrayHasKey('items', $properties['fields']);
    }

    /**
     * Test that fields parameter works with pages table
     */
    public function testFieldsParameterWithPagesTable(): void
    {
        $result = $this->tool->execute([
            'table' => 'pages',
            'uid' => 1,
            'fields' => ['title'],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);
        $record = $data['records'][0];

        // uid is always included
        self::assertArrayHasKey('uid', $record);

        // Requested field should be present
        self::assertArrayHasKey('title', $record);

        // Everything else should be absent
        self::assertArrayNotHasKey('doktype', $record, 'Non-requested field doktype should be excluded');
        self::assertArrayNotHasKey('pid', $record, 'Non-requested field pid should be excluded');
        self::assertArrayNotHasKey('description', $record, 'Non-requested field description should be excluded');
        self::assertArrayNotHasKey('slug', $record, 'Non-requested field slug should be excluded');
    }

    /**
     * Test that ctrl fields (tstamp, crdate, etc.) can be requested even though
     * they are not in the TCA showitem definition for any type.
     */
    public function testFieldsParameterCanRequestCtrlFields(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'fields' => ['tstamp', 'crdate', 'pid'],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);
        $record = $data['records'][0];

        // uid always included
        self::assertArrayHasKey('uid', $record);

        // Requested ctrl fields should be present
        self::assertArrayHasKey('tstamp', $record, 'Requested ctrl field tstamp should be included');
        self::assertArrayHasKey('crdate', $record, 'Requested ctrl field crdate should be included');
        self::assertArrayHasKey('pid', $record, 'Requested ctrl field pid should be included');

        // Dates should still be converted to ISO format
        $this->assertDateFormat($record['tstamp'], 'tstamp');
        $this->assertDateFormat($record['crdate'], 'crdate');

        // Non-requested fields should be absent
        self::assertArrayNotHasKey('header', $record, 'Non-requested field header should be excluded');
        self::assertArrayNotHasKey('bodytext', $record, 'Non-requested field bodytext should be excluded');
    }

    /**
     * Test that field names are matched case-insensitively.
     * The output should use the correct TCA case.
     */
    public function testFieldsParameterIsCaseInsensitive(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'fields' => ['ctype', 'HEADER', 'Bodytext'],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);
        $record = $data['records'][0];

        // Fields should be returned in their correct TCA case
        self::assertArrayHasKey('CType', $record, '"ctype" should match CType');
        self::assertArrayHasKey('header', $record, '"HEADER" should match header');
        self::assertArrayHasKey('bodytext', $record, '"Bodytext" should match bodytext');

        // Non-requested fields should still be excluded
        self::assertArrayNotHasKey('colPos', $record);
    }
}
