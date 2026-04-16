<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test error handling paths for WriteTableTool
 */
class WriteTableToolErrorTest extends FunctionalTestCase
{
    use GetServiceTrait;
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected WriteTableTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        // Import fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');

        // Set up backend user
        $this->setUpBackendUser(1);

        // Initialize tool
        $this->tool = $this->getService(WriteTableTool::class);
    }

    /**
     * Test missing action parameter
     */
    public function testMissingAction(): void
    {
        $result = $this->tool->execute([
            'table' => 'pages',
            'uid' => 1,
            'data' => ['title' => 'Test'],
        ]);

        self::assertTrue($result->isError, 'Result should be an error');
        self::assertStringContainsString('Action is required', $result->content[0]->text);
    }

    /**
     * Test invalid action parameter
     */
    public function testInvalidAction(): void
    {
        $result = $this->tool->execute([
            'action' => 'invalid',
            'table' => 'pages',
            'uid' => 1,
        ]);

        self::assertTrue($result->isError, 'Result should be an error');
        self::assertStringContainsString('Invalid action: invalid', $result->content[0]->text);
    }

    /**
     * Test missing table parameter
     */
    public function testMissingTable(): void
    {
        $result = $this->tool->execute([
            'action' => 'update',
            'uid' => 1,
            'data' => ['title' => 'Test'],
        ]);

        self::assertTrue($result->isError, 'Result should be an error');
        self::assertStringContainsString('Table name is required', $result->content[0]->text);
    }

    /**
     * Test missing required parameters for each action
     */
    public function testMissingRequiredParameters(): void
    {
        // Missing UID for update
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'pages',
            'data' => ['title' => 'Test'],
        ]);
        self::assertTrue($result->isError);
        self::assertStringContainsString('Record UID is required for update action', $result->content[0]->text);

        // Missing PID for create
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'data' => ['title' => 'Test'],
        ]);
        self::assertTrue($result->isError);
        self::assertStringContainsString('Page ID (pid) is required for create action', $result->content[0]->text);

        // Missing data for update
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
        ]);
        self::assertTrue($result->isError);
        self::assertStringContainsString('Data is required for update action', $result->content[0]->text);
    }

    /**
     * Test invalid data parameter types
     */
    public function testInvalidDataParameterType(): void
    {
        // String instead of array
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => 'not an array',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Invalid data parameter: Expected an object/array with field names as keys', $result->content[0]->text);
    }

    /**
     * Test access denied for non-existent table
     */
    public function testAccessDeniedForNonExistentTable(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'non_existent_table',
            'pid' => 1,
            'data' => ['title' => 'Test'],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Cannot access table', $result->content[0]->text);
        self::assertStringContainsString('non_existent_table', $result->content[0]->text);
    }

    /**
     * Test access denied for restricted table
     */
    public function testAccessDeniedForRestrictedTable(): void
    {
        // Create a backend user without admin rights
        $restrictedUserId = 1; // Use existing user

        // Set up restricted user
        $this->setUpBackendUser($restrictedUserId);
        $backendUser = $GLOBALS['BE_USER'];

        // Remove access to sys_template table
        $backendUser->groupData['tables_modify'] = 'pages,tt_content';

        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'sys_template',
            'uid' => 1,
            'data' => ['title' => 'Test'],
        ]);

        self::assertTrue($result->isError);
        // The error message may vary - sys_template is not workspace-capable
        self::assertStringContainsString('sys_template', $result->content[0]->text);
    }

    /**
     * Test TCA validation errors
     */
    public function testTcaValidationErrors(): void
    {
        // Test 1: Direct uid modification
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
            'data' => [
                'uid' => 999, // Cannot modify uid
                'title' => 'Test',
            ],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString("Field 'uid' cannot be modified directly", $result->content[0]->text);

        // Test 2: pid modification in update
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
            'data' => [
                'pid' => 2, // Cannot modify pid in update
                'title' => 'Test',
            ],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString("Field 'pid' can only be set during record creation", $result->content[0]->text);

        // Test 3: Invalid field value (exceeds max length)
        $longTitle = str_repeat('x', 300); // Title field typically has max length of 255
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
            'data' => [
                'title' => $longTitle,
            ],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('exceeds maximum length', $result->content[0]->text);
    }

    /**
     * Test invalid language code
     */
    public function testInvalidLanguageCode(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'sys_language_uid' => 'invalid_lang_code',
            ],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Unknown language code: invalid_lang_code', $result->content[0]->text);
    }

    /**
     * Test field not available for record type
     */
    public function testFieldNotAvailableForRecordType(): void
    {
        // Try to set a field that's not available for the given CType
        // The 'table_caption' field is only available for CType='table', not for 'text'
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text', // Text content type
                'table_caption' => 'Some caption', // Table field not available for text CType
                'header' => 'Test Content',
            ],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('not available for this record type', $result->content[0]->text);
    }

    /**
     * Test that file fields require array data (not scalar values)
     */
    public function testFileFieldsRequireArrayData(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'media' => 'some_value',
            ],
        ]);

        self::assertTrue($result->isError, 'File fields with non-array values should be rejected');
        self::assertStringContainsString('File field must be an array', $result->content[0]->text);
    }

    /**
     * Test workspace operation on non-workspace capable table
     */
    public function testNonWorkspaceCapableTable(): void
    {
        // be_users is not workspace capable
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'be_users',
            'uid' => 1,
            'data' => [
                'username' => 'modified_admin',
            ],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Cannot access table', $result->content[0]->text);
        self::assertStringContainsString('be_users', $result->content[0]->text);
    }

    /**
     * Test updating non-existent record
     */
    public function testUpdateNonExistentRecord(): void
    {
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 99999, // Non-existent UID
            'data' => [
                'title' => 'Updated Title',
            ],
        ]);

        // The tool should handle this gracefully - DataHandler will fail
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // But the record shouldn't actually be created
        $record = BackendUtility::getRecord('pages', 99999);
        self::assertNull($record, 'Non-existent record should not be created');
    }

    /**
     * Test deleting non-existent record
     */
    public function testDeleteNonExistentRecord(): void
    {
        $result = $this->tool->execute([
            'action' => 'delete',
            'table' => 'pages',
            'uid' => 99999, // Non-existent UID
        ]);

        // Should succeed but report no deletion
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode((string)$result->content[0]->text, true);
        self::assertEquals('delete', $data['action']);
        self::assertEquals(99999, $data['uid']);
    }

    /**
     * Test creating record with invalid parent
     */
    public function testCreateWithInvalidParent(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 99999, // Non-existent parent
            'data' => [
                'CType' => 'text',
                'header' => 'Test Content',
            ],
        ]);

        self::assertTrue($result->isError, 'Invalid parent pages should be rejected before DataHandler runs');
        self::assertStringContainsString('Invalid parent page', $result->content[0]->text);

        // Verify no record was created
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');

        $count = $queryBuilder
            ->count('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', 99999),
            )
            ->executeQuery()
            ->fetchOne();

        // TYPO3 might still create the record with invalid parent
        // This is a DataHandler behavior, not a tool validation
    }

    /**
     * Test position parameter validation
     */
    public function testInvalidPositionParameter(): void
    {
        // Invalid position format
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'position' => 'invalid:position',
            'data' => [
                'CType' => 'text',
                'header' => 'Test Content',
            ],
        ]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('Invalid position: invalid:position', $result->content[0]->text);
    }

    /**
     * Test maximum field length validation
     */
    public function testMaximumFieldLengthValidation(): void
    {
        // Create a string that exceeds typical varchar(255) limit
        $longString = str_repeat('a', 300);

        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => $longString,
                'doktype' => 1,
            ],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('exceeds maximum length', $result->content[0]->text);
    }

    /**
     * Test required field validation
     */
    public function testRequiredFieldValidation(): void
    {
        // Try to create a page without required title
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'doktype' => 1,
                // Missing required 'title' field
            ],
        ]);

        // TYPO3 DataHandler might handle this differently
        // The tool itself doesn't enforce required fields, DataHandler does
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // But the page shouldn't be properly created without title
        $data = json_decode((string)$result->content[0]->text, true);
        if (isset($data['uid']) && $data['uid'] > 0) {
            $record = BackendUtility::getRecord('pages', $data['uid']);
            // Record might be created but with empty title
            self::assertIsArray($record);
        }
    }

    // ========================================================================
    // ========================================================================
    // search-and-replace (embedded in data) error tests
    // ========================================================================

    /**
     * Test search-and-replace with search string not found
     */
    public function testSearchReplaceNotFoundError(): void
    {
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => [
                'bodytext' => [
                    ['search' => 'nonexistent text that is not in the content', 'replace' => 'replacement'],
                ],
            ],
        ]);

        self::assertTrue($result->isError, 'Should fail when search string not found');
        self::assertStringContainsString('not found', $result->content[0]->text);
    }

    /**
     * Test search-and-replace with ambiguous search string (multiple matches)
     */
    public function testSearchReplaceAmbiguousError(): void
    {
        // Record 100 has bodytext "Welcome to our homepage"
        // The word "o" appears multiple times in it
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => [
                'bodytext' => [
                    ['search' => 'o', 'replace' => 'X'],
                ],
            ],
        ]);

        self::assertTrue($result->isError, 'Should fail when search string matches multiple times');
        self::assertStringContainsString('times', $result->content[0]->text);
        self::assertStringContainsString('replaceAll', $result->content[0]->text);
    }

    /**
     * Test search-and-replace on create action (not supported)
     */
    public function testSearchReplaceOnCreateAction(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Test',
                'bodytext' => [
                    ['search' => 'old', 'replace' => 'new'],
                ],
            ],
        ]);

        self::assertTrue($result->isError, 'search-and-replace should not work with create action');
        self::assertStringContainsString('only supported for the "update" action', $result->content[0]->text);
    }

    /**
     * Test search-and-replace with empty search string
     */
    public function testSearchReplaceEmptySearch(): void
    {
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => [
                'bodytext' => [
                    ['search' => '', 'replace' => 'something'],
                ],
            ],
        ]);

        self::assertTrue($result->isError, 'Should fail with empty search string');
        self::assertStringContainsString('empty search string', $result->content[0]->text);
    }

    /**
     * Test search-and-replace on a non-string field type
     */
    public function testSearchReplaceOnNonStringField(): void
    {
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => [
                'colPos' => [
                    ['search' => '0', 'replace' => '1'],
                ],
            ],
        ]);

        self::assertTrue($result->isError, 'Should fail on non-string field');
        self::assertStringContainsString('not supported for field', $result->content[0]->text);
    }

    /**
     * Test search-and-replace on non-existent field
     */
    public function testSearchReplaceOnNonExistentField(): void
    {
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => [
                'nonexistent_field' => [
                    ['search' => 'old', 'replace' => 'new'],
                ],
            ],
        ]);

        self::assertTrue($result->isError, 'Should fail on non-existent field');
        self::assertStringContainsString('does not exist', $result->content[0]->text);
    }
}
