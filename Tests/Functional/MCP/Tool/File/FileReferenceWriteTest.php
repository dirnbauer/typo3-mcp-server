<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\File;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests for file reference creation and reading via type=file fields
 */
class FileReferenceWriteTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;
    private ReadTableTool $readTool;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure sys_file fixtures exist
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/sys_file.csv');

        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $this->readTool = GeneralUtility::makeInstance(ReadTableTool::class);
    }

    /**
     * Creating a page with file references using plain sys_file UIDs
     */
    public function testCreatePageWithFileReferenceUidShorthand(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'title' => 'Page with media',
                'media' => [1],
            ],
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        self::assertArrayHasKey('uid', $data);
    }

    /**
     * Creating a page with file references using object format with metadata
     */
    public function testCreatePageWithFileReferenceObjectFormat(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'title' => 'Page with titled media',
                'media' => [
                    ['uid_local' => 1, 'title' => 'Test Image', 'alternative' => 'Alt text'],
                ],
            ],
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $references = $this->getFileReferences('pages', 'media', $data['uid']);
        self::assertCount(1, $references);
        self::assertSame(1, (int)$references[0]['uid_local']);
    }

    /**
     * Creating content with multiple file references using object format
     */
    public function testCreateContentWithMultipleFileReferences(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Content with assets',
                'assets' => [
                    ['uid_local' => 1],
                    ['uid_local' => 2],
                ],
            ],
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        self::assertArrayHasKey('uid', $data);
    }

    /**
     * File field validation rejects invalid data (string instead of array)
     */
    public function testFileFieldRejectsStringValue(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'title' => 'Invalid media',
                'media' => 'not_an_array',
            ],
        ]);

        $this->assertToolError($result);
    }

    /**
     * File field validation rejects objects without uid_local
     */
    public function testFileFieldRejectsObjectWithoutUidLocal(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'title' => 'Missing uid_local',
                'media' => [
                    ['title' => 'No uid_local provided'],
                ],
            ],
        ]);

        $this->assertToolError($result, 'uid_local');
    }

    /**
     * ReadTableTool returns file field count (sys_file_reference is restricted)
     */
    public function testReadTableExpandsFileFields(): void
    {
        // Create a page with file reference first
        $createResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'title' => 'Page for reading',
                'media' => [
                    ['uid_local' => 1],
                ],
            ],
        ]);

        $this->assertSuccessfulToolResult($createResult);
        $createData = $this->extractJsonFromResult($createResult);

        // Now read the page
        $readResult = $this->readTool->execute([
            'table' => 'pages',
            'uid' => $createData['uid'],
            'fields' => ['title', 'media'],
        ]);

        $this->assertSuccessfulToolResult($readResult);
        $readData = json_decode((string)$readResult->content[0]->text, true);

        $records = $readData['records'] ?? [];
        self::assertNotEmpty($records);

        $record = $records[0];
        self::assertEquals('Page for reading', $record['title']);
    }

    /**
     * Helper: get sys_file_reference records for a parent record
     */
    private function getFileReferences(string $tablenames, string $fieldname, int $uidForeign): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');

        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($tablenames)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($fieldname)),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($uidForeign, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
