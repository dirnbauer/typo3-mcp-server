<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileType;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Ensures TYPO3 has required image dimensions before MCP creates file references.
 */
final readonly class FileMetadataIndexService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private ResourceFactory $resourceFactory,
    ) {}

    public function ensureImageMetadataForFileUid(int $fileUid): void
    {
        if ($fileUid <= 0) {
            return;
        }

        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (FileDoesNotExistException) {
            return;
        }

        $this->ensureImageMetadataForFile($file);
    }

    public function ensureImageMetadataForFile(File $file): void
    {
        if (!$file->isType(FileType::IMAGE) || !$file->exists()) {
            return;
        }

        if ($this->hasRequiredImageMetadata($file->getUid())) {
            return;
        }

        GeneralUtility::makeInstance(Indexer::class, $file->getStorage())->updateIndexEntry($file);
    }

    private function hasRequiredImageMetadata(int $fileUid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('width', 'height')
            ->from('sys_file_metadata')
            ->where(
                $queryBuilder->expr()->eq('file', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([0, -1], Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row)
            && is_numeric($row['width'] ?? null)
            && is_numeric($row['height'] ?? null)
            && (int)$row['width'] > 0
            && (int)$row['height'] > 0;
    }
}
