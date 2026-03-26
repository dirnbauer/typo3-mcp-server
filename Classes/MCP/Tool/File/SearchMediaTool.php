<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Search for files across all TYPO3 file storage by metadata, type, or dimensions.
 */
final class SearchMediaTool extends AbstractTool
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Search for files across all TYPO3 file storage by metadata, type, or dimensions. '
                . 'Returns file UID, name, path, MIME type, dimensions, and metadata summary. '
                . 'Useful for finding images, PDFs, or other media to attach to records via WriteTable. '
                . 'Use ReadFileMetadata for full details on a specific file.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'keyword' => [
                        'type' => 'string',
                        'description' => 'Search in file name, metadata title, description, and alternative text (case-insensitive LIKE match)',
                    ],
                    'mimeType' => [
                        'type' => 'string',
                        'description' => 'Filter by MIME type prefix, e.g. "image/", "application/pdf", "video/"',
                    ],
                    'extension' => [
                        'type' => 'string',
                        'description' => 'Filter by file extension, e.g. "jpg", "pdf", "svg"',
                    ],
                    'folder' => [
                        'type' => 'string',
                        'description' => 'Filter by folder path prefix within storage, e.g. "/user_upload/"',
                    ],
                    'minWidth' => [
                        'type' => 'integer',
                        'description' => 'Minimum image width in pixels',
                        'minimum' => 1,
                    ],
                    'minHeight' => [
                        'type' => 'integer',
                        'description' => 'Minimum image height in pixels',
                        'minimum' => 1,
                    ],
                    'createdAfter' => [
                        'type' => 'string',
                        'description' => 'ISO date (YYYY-MM-DD): only files created after this date',
                    ],
                    'createdBefore' => [
                        'type' => 'string',
                        'description' => 'ISO date (YYYY-MM-DD): only files created before this date',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of results (default: 50, max: 200)',
                        'default' => self::DEFAULT_LIMIT,
                        'minimum' => 1,
                        'maximum' => self::MAX_LIMIT,
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'Offset for pagination (default: 0)',
                        'default' => 0,
                        'minimum' => 0,
                    ],
                ],
                'required' => [],
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
        $keyword = \is_string($params['keyword'] ?? null) ? trim($params['keyword']) : '';
        $mimeType = \is_string($params['mimeType'] ?? null) ? trim($params['mimeType']) : '';
        $extension = \is_string($params['extension'] ?? null) ? trim($params['extension']) : '';
        $folder = \is_string($params['folder'] ?? null) ? trim($params['folder']) : '';
        $minWidth = is_numeric($params['minWidth'] ?? null) ? (int)$params['minWidth'] : null;
        $minHeight = is_numeric($params['minHeight'] ?? null) ? (int)$params['minHeight'] : null;
        $createdAfter = \is_string($params['createdAfter'] ?? null) ? trim($params['createdAfter']) : '';
        $createdBefore = \is_string($params['createdBefore'] ?? null) ? trim($params['createdBefore']) : '';
        $limit = is_numeric($params['limit'] ?? null) ? min((int)$params['limit'], self::MAX_LIMIT) : self::DEFAULT_LIMIT;
        $offset = is_numeric($params['offset'] ?? null) ? max((int)$params['offset'], 0) : 0;

        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        $hasFilter = $keyword !== '' || $mimeType !== '' || $extension !== '' || $folder !== ''
            || $minWidth !== null || $minHeight !== null || $createdAfter !== '' || $createdBefore !== '';

        if (!$hasFilter) {
            throw new ValidationException(['At least one filter parameter is required (keyword, mimeType, extension, folder, minWidth, minHeight, createdAfter, or createdBefore)']);
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $qb->select(
            'f.uid',
            'f.name',
            'f.identifier',
            'f.storage',
            'f.mime_type',
            'f.extension',
            'f.size',
        )
            ->addSelectLiteral(
                $qb->quoteIdentifier('m.width') . ' AS meta_width',
                $qb->quoteIdentifier('m.height') . ' AS meta_height',
                $qb->quoteIdentifier('m.title') . ' AS meta_title',
                $qb->quoteIdentifier('m.description') . ' AS meta_description',
                $qb->quoteIdentifier('m.alternative') . ' AS meta_alternative',
            )
            ->from('sys_file', 'f')
            ->leftJoin(
                'f',
                'sys_file_metadata',
                'm',
                $qb->expr()->eq('m.file', $qb->quoteIdentifier('f.uid')),
            );

        // Exclude deleted/missing files (type 0 = unknown/missing placeholder)
        // Type 1 = text, 2 = image, 3 = audio, 4 = video, 5 = application
        $qb->andWhere($qb->expr()->gt('f.type', $qb->createNamedParameter(0, Connection::PARAM_INT)));

        if ($keyword !== '') {
            $likeKeyword = '%' . $qb->escapeLikeWildcards($keyword) . '%';
            $qb->andWhere($qb->expr()->or(
                $qb->expr()->like('f.name', $qb->createNamedParameter($likeKeyword)),
                $qb->expr()->like('m.title', $qb->createNamedParameter($likeKeyword)),
                $qb->expr()->like('m.description', $qb->createNamedParameter($likeKeyword)),
                $qb->expr()->like('m.alternative', $qb->createNamedParameter($likeKeyword)),
            ));
        }

        if ($mimeType !== '') {
            $likeMime = $qb->escapeLikeWildcards($mimeType) . '%';
            $qb->andWhere($qb->expr()->like('f.mime_type', $qb->createNamedParameter($likeMime)));
        }

        if ($extension !== '') {
            $qb->andWhere($qb->expr()->eq('f.extension', $qb->createNamedParameter(ltrim($extension, '.'))));
        }

        if ($folder !== '') {
            $likeFolder = $qb->escapeLikeWildcards($folder) . '%';
            $qb->andWhere($qb->expr()->like('f.identifier', $qb->createNamedParameter($likeFolder)));
        }

        if ($minWidth !== null) {
            $qb->andWhere($qb->expr()->gte('m.width', $qb->createNamedParameter($minWidth, Connection::PARAM_INT)));
        }

        if ($minHeight !== null) {
            $qb->andWhere($qb->expr()->gte('m.height', $qb->createNamedParameter($minHeight, Connection::PARAM_INT)));
        }

        if ($createdAfter !== '') {
            $ts = strtotime($createdAfter);
            if ($ts === false) {
                throw new ValidationException(['Invalid createdAfter date format. Use YYYY-MM-DD.']);
            }
            $qb->andWhere($qb->expr()->gte('f.creation_date', $qb->createNamedParameter($ts, Connection::PARAM_INT)));
        }

        if ($createdBefore !== '') {
            $ts = strtotime($createdBefore . ' 23:59:59');
            if ($ts === false) {
                throw new ValidationException(['Invalid createdBefore date format. Use YYYY-MM-DD.']);
            }
            $qb->andWhere($qb->expr()->lte('f.creation_date', $qb->createNamedParameter($ts, Connection::PARAM_INT)));
        }

        // Count total using a separate query builder (TYPO3 v14 QueryBuilder has no resetQueryPart)
        $countQb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $countQb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $countQb->count('f.uid')
            ->from('sys_file', 'f')
            ->leftJoin('f', 'sys_file_metadata', 'm', $countQb->expr()->eq('m.file', $countQb->quoteIdentifier('f.uid')));
        $countQb->andWhere($countQb->expr()->gt('f.type', $countQb->createNamedParameter(0, Connection::PARAM_INT)));

        if ($keyword !== '') {
            $countLikeKeyword = '%' . $countQb->escapeLikeWildcards($keyword) . '%';
            $countQb->andWhere($countQb->expr()->or(
                $countQb->expr()->like('f.name', $countQb->createNamedParameter($countLikeKeyword)),
                $countQb->expr()->like('m.title', $countQb->createNamedParameter($countLikeKeyword)),
                $countQb->expr()->like('m.description', $countQb->createNamedParameter($countLikeKeyword)),
                $countQb->expr()->like('m.alternative', $countQb->createNamedParameter($countLikeKeyword)),
            ));
        }
        if ($mimeType !== '') {
            $countQb->andWhere($countQb->expr()->like('f.mime_type', $countQb->createNamedParameter($countQb->escapeLikeWildcards($mimeType) . '%')));
        }
        if ($extension !== '') {
            $countQb->andWhere($countQb->expr()->eq('f.extension', $countQb->createNamedParameter(ltrim($extension, '.'))));
        }
        if ($folder !== '') {
            $countQb->andWhere($countQb->expr()->like('f.identifier', $countQb->createNamedParameter($countQb->escapeLikeWildcards($folder) . '%')));
        }
        if ($minWidth !== null) {
            $countQb->andWhere($countQb->expr()->gte('m.width', $countQb->createNamedParameter($minWidth, Connection::PARAM_INT)));
        }
        if ($minHeight !== null) {
            $countQb->andWhere($countQb->expr()->gte('m.height', $countQb->createNamedParameter($minHeight, Connection::PARAM_INT)));
        }
        if ($createdAfter !== '') {
            $countQb->andWhere($countQb->expr()->gte('f.creation_date', $countQb->createNamedParameter((int)strtotime($createdAfter), Connection::PARAM_INT)));
        }
        if ($createdBefore !== '') {
            $countQb->andWhere($countQb->expr()->lte('f.creation_date', $countQb->createNamedParameter((int)strtotime($createdBefore . ' 23:59:59'), Connection::PARAM_INT)));
        }

        $totalValue = $countQb->executeQuery()->fetchOne();
        $total = is_numeric($totalValue) ? (int)$totalValue : 0;

        // Fetch results
        $qb->orderBy('f.uid', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $files = [];
        foreach ($rows as $row) {
            $file = [
                'uid' => is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0,
                'name' => \is_scalar($row['name'] ?? null) ? (string)$row['name'] : '',
                'identifier' => \is_scalar($row['identifier'] ?? null) ? (string)$row['identifier'] : '',
                'storage' => is_numeric($row['storage'] ?? null) ? (int)$row['storage'] : 0,
                'mimeType' => \is_scalar($row['mime_type'] ?? null) ? (string)$row['mime_type'] : '',
                'extension' => \is_scalar($row['extension'] ?? null) ? (string)$row['extension'] : '',
                'size' => is_numeric($row['size'] ?? null) ? (int)$row['size'] : 0,
                'sizeFormatted' => GeneralUtility::formatSize(is_numeric($row['size'] ?? null) ? (int)$row['size'] : 0, 'si'),
            ];

            $width = is_numeric($row['meta_width'] ?? null) ? (int)$row['meta_width'] : 0;
            $height = is_numeric($row['meta_height'] ?? null) ? (int)$row['meta_height'] : 0;
            if ($width > 0 || $height > 0) {
                $file['width'] = $width;
                $file['height'] = $height;
            }

            $file['metadata'] = [
                'title' => \is_scalar($row['meta_title'] ?? null) ? (string)$row['meta_title'] : '',
                'description' => \is_scalar($row['meta_description'] ?? null) ? (string)$row['meta_description'] : '',
                'alternative' => \is_scalar($row['meta_alternative'] ?? null) ? (string)$row['meta_alternative'] : '',
            ];

            $files[] = $file;
        }

        $returned = \count($files);
        $hasMore = ($offset + $returned) < $total;

        $result = [
            'files' => $files,
            'total' => $total,
            'returned' => $returned,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => $hasMore,
        ];

        if ($hasMore) {
            $result['nextOffset'] = $offset + $returned;
        }

        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        return new CallToolResult([new TextContent($json)]);
    }
}
