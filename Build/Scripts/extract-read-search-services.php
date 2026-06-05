<?php

declare(strict_types=1);

function sliceMethods(string $file, array $ranges): string
{
    $lines = file($file);
    $out = '';
    foreach ($ranges as [$start, $end]) {
        $chunk = array_slice($lines, $start - 1, $end - $start + 1);
        $chunk[0] = preg_replace('/^\s+(?:protected|private) function /', '    public function ', $chunk[0]) ?? $chunk[0];
        $out .= implode('', $chunk) . "\n";
    }

    return $out;
}

function writeService(string $path, string $header, string $body, array $replacements = []): void
{
    foreach ($replacements as $search => $replace) {
        $body = str_replace($search, $replace, $body);
    }
    file_put_contents($path, $header . $body . "}\n");
}

$read = __DIR__ . '/../../Classes/MCP/Tool/Record/ReadTableTool.php';
$search = __DIR__ . '/../../Classes/MCP/Tool/SearchTool.php';

$fieldBody = sliceMethods($read, [[559, 639], [644, 758]]);
writeService(
    __DIR__ . '/../../Classes/Service/Record/RecordFieldReadConverter.php',
    <<<'PHP'
<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Service\FlexFormService;

final readonly class RecordFieldReadConverter
{
    public function __construct(
        private TableAccessService $tableAccessService,
    ) {}

PHP,
    $fieldBody,
    ["\$this->logException(\$e, 'parsing flexform XML');" => '// flexform parse failed'],
);

$queryBody = sliceMethods($read, [[338, 530], [772, 848], [849, 880], [881, 993], [994, 1001], [1002, 1022], [1419, 1438], [1512, 1544]]);
writeService(
    __DIR__ . '/../../Classes/Service/Record/RecordReadQueryService.php',
    <<<'PHP'
<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Database\Query\Restriction\WorkspaceDeletePlaceholderRestriction;
use Hn\McpServer\Event\AfterRecordReadEvent;
use Hn\McpServer\Event\BeforeRecordReadEvent;
use Hn\McpServer\Exception\DatabaseException;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\TableTcaResolver;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class RecordReadQueryService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TableAccessService $tableAccessService,
        private TableTcaResolver $tcaResolver,
        private RecordFieldReadConverter $fieldReadConverter,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public function getBackendUserForRelations(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No backend user available', 1748000001);
        }

        return $backendUser;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $this->getBackendUserForRelations();
    }

    private function getTableCtrlArray(string $table): array
    {
        return $this->tcaResolver->getCtrl($table);
    }

    private function getTableLabel(string $table): string
    {
        if (!$this->tableExists($table)) {
            return $table;
        }

        return TableAccessService::translateLabel($this->tableAccessService->getTableTitle($table));
    }

    private function tableExists(string $table): bool
    {
        return $this->tcaResolver->hasTable($table);
    }

    private function logException(\Throwable $e, string $context): void
    {
        unset($e, $context);
    }

PHP,
    $queryBody,
    [
        '$this->processRecord(' => '$this->fieldReadConverter->processRecord(',
        'GeneralUtility::makeInstance(EventDispatcherInterface::class)' => '$this->eventDispatcher',
    ],
);

$relationBody = sliceMethods($read, [[1027, 1058], [1063, 1089], [1094, 1111], [1116, 1155], [1160, 1173], [1178, 1249], [1261, 1345], [1365, 1414]]);
writeService(
    __DIR__ . '/../../Classes/Service/Record/RecordRelationReadService.php',
    <<<'PHP'
<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Event\AfterRecordReadEvent;
use Hn\McpServer\Event\BeforeRecordReadEvent;
use Hn\McpServer\Service\TableAccessService;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class RecordRelationReadService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TableAccessService $tableAccessService,
        private RecordFieldReadConverter $fieldReadConverter,
        private RecordReadQueryService $readQueryService,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

PHP,
    $relationBody,
    [
        '$this->processRecord(' => '$this->fieldReadConverter->processRecord(',
        '$this->convertFieldValue(' => '$this->fieldReadConverter->convertFieldValue(',
        '$this->applyDefaultSorting(' => '$this->readQueryService->applyDefaultSorting(',
        '$this->applyWorkspaceOverlay(' => '$this->readQueryService->applyWorkspaceOverlay(',
        '$this->getBackendUser()' => '$this->readQueryService->getBackendUserForRelations()',
        'GeneralUtility::makeInstance(EventDispatcherInterface::class)' => '$this->eventDispatcher',
    ],
);

$executorBody = sliceMethods($search, [[509, 537], [607, 632], [637, 743], [748, 754], [753, 810], [812, 847], [1040, 1043]]);
writeService(
    __DIR__ . '/../../Classes/Service/Record/RecordSearchExecutor.php',
    <<<'PHP'
<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Database\Query\Restriction\WorkspaceDeletePlaceholderRestriction;
use Hn\McpServer\Event\BeforeRecordReadEvent;
use Hn\McpServer\Exception\DatabaseException;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\TableAccessService;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class RecordSearchExecutor
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TableAccessService $tableAccessService,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public function ensureTableAccess(string $table, string $operation = 'read'): void
    {
        $this->tableAccessService->validateTableAccess($table, $operation);
    }

    private function logException(\Throwable $e, string $context): void
    {
        unset($e, $context);
    }

PHP,
    $executorBody,
    [
        '$this->getTablesToSearch(' => '$this->getTablesToSearch(',
        '$this->getSearchableFields(' => '$this->getSearchableFields(',
        '$this->ensureTableAccess(' => '$this->ensureTableAccess(',
        'GeneralUtility::makeInstance(EventDispatcherInterface::class)' => '$this->eventDispatcher',
        '$this->logException(' => '$this->logException(',
    ],
);

$attributionBody = sliceMethods($search, [[370, 441], [446, 504], [542, 594]]);
writeService(
    __DIR__ . '/../../Classes/Service/Record/InlineSearchAttributionService.php',
    <<<'PHP'
<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Event\BeforeRecordReadEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class InlineSearchAttributionService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private RecordSearchExecutor $searchExecutor,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    private function logException(\Throwable $e, string $context): void
    {
        // SearchTool logs via AbstractTool; keep attribution resilient without failing search.
        unset($e, $context);
    }

PHP,
    $attributionBody,
    [
        '$this->findParentRecordsForInlineRecord(' => '$this->findParentRecordsForInlineRecord(',
        '$this->getInlineRelatedHiddenTables(' => '$this->getInlineRelatedHiddenTables(',
        '$this->enhanceRecordsWithPageInfo(' => '$this->searchExecutor->enhanceRecordsWithPageInfo(',
        '$this->getSearchableFields(' => '$this->searchExecutor->getSearchableFields(',
        'GeneralUtility::makeInstance(EventDispatcherInterface::class)' => '$this->eventDispatcher',
        '$this->logException(' => '$this->logException(',
    ],
);

// RecordSearchExecutor needs public getSearchableFields wrapper
$executorFile = file_get_contents(__DIR__ . '/../../Classes/Service/Record/RecordSearchExecutor.php');
if (!str_contains($executorFile, 'public function getSearchableFields')) {
    $executorFile = str_replace(
        "    /**\n     * @throws ValidationException\n     */\n    public function ensureTableAccess",
        "    public function getSearchableFields(string \$table): array\n    {\n        return \$this->tableAccessService->getSearchFields(\$table);\n    }\n\n    /**\n     * @throws ValidationException\n     */\n    public function ensureTableAccess",
        $executorFile,
    );
    file_put_contents(__DIR__ . '/../../Classes/Service/Record/RecordSearchExecutor.php', $executorFile);
}

$formatterBody = sliceMethods($search, [[852, 898], [903, 927], [932, 997], [1002, 1035]]);
writeService(
    __DIR__ . '/../../Classes/Service/Record/RecordSearchResultFormatter.php',
    <<<'PHP'
<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Utility\RecordFormattingUtility;

final readonly class RecordSearchResultFormatter
{
    public function __construct(
        private LanguageService $languageService,
    ) {}

PHP,
    $formatterBody,
);

echo "Services generated\n";
