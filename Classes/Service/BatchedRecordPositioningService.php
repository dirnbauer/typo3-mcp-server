<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Assigns stable append positions to batched DataHandler creates.
 *
 * TYPO3 interprets a positive pid as "insert at the top of this parent/page".
 * For several NEW records in one DataHandler data map that can reverse the
 * visual order. This service chains DataHandler-native positions instead:
 * first NEW record after the last existing record, then each next NEW record
 * after the previous NEW placeholder.
 */
final readonly class BatchedRecordPositioningService
{
    public function __construct(
        private TableAccessService $tableAccessService,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $dataMap
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    public function assignAppendPositions(array $dataMap): array
    {
        /** @var array<string, int|string|null> $previousRecordByScope */
        $previousRecordByScope = [];

        foreach ($dataMap as $table => $records) {
            if ($this->tableAccessService->getSortingFieldName($table) === null) {
                continue;
            }

            foreach ($records as $recordId => $record) {
                if (!is_string($recordId) || !str_starts_with($recordId, 'NEW')) {
                    continue;
                }

                $pid = $this->extractNonNegativeInteger($record['pid'] ?? null);
                if ($pid === null) {
                    continue;
                }

                $scopeKey = $this->buildScopeKey($table, $pid, $record);
                if (!array_key_exists($scopeKey, $previousRecordByScope)) {
                    $previousRecordByScope[$scopeKey] = $this->fetchLastRecordUid($table, $pid, $record);
                }

                $previousRecord = $previousRecordByScope[$scopeKey];
                if ($previousRecord !== null) {
                    $dataMap[$table][$recordId]['pid'] = '-' . $previousRecord;
                }

                $previousRecordByScope[$scopeKey] = $recordId;
            }
        }

        return $dataMap;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function buildScopeKey(string $table, int $pid, array $record): string
    {
        $parts = [$table, 'pid=' . $pid];

        if ($table === 'tt_content') {
            $parts[] = 'colPos=' . ($this->extractInteger($record['colPos'] ?? null) ?? 0);
        }

        return implode('|', $parts);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function fetchLastRecordUid(string $table, int $pid, array $record): ?int
    {
        $sortingField = $this->tableAccessService->getSortingFieldName($table);
        if ($sortingField === null) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getWorkspaceId()));

        $constraints = [
            $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
        ];

        if ($table === 'tt_content') {
            $constraints[] = $queryBuilder->expr()->eq(
                'colPos',
                $queryBuilder->createNamedParameter(
                    $this->extractInteger($record['colPos'] ?? null) ?? 0,
                    ParameterType::INTEGER,
                ),
            );
        }

        $uid = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(...$constraints)
            ->orderBy($sortingField, 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($uid) ? (int)$uid : null;
    }

    private function getWorkspaceId(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return 0;
        }

        return $backendUser->workspace;
    }

    private function extractInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int)$value;
        }

        return null;
    }

    private function extractNonNegativeInteger(mixed $value): ?int
    {
        $integer = $this->extractInteger($value);
        return $integer !== null && $integer >= 0 ? $integer : null;
    }
}
