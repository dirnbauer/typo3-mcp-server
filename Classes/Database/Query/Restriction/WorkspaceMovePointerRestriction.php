<?php

declare(strict_types=1);

namespace Hn\McpServer\Database\Query\Restriction;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Query restriction to exclude live records that have move pointers in the current workspace.
 *
 * TYPO3 stores moves in workspaces as separate overlay rows with t3ver_state=4 and the new pid/sorting.
 * Without excluding the original live row, page-based queries leak the old location while also showing
 * the move pointer in the new location. This restriction keeps record positioning transparent to MCP clients.
 */
final class WorkspaceMovePointerRestriction implements QueryRestrictionInterface
{
    public function __construct(protected int $workspaceId) {}

    /**
     * @param array<string, string> $queriedTables
     */
    public function buildExpression(array $queriedTables, ExpressionBuilder $expressionBuilder): CompositeExpression
    {
        $constraints = [];

        if ($this->workspaceId === 0) {
            return $expressionBuilder->and();
        }

        foreach ($queriedTables as $tableAlias => $tableName) {
            $globalTca = $GLOBALS['TCA'] ?? null;
            $tableConfig = \is_array($globalTca) ? ($globalTca[$tableName] ?? null) : null;
            $ctrl = \is_array($tableConfig) && \is_array($tableConfig['ctrl'] ?? null) ? $tableConfig['ctrl'] : [];
            if (empty($ctrl['versioningWS'])) {
                continue;
            }

            try {
                $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
                $subQueryBuilder = $connectionPool->getQueryBuilderForTable($tableName);
                $subQueryBuilder->getRestrictions()->removeAll();

                $subQuery = $subQueryBuilder
                    ->select('t3ver_oid')
                    ->from($tableName)
                    ->where(
                        $subQueryBuilder->expr()->eq(
                            't3ver_state',
                            $subQueryBuilder->expr()->literal((string)VersionState::MOVE_POINTER->value),
                        ),
                        $subQueryBuilder->expr()->eq(
                            't3ver_wsid',
                            $subQueryBuilder->expr()->literal((string)$this->workspaceId),
                        ),
                        $subQueryBuilder->expr()->gt(
                            't3ver_oid',
                            $subQueryBuilder->expr()->literal('0'),
                        ),
                    );

                $constraints[] = $expressionBuilder->notIn(
                    $tableAlias . '.uid',
                    $subQuery->getSQL(),
                );
            } catch (\Throwable) {
            }
        }

        return $expressionBuilder->and(...$constraints);
    }
}
