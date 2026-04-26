<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates sys_file_reference rows and updates parent TCA file fields via DataHandler (workspace-safe).
 */
final readonly class FileReferenceAttachmentService
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param int $parentUidForUpdate Primary key of the parent row in the **current** workspace/backend context
     *        (same uid used for DataHandler updates). This is usually `resolveToWorkspaceUid()` output: for a
     *        workspace draft row it is the version row uid, not `t3ver_oid`. `sys_file_reference.uid_foreign`
     *        must match this uid or references attach to the wrong row and file fields stay empty on FE.
     * @param array<int|string, mixed> $fileItems sys_file UIDs or objects with uid + optional metadata
     * @param bool $append When true, existing sys_file_reference UIDs on the parent field are preserved and new ones are appended
     */
    public function attachFilesToField(
        string $parentTable,
        int $parentUidForUpdate,
        int $pid,
        string $fieldName,
        array $fileItems,
        bool $append,
    ): void {
        if ($fileItems === []) {
            return;
        }

        $existingRefUids = [];
        if ($append) {
            $existingRefUids = $this->fetchExistingFileReferenceUids($parentTable, $parentUidForUpdate, $fieldName);
        }

        $dataMap = [];
        $refCount = 0;
        $baseSorting = count($existingRefUids);

        foreach ($fileItems as $index => $item) {
            $fileUid = null;
            $metadata = [];

            if (is_numeric($item)) {
                $fileUid = (int)$item;
            } elseif (is_array($item)) {
                $fileUid = isset($item['uid']) && is_numeric($item['uid']) ? (int)$item['uid'] : null;
                unset($item['uid']);
                $metadata = $item;
            }

            if ($fileUid === null || $fileUid <= 0) {
                continue;
            }

            $newRefId = 'NEW_file_' . uniqid() . '_' . $index;

            $refData = [
                'uid_local' => $fileUid,
                'uid_foreign' => $parentUidForUpdate,
                'tablenames' => $parentTable,
                'fieldname' => $fieldName,
                'pid' => $pid,
                'sorting_foreign' => ($baseSorting + (int)$index + 1) * 256,
            ];

            $allowedMetadataFields = ['title', 'description', 'alternative', 'link', 'crop', 'autoplay', 'showinpreview'];
            foreach ($allowedMetadataFields as $metaField) {
                if (isset($metadata[$metaField]) && (is_string($metadata[$metaField]) || is_numeric($metadata[$metaField]))) {
                    $refData[$metaField] = $metadata[$metaField];
                }
            }

            $dataMap['sys_file_reference'][$newRefId] = $refData;
            $refCount++;
        }

        if ($refCount === 0) {
            return;
        }

        $refDataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $this->assignBackendUser($refDataHandler);
        $refDataHandler->start($dataMap, []);
        $refDataHandler->process_datamap();

        $createdRefUids = [];
        foreach ($refDataHandler->substNEWwithIDs as $newId => $realId) {
            if (is_string($newId) && str_starts_with($newId, 'NEW_file_') && is_numeric($realId)) {
                $createdRefUids[] = (int)$realId;
            }
        }

        if ($createdRefUids === []) {
            return;
        }

        $allRefUids = $append ? array_merge($existingRefUids, $createdRefUids) : $createdRefUids;
        $refUidList = implode(',', array_map(static fn(int $uid): string => (string)$uid, $allRefUids));

        $updateDataMap = [
            $parentTable => [
                $parentUidForUpdate => [
                    $fieldName => $refUidList,
                ],
            ],
        ];
        $updateDataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $this->assignBackendUser($updateDataHandler);
        $updateDataHandler->start($updateDataMap, []);
        $updateDataHandler->process_datamap();

        // After both DataHandler passes: workspace handling can re-map sys_file_reference.uid_foreign to
        // the live (t3ver_oid) parent. For version rows the reference must use the same primary key as
        // $parentUidForUpdate or the file field and frontend resolution stay empty.
        $connection = $this->connectionPool->getConnectionForTable('sys_file_reference');
        foreach ($createdRefUids as $refUid) {
            $connection->update(
                'sys_file_reference',
                ['uid_foreign' => $parentUidForUpdate],
                ['uid' => $refUid],
            );
        }
    }

    /**
     * @return list<int>
     */
    private function fetchExistingFileReferenceUids(string $parentTable, int $parentUidForUpdate, string $fieldName): array
    {
        $record = BackendUtility::getRecord($parentTable, $parentUidForUpdate, $fieldName);
        if (!is_array($record)) {
            return [];
        }

        $raw = (string)($record[$fieldName] ?? '');
        if ($raw === '') {
            return [];
        }

        $uids = GeneralUtility::trimExplode(',', $raw, true);
        $out = [];
        foreach ($uids as $uid) {
            if (is_numeric($uid) && (int)$uid > 0) {
                $out[] = (int)$uid;
            }
        }

        return $out;
    }

    private function assignBackendUser(DataHandler $dataHandler): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('Backend user context not initialized');
        }

        $dataHandler->BE_USER = $backendUser;
    }
}
