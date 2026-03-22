<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\McpFileSandboxService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ReadFileMetadataTool extends AbstractTool
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly McpFileSandboxService $fileSandboxService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Read detailed metadata for a file in the MCP file sandbox by UID or path. '
                . 'All access is restricted to the configured MCP file sandbox root (default: fileadmin/mcp/). '
                . 'Returns title, description, alternative text, categories, dimensions, and more. '
                . 'Use browse_files to inspect the sandbox first.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'File UID (sys_file.uid). Provide uid or identifier.',
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'File path inside the MCP file sandbox. '
                            . 'Use a relative path like "images/photo.jpg" or an absolute combined identifier inside the sandbox such as "1:/mcp/images/photo.jpg". '
                            . 'Use uid OR identifier, not both.',
                    ],
                ],
                'required' => [],
                'oneOf' => [
                    ['required' => ['uid']],
                    ['required' => ['identifier']],
                ],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $uid = isset($params['uid']) && is_numeric($params['uid']) ? (int)$params['uid'] : null;
        $identifier = \is_string($params['identifier'] ?? null) ? $params['identifier'] : null;

        if ($uid === null && $identifier === null) {
            throw new ValidationException(['Either uid or identifier must be provided']);
        }

        try {
            if ($uid !== null) {
                $file = $this->resourceFactory->getFileObject($uid);
            } else {
                $resolved = $this->fileSandboxService->resolveFileTarget($identifier);
                $file = $this->resourceFactory->getFileObjectFromCombinedIdentifier($resolved['combinedIdentifier']);
            }
        } catch (\Exception $e) {
            throw new ValidationException(['File not found: ' . $e->getMessage()]);
        }

        if (!$file instanceof File && !$file instanceof ProcessedFile) {
            throw new ValidationException(['File could not be loaded']);
        }

        $this->fileSandboxService->assertFileAllowed($file);

        $props = $file->getProperties();
        $metadataFile = $file instanceof ProcessedFile ? $file->getOriginalFile() : $file;
        $metaData = $metadataFile->getMetaData()->get();

        $result = [
            'uid' => $file->getUid(),
            'name' => $file->getName(),
            'identifier' => $file->getCombinedIdentifier(),
            'mimeType' => $file->getMimeType(),
            'size' => $file->getSize(),
            'sizeFormatted' => GeneralUtility::formatSize($file->getSize(), 'si'),
            'extension' => $file->getExtension(),
        ];

        if (str_starts_with($file->getMimeType(), 'image/')) {
            $result['width'] = is_numeric($props['width'] ?? null) ? (int)$props['width'] : 0;
            $result['height'] = is_numeric($props['height'] ?? null) ? (int)$props['height'] : 0;
        }

        $result['metadata'] = [
            'title' => \is_scalar($metaData['title'] ?? null) ? (string)$metaData['title'] : '',
            'description' => \is_scalar($metaData['description'] ?? null) ? (string)$metaData['description'] : '',
            'alternative' => \is_scalar($metaData['alternative'] ?? null) ? (string)$metaData['alternative'] : '',
            'copyright' => \is_scalar($metaData['copyright'] ?? null) ? (string)$metaData['copyright'] : '',
        ];

        $categories = $this->getFileCategories($file->getUid());
        if (!empty($categories)) {
            $result['categories'] = $categories;
        }

        $references = $this->getFileReferences($file->getUid());
        if (!empty($references)) {
            $result['usedIn'] = $references;
        }

        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        return new CallToolResult([new TextContent($json)]);
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
                $qb->expr()->eq('mm.fieldname', $qb->createNamedParameter('categories')),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $r): array => [
                'uid' => is_numeric($r['uid'] ?? null) ? (int)$r['uid'] : 0,
                'title' => \is_scalar($r['title'] ?? null) ? (string)$r['title'] : '',
            ],
            $rows,
        );
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
                $qb->expr()->eq('uid_local', $qb->createNamedParameter($fileUid, Connection::PARAM_INT)),
            )
            ->setMaxResults(20)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $r): array => [
            'table' => \is_scalar($r['tablenames'] ?? null) ? (string)$r['tablenames'] : '',
            'uid' => is_numeric($r['uid_foreign'] ?? null) ? (int)$r['uid_foreign'] : 0,
            'field' => \is_scalar($r['fieldname'] ?? null) ? (string)$r['fieldname'] : '',
        ], $rows);
    }
}
