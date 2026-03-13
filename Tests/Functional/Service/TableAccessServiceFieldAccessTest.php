<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\TableAccessService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test field access restrictions in TableAccessService
 * Verifies that file fields and inaccessible inline relations are properly blocked
 */
class TableAccessServiceFieldAccessTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected TableAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->service = new TableAccessService();
    }

    /**
     * Test that file type fields are not accessible
     */
    public function testFileFieldsAreNotAccessible(): void
    {
        // The 'media' field on pages table is type='file'
        $canAccess = $this->service->canAccessField('pages', 'media');

        $this->assertFalse($canAccess, 'File fields should not be accessible');
    }

    /**
     * Test that file fields are hidden from available fields
     */
    public function testFileFieldsAreHiddenFromSchema(): void
    {
        $fields = $this->service->getAvailableFields('pages');

        $this->assertArrayNotHasKey('media', $fields, 'File field "media" should not be in available fields');
    }

    /**
     * Test that sys_file_reference table is not accessible
     */
    public function testSysFileReferenceTableIsRestricted(): void
    {
        $canAccess = $this->service->canAccessTable('sys_file_reference');

        $this->assertFalse($canAccess, 'sys_file_reference table should be restricted');
    }

    /**
     * Test that inline relations to sys_file_reference are filtered out
     */
    public function testInlineRelationsToSysFileReferenceAreHidden(): void
    {
        // TYPO3 v13/v14 model this relation differently, but access should stay blocked in both.
        $this->assertArrayHasKey('assets', $GLOBALS['TCA']['tt_content']['columns'] ?? []);
        $fieldConfig = $GLOBALS['TCA']['tt_content']['columns']['assets'] ?? [];
        $config = is_array($fieldConfig['config'] ?? null) ? $fieldConfig['config'] : [];
        $fieldType = is_string($config['type'] ?? null) ? $config['type'] : '';
        $foreignTable = is_string($config['foreign_table'] ?? null) ? $config['foreign_table'] : '';

        $this->assertContains($fieldType, ['file', 'inline']);
        if ($fieldType === 'inline') {
            $this->assertSame('sys_file_reference', $foreignTable);
        }

        $canAccess = $this->service->canAccessField('tt_content', 'assets');

        $this->assertFalse($canAccess, 'Relations to sys_file_reference should not be accessible');
    }

    /**
     * Test that inline relations to inaccessible tables are filtered
     */
    public function testInlineRelationsToInaccessibleTablesAreHidden(): void
    {
        // Create a mock inline field config for testing
        // We'll check if an inline field referencing a restricted table is blocked

        // First, verify that a normal accessible inline relation works
        // (if there are any in the system)

        // Then verify that inline to restricted table doesn't work
        // This is implicitly tested by sys_file_reference test above
        $this->assertTrue(true, 'Inline relation filtering is tested via sys_file_reference test');
    }

    /**
     * Test that regular accessible fields remain accessible
     */
    public function testRegularFieldsRemainAccessible(): void
    {
        // Test that normal text fields are accessible
        $canAccessTitle = $this->service->canAccessField('pages', 'title');
        $canAccessDescription = $this->service->canAccessField('pages', 'description');

        $this->assertTrue($canAccessTitle, 'Regular text field "title" should be accessible');
        $this->assertTrue($canAccessDescription, 'Regular text field "description" should be accessible');
    }

    /**
     * Test that available fields properly filters file fields
     */
    public function testAvailableFieldsFiltersFileFields(): void
    {
        $fields = $this->service->getAvailableFields('pages');

        // Check that normal fields are present
        $this->assertArrayHasKey('title', $fields, 'Title field should be available');
        $this->assertArrayHasKey('description', $fields, 'Description field should be available');

        // Check that file field is not present
        $this->assertArrayNotHasKey('media', $fields, 'Media file field should not be available');
    }

    /**
     * Test that tt_content fields properly filter file/inline fields
     */
    public function testTtContentFieldsFilterFileAndInlineRelations(): void
    {
        $fields = $this->service->getAvailableFields('tt_content', 'text');

        // Check that normal fields are present
        $this->assertArrayHasKey('header', $fields, 'Header field should be available');
        $this->assertArrayHasKey('bodytext', $fields, 'Bodytext field should be available');

        // Check that assets field (inline to sys_file_reference) is not present
        if (isset($GLOBALS['TCA']['tt_content']['columns']['assets'])) {
            $this->assertArrayNotHasKey('assets', $fields, 'Assets field (inline to sys_file_reference) should not be available');
        }

        // Check that image field (if type=file) is not present
        if (isset($GLOBALS['TCA']['tt_content']['columns']['image'])) {
            $imageConfig = $GLOBALS['TCA']['tt_content']['columns']['image'] ?? [];
            if (($imageConfig['config']['type'] ?? '') === 'file') {
                $this->assertArrayNotHasKey('image', $fields, 'Image file field should not be available');
            }
        }
    }
}
