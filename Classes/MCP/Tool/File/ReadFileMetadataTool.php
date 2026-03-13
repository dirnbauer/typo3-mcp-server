<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;

class ReadFileMetadataTool extends AbstractTool
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Read detailed metadata for a file by UID or combined identifier. '
                . 'Returns title, description, alternative text, categories, dimensions, and more. '
                . 'Use browse_files to find file UIDs first.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'File UID (sys_file.uid)',
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'Combined identifier, e.g. "1:/user_upload/photo.jpg". Use uid OR identifier, not both.',
                    ],
                ],
                'required' => [],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $uid = isset($params['uid']) ? (int)$params['uid'] : null;
        $identifier = $params['identifier'] ?? null;

        if ($uid === null && $identifier === null) {
            throw new ValidationException(['Either uid or identifier must be provided']);
        }

        try {
            if ($uid !== null) {
                $file = $this->resourceFactory->getFileObject($uid);
            } else {
                $file = $this->resourceFactory->getFileObjectFromCombinedIdentifier($identifier);
            }
        } catch (\Exception $e) {
            throw new ValidationException(['File not found: ' . $e->getMessage()]);
        }

        $props = $file->getProperties();
        $metaData = $file->getMetaData()->get();

        $result = [
            'uid' => $file->getUid(),
            'name' => $file->getName(),
            'identifier' => $file->getCombinedIdentifier(),
            'mimeType' => $file->getMimeType(),
            'size' => $file->getSize(),
            'sizeFormatted' => \TYPO3\CMS\Core\Utility\GeneralUtility::formatSize($file->getSize(), 'si'),
            'extension' => $file->getExtension(),
        ];

        if (str_starts_with($file->getMimeType(), 'image/')) {
            $result['width'] = (int)($props['width'] ?? 0);
            $result['height'] = (int)($props['height'] ?? 0);
        }

        $result['metadata'] = [
            'title' => $metaData['title'] ?? '',
            'description' => $metaData['description'] ?? '',
            'alternative' => $metaData['alternative'] ?? '',
            'copyright' => $metaData['copyright'] ?? '',
        ];

        $categories = $this->getFileCategories($file->getUid());
        if (!empty($categories)) {
            $result['categories'] = $categories;
        }

        $references = $this->getFileReferences($file->getUid());
        if (!empty($references)) {
            $result['usedIn'] = $references;
        }

        return new CallToolResult([new TextContent(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
    }

    /**
     * @return list<array{uid: int, title: string}>
     */
    private function getFileCategories(int $fileUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_category');
        $rows = $qb->select('c.uid', 'c.title')
            ->from('sys_category', 'c')
            ->join('c', 'sys_category_record_mm', 'mm', $qb->expr()->eq('mm.uid_local', $qb->quoteIdentifier('c.uid')))
            ->where(
                $qb->expr()->eq('mm.uid_foreign', $qb->createNamedParameter($fileUid, Connection::PARAM_INT)),
                $qb->expr()->eq('mm.tablenames', $qb->createNamedParameter('sys_file_metadata')),
                $qb->expr()->eq('mm.fieldname', $qb->createNamedParameter('categories'))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $r): array => ['uid' => (int)$r['uid'], 'title' => $r['title']], $rows);
    }

    /**
     * Returns a summary of where this file is referenced.
     *
     * @return list<array{table: string, uid: int, field: string}>
     */
    private function getFileReferences(int $fileUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $rows = $qb->select('tablenames', 'uid_foreign', 'fieldname')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('uid_local', $qb->createNamedParameter($fileUid, Connection::PARAM_INT))
            )
            ->setMaxResults(20)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $r): array => [
            'table' => $r['tablenames'],
            'uid' => (int)$r['uid_foreign'],
            'field' => $r['fieldname'],
        ], $rows);
    }
}
