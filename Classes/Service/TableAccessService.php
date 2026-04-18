<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Event\ModifyAvailableFieldsEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\PageDoktypeRegistry;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Central service for determining table access permissions and capabilities
 * This service acts as the single source of truth for which tables can be accessed
 * through the MCP tools, considering workspace capability, user permissions, and other restrictions.
 *
 * @phpstan-type TablePermissions array{read: bool, write: bool, delete: bool}
 * @phpstan-type TableAccessInfo array{
 *   accessible: bool,
 *   reasons: list<string>,
 *   restrictions: list<string>,
 *   workspace_capable: bool,
 *   read_only: bool,
 *   permissions: TablePermissions
 * }
 * @phpstan-type SelectItemsResult array{values: list<string>, labels: array<string, string>}
 */
final class TableAccessService
{
    /** @var array<string, string> */
    private const DEPRECATED_LABEL_FALLBACKS = [
        'LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:header_formlabel' => 'Header',
        'LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:bodytext_formlabel' => 'Text',
        'LLL:EXT:frontend/Resources/Private/Language/locallang_tca.xlf:pages.palettes.editorial' => 'Editorial',
        'LLL:EXT:frontend/Resources/Private/Language/locallang_tca.xlf:pages.palettes.metatags' => 'Meta tags',
        'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.l18n_parent' => 'Translation parent',
        'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.fe_group' => 'Frontend user group',
        'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hide_at_login' => 'Hide at login',
        'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.any_login' => 'Any login',
        'LLL:EXT:frontend/Resources/Private/Language/locallang_tca.xlf:pages.doktype.I.8' => 'Mount point',
    ];

    private ?BackendUserAuthentication $backendUser = null;

    public function __construct(
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly PageDoktypeRegistry $pageDoktypeRegistry,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getAllTcaTables(): array
    {
        $globalTca = $GLOBALS['TCA'] ?? null;
        if (!is_array($globalTca)) {
            return [];
        }

        $tables = [];
        foreach ($globalTca as $table => $tableConfig) {
            if (is_string($table) && is_array($tableConfig)) {
                $tables[$table] = $tableConfig;
            }
        }

        return $tables;
    }

    /**
     * @return array<string, mixed>
     */
    private function getTableTca(string $table): array
    {
        return $this->getAllTcaTables()[$table] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getTableCtrl(string $table): array
    {
        $ctrl = $this->getTableTca($table)['ctrl'] ?? [];
        return is_array($ctrl) ? $ctrl : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getTableColumns(string $table): array
    {
        $columns = $this->getTableTca($table)['columns'] ?? [];
        if (!is_array($columns)) {
            return [];
        }

        $normalizedColumns = [];
        foreach ($columns as $fieldName => $fieldConfig) {
            if (is_string($fieldName) && is_array($fieldConfig)) {
                $normalizedColumns[$fieldName] = $fieldConfig;
            }
        }

        return $normalizedColumns;
    }

    /**
     * @return array<string, mixed>
     */
    private function getFieldTca(string $table, string $fieldName): array
    {
        return $this->getTableColumns($table)[$fieldName] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getTableTypeConfig(string $table, string $type): array
    {
        $types = $this->getTableTca($table)['types'] ?? [];
        if (!is_array($types)) {
            return [];
        }

        $typeConfig = $types[$type] ?? [];
        return is_array($typeConfig) ? $typeConfig : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfigurationSection(string $section): array
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($configuration)) {
            return [];
        }

        $sectionConfiguration = $configuration[$section] ?? [];
        return is_array($sectionConfiguration) ? $sectionConfiguration : [];
    }

    /**
     * Get the current backend user, ensuring it's properly initialized
     */
    private function getBackendUser(): BackendUserAuthentication
    {
        if ($this->backendUser === null) {
            if (!isset($GLOBALS['BE_USER']) || !$GLOBALS['BE_USER'] instanceof BackendUserAuthentication) {
                throw new \RuntimeException('Backend user context not properly initialized. Make sure authentication is set up.');
            }
            $this->backendUser = $GLOBALS['BE_USER'];
        }

        return $this->backendUser;
    }

    /**
     * Get all tables that are accessible to the current user
     *
     * @param bool $includeReadOnly Include read-only tables
     * @return array<string, TableAccessInfo> Array of table names with access information
     */
    public function getAccessibleTables(bool $includeReadOnly = false): array
    {
        $accessibleTables = [];

        foreach (array_keys($this->getAllTcaTables()) as $table) {
            $accessInfo = $this->getTableAccessInfo($table);

            if ($accessInfo['accessible']) {
                // Skip read-only tables if not requested
                if (!$includeReadOnly && $accessInfo['read_only']) {
                    continue;
                }

                $accessibleTables[$table] = $accessInfo;
            }
        }

        return $accessibleTables;
    }

    /**
     * Get all tables that are readable (less restrictive - no workspace capability required)
     *
     * @return array<string, TableAccessInfo> Array of table names with access information
     */
    public function getReadableTables(): array
    {
        $readableTables = [];

        foreach (array_keys($this->getAllTcaTables()) as $table) {
            $accessInfo = $this->getTableAccessInfo($table, false); // Don't require workspace capability

            if ($accessInfo['accessible']) {
                $readableTables[$table] = $accessInfo;
            }
        }

        return $readableTables;
    }

    /**
     * Check if a table can be accessed by the current user
     *
     * @param string $table Table name
     * @return bool
     */
    public function canAccessTable(string $table): bool
    {
        $accessInfo = $this->getTableAccessInfo($table);
        return $accessInfo['accessible'];
    }

    /**
     * Check if a table can be accessed for read operations (less restrictive)
     *
     * @param string $table Table name
     * @return bool
     */
    public function canReadTable(string $table): bool
    {
        $accessInfo = $this->getTableAccessInfo($table, false); // Don't require workspace capability
        return $accessInfo['accessible'];
    }

    /**
     * Get detailed access information for a table
     *
     * @param string $table Table name
     * @param bool $requireWorkspaceCapability Whether workspace capability is required (default: true)
     * @return TableAccessInfo Detailed access information
     */
    public function getTableAccessInfo(string $table, bool $requireWorkspaceCapability = true): array
    {
        // Start with default values
        $info = [
            'accessible' => false,
            'reasons' => [],
            'restrictions' => [],
            'workspace_capable' => false,
            'read_only' => false,
            'permissions' => [
                'read' => false,
                'write' => false,
                'delete' => false,
            ],
        ];

        // Check if table exists in TCA
        if ($this->getTableTca($table) === []) {
            $info['reasons'][] = 'Table does not exist in TCA';
            return $info;
        }

        // Check if table is a truly restricted system table
        if ($this->isRestrictedSystemTable($table)) {
            $info['reasons'][] = 'Table is restricted for security or system integrity reasons';
            return $info;
        }

        // Check workspace capability (required for write operations)
        $workspaceCapability = $this->getTableCtrl($table)['versioningWS'] ?? false;
        $info['workspace_capable'] = $workspaceCapability === true || $workspaceCapability === 1 || $workspaceCapability === '1';
        if ($requireWorkspaceCapability && !$info['workspace_capable']) {
            $info['reasons'][] = 'Table is not workspace-capable (required for write operations)';
            return $info;
        }

        // Check user permissions
        $permissions = $this->checkUserPermissions($table);
        $info['permissions'] = $permissions;

        if (!$permissions['read']) {
            $info['reasons'][] = 'User does not have read permission for this table';
            return $info;
        }

        // Check if table is read-only
        $info['read_only'] = $this->isTableReadOnly($table);
        if ($info['read_only']) {
            $info['restrictions'][] = 'Table is read-only';
            $info['permissions']['write'] = false;
            $info['permissions']['delete'] = false;
        }

        // Check field restrictions
        $fieldRestrictions = $this->getFieldRestrictions($table);
        if (!empty($fieldRestrictions)) {
            $info['restrictions'] = array_merge($info['restrictions'], $fieldRestrictions);
        }

        // If we made it here, the table is accessible
        $info['accessible'] = true;

        return $info;
    }

    /**
     * Validate that a table can be accessed, throwing an exception if not
     *
     * @param string $table Table name
     * @param string $operation Optional operation being attempted (read, write, delete)
     * @throws \InvalidArgumentException If table cannot be accessed
     */
    public function validateTableAccess(string $table, string $operation = 'read'): void
    {
        $accessInfo = $this->getTableAccessInfo($table);

        if (!$accessInfo['accessible']) {
            $reasons = implode(', ', $accessInfo['reasons']);
            throw new \InvalidArgumentException(
                "Cannot access table '{$table}': {$reasons}",
            );
        }

        // Check specific operation permission
        if ($operation !== 'read' && !$accessInfo['permissions'][$operation]) {
            throw new \InvalidArgumentException(
                "Operation '{$operation}' not permitted on table '{$table}'",
            );
        }
    }

    /**
     * Validate that a table can be read even when it is not workspace-capable.
     *
     * This is used for read-only access to root-level or system tables that are
     * intentionally exposed but must never be edited through workspace tools.
     *
     * @throws \InvalidArgumentException If table cannot be read
     */
    public function validateReadTableAccess(string $table): void
    {
        $accessInfo = $this->getTableAccessInfo($table, false);

        if (!$accessInfo['accessible']) {
            $reasons = implode(', ', $accessInfo['reasons']);
            throw new \InvalidArgumentException(
                "Cannot access table '{$table}': {$reasons}",
            );
        }

        if (!$accessInfo['permissions']['read']) {
            throw new \InvalidArgumentException(
                "Operation 'read' not permitted on table '{$table}'",
            );
        }
    }

    /**
     * Get the schema for an accessible table
     *
     * @param string $table Table name
     * @param string $type Record type (optional)
     * @return array{table: string, type: string, fields: array<string, array<string, mixed>>, ctrl: array<string, mixed>} Schema information
     * @throws \InvalidArgumentException If table is not accessible
     */
    public function getTableSchema(string $table, string $type = ''): array
    {
        $this->validateTableAccess($table);

        $schema = [
            'table' => $table,
            'type' => $type,
            'fields' => $this->getAvailableFields($table, $type),
            'ctrl' => $this->getTableControlInfo($table),
        ];

        return $schema;
    }

    /**
     * Get available fields for a table and type
     *
     * @param string $table Table name
     * @param string $type Record type (optional)
     * @return array<string, array<string, mixed>> Field configuration
     */
    public function getAvailableFields(string $table, string $type = ''): array
    {
        $this->validateTableAccess($table);

        // Check if schema exists for this table
        if (!$this->tcaSchemaFactory->has($table)) {
            return [];
        }

        $schema = $this->tcaSchemaFactory->get($table);
        $fields = [];
        $subtypeFieldName = null;

        // If a specific type is provided and the schema supports sub-schemas
        if (!empty($type) && $schema->hasSubSchema($type)) {
            $subSchema = $schema->getSubSchema($type);

            // TYPO3 v14 no longer exposes subtype handling via TcaSchema.
            // Prefer CType-driven schema handling and only fall back to raw TCA when
            // older subtype-based configurations are still present.
            $typeConfig = $this->getTableTypeConfig($table, $type);
            $subtypeFieldName = isset($typeConfig['subtype_value_field']) && is_string($typeConfig['subtype_value_field'])
                ? $typeConfig['subtype_value_field']
                : null;

            // Get fields from the sub-schema
            foreach ($subSchema->getFields() as $field) {
                $fieldName = $field->getName();
                $fields[$fieldName] = $field->getConfiguration();
            }
        } else {
            // No specific type or type doesn't exist - use main schema
            // Try to fall back to a reasonable default type
            if (empty($type) && $schema->supportsSubSchema()) {
                // Get the default type from TCA configuration
                $typeFieldConfig = $this->getFieldTca($table, $schema->getSubSchemaTypeInformation()->getFieldName());
                $typeFieldOptions = isset($typeFieldConfig['config']) && is_array($typeFieldConfig['config']) ? $typeFieldConfig['config'] : [];
                $defaultType = isset($typeFieldOptions['default']) && is_string($typeFieldOptions['default']) ? $typeFieldOptions['default'] : '';

                if (!empty($defaultType) && $schema->hasSubSchema($defaultType)) {
                    $subSchema = $schema->getSubSchema($defaultType);
                    foreach ($subSchema->getFields() as $field) {
                        $fieldName = $field->getName();
                        $fields[$fieldName] = $field->getConfiguration();
                    }
                } else {
                    // No reasonable default found, use all main schema fields
                    foreach ($schema->getFields() as $field) {
                        $fieldName = $field->getName();
                        $fields[$fieldName] = $field->getConfiguration();
                    }
                }
            } else {
                // Use main schema fields
                foreach ($schema->getFields() as $field) {
                    $fieldName = $field->getName();
                    $fields[$fieldName] = $field->getConfiguration();
                }
            }
        }

        // Handle subtypes pattern: If a type uses subtypes and has FlexForm configurations,
        // ensure FlexForm fields are included even if not explicitly in showitem
        if (is_string($subtypeFieldName) && $subtypeFieldName !== '') {
            $this->addSubtypeFields($table, $type, $subtypeFieldName, $fields);
        }

        // Apply field-level access restrictions
        foreach ($fields as $fieldName => $fieldConfig) {
            if (!$this->canAccessField($table, $fieldName, $type)) {
                unset($fields[$fieldName]);
            }
        }

        /** @var ModifyAvailableFieldsEvent $event */
        $event = $this->eventDispatcher->dispatch(new ModifyAvailableFieldsEvent($table, $type, $fields));
        return $event->getFields();
    }

    /**
     * Add fields that should be available based on subtype configuration.
     *
     * TYPO3 v14 primarily uses CType-driven plugin types. This helper keeps the
     * legacy subtype/list_type path for TYPO3 v14-compatible setups.
     *
     * @param string $table Table name
     * @param string $type Record type
     * @param string $subtypeField The subtype field name (for example `list_type`)
     * @param array<string, array<string, mixed>> &$fields Reference to fields array to modify
     */
    private function addSubtypeFields(string $table, string $type, string $subtypeField, array &$fields): void
    {
        $columns = $this->getTableColumns($table);
        $typeConfig = $this->getTableTypeConfig($table, $type);

        // Check if there are FlexForm fields configured
        $flexFormFields = [];
        foreach ($columns as $fieldName => $fieldConfig) {
            $fieldOptions = isset($fieldConfig['config']) && is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
            if (($fieldOptions['type'] ?? '') === 'flex') {
                $flexFormFields[] = $fieldName;
            }
        }

        // For each FlexForm field, check if there are DataStructures configured that use the subtype pattern
        foreach ($flexFormFields as $flexFormField) {
            $flexFormConfig = $columns[$flexFormField] ?? [];
            $flexFormOptions = isset($flexFormConfig['config']) && is_array($flexFormConfig['config']) ? $flexFormConfig['config'] : [];
            $dsConfig = $flexFormOptions['ds'] ?? [];

            if (!empty($dsConfig)) {
                // Check if any DS key still uses the legacy subtype pattern.
                $hasSubtypeDS = false;
                if (is_array($dsConfig)) {
                    foreach (array_keys($dsConfig) as $dsKey) {
                        // Common patterns: "*,plugin_key" or "type,plugin_key" or just "plugin_key"
                        $subtypeFieldConfig = $columns[$subtypeField] ?? [];
                        $subtypeFieldOptions = isset($subtypeFieldConfig['config']) && is_array($subtypeFieldConfig['config']) ? $subtypeFieldConfig['config'] : [];
                        if (str_contains((string)$dsKey, ',') || isset($subtypeFieldOptions['items'])) {
                            $hasSubtypeDS = true;
                            break;
                        }
                    }
                } elseif (is_string($dsConfig)) {
                    $hasSubtypeDS = true;
                }

                // If there are subtype-based DataStructures, include the FlexForm field
                if ($hasSubtypeDS && !isset($fields[$flexFormField])) {
                    // Add the FlexForm field configuration if it's not already present
                    $fields[$flexFormField] = $columns[$flexFormField] ?? [];
                }
            }
        }

        // Handle traditional subtypes_addlist (deprecated but still supported)
        $subtypesAddlist = $typeConfig['subtypes_addlist'] ?? [];
        if (!empty($subtypesAddlist) && is_array($subtypesAddlist)) {
            // This would require knowing the actual subtype value, which we don't have here
            // For general schema purposes, we could include all possible fields from all subtypes
            foreach ($subtypesAddlist as $subtypeValue => $addFields) {
                if (!empty($addFields)) {
                    $addFieldsList = GeneralUtility::trimExplode(',', is_scalar($addFields) ? (string)$addFields : '', true);
                    foreach ($addFieldsList as $fieldName) {
                        if (isset($columns[$fieldName]) && !isset($fields[$fieldName])) {
                            $fields[$fieldName] = $columns[$fieldName];
                        }
                    }
                }
            }
        }
    }

    /**
     * Get field names for a table and type (without full configuration)
     *
     * @param string $table Table name
     * @param string $type Record type (optional)
     * @return list<string> List of field names
     */
    public function getFieldNamesForType(string $table, string $type = ''): array
    {
        $fields = $this->getAvailableFields($table, $type);
        return array_keys($fields);
    }

    /**
     * Get restrictions for a table
     *
     * @param string $table Table name
     * @return list<string> List of restrictions
     */
    public function getTableRestrictions(string $table): array
    {
        $restrictions = [];

        // Check if entire table is read-only
        if ($this->isTableReadOnly($table)) {
            $restrictions[] = 'Table is read-only';
        }

        // Get field-level restrictions
        $fieldRestrictions = $this->getFieldRestrictions($table);
        $restrictions = array_merge($restrictions, $fieldRestrictions);

        return $restrictions;
    }

    /**
     * Check if a table is truly restricted and should not be accessible via MCP
     */
    private function isRestrictedSystemTable(string $table): bool
    {
        // Admin-only tables (only restrict if user is not admin)
        $ctrl = $this->getTableCtrl($table);
        if (!empty($ctrl['adminOnly']) && !$this->getBackendUser()->isAdmin()) {
            return true;
        }

        // Root-level tables that are dangerous to modify
        if (!empty($ctrl['rootLevel'])) {
            // Allow some safe root-level tables
            $allowedRootTables = [
                'sys_file_storage', // File storage configuration
                'sys_domain', // Domain configuration
                'sys_category', // Category system - safe for read operations
                'sys_redirect', // Redirect records are intentionally exposed via ManageRedirects
            ];

            if (!in_array($table, $allowedRootTables)) {
                return true;
            }
        }

        // Specific dangerous system tables that should never be accessed via MCP
        $restrictedTables = [
            'sys_log', // System log - read-only, managed by system
            'sys_history', // Change history - read-only, managed by system
            'sys_refindex', // Reference index - managed by system
            'sys_registry', // System registry - internal configuration
            'sys_lockedrecords', // Lock management - managed by system
            'be_sessions', // Backend sessions - security risk
            'fe_sessions', // Frontend sessions - security risk
            'cache_treelist', // Cache tables - managed by system
            'cache_pages', // Cache tables - managed by system
            'cache_pagesection', // Cache tables - managed by system
            'cache_hash', // Cache tables - managed by system
            'sys_be_shortcuts', // User shortcuts - user-specific
            'sys_news', // System news - admin-only
            // sys_file_reference intentionally NOT restricted -- workspace-versioned and needed for file attachments
        ];

        if (in_array($table, $restrictedTables)) {
            return true;
        }

        // Check for system group tables with workspace support
        // This is likely a misconfiguration as system configuration tables shouldn't support workspaces
        $groupName = is_string($ctrl['groupName'] ?? null) ? $ctrl['groupName'] : '';
        $isWorkspaceCapable = !empty($ctrl['versioningWS']);
        if ($groupName === 'system' && $isWorkspaceCapable) {
            // System tables with workspace support are suspicious
            // Examples: backend_layout, sys_template, sys_file_storage, sys_workspace
            // These are configuration tables that shouldn't be edited in workspaces
            return true;
        }

        return false;
    }

    /**
     * Check if a table is read-only
     */
    private function isTableReadOnly(string $table): bool
    {
        // Check TCA configuration
        $ctrl = $this->getTableCtrl($table);
        if (!empty($ctrl['readOnly'])) {
            return true;
        }

        // Specific read-only tables that can be read but shouldn't be modified via MCP
        $readOnlyTables = [
            'sys_file', // Files are managed through file system, not direct DB edits
            'sys_file_processedfile', // Processed files are generated automatically
            'sys_file_storage', // Storage configuration - sensitive
            'sys_file_metadata', // File metadata - usually auto-generated
        ];

        if (in_array($table, $readOnlyTables)) {
            return true;
        }

        // Tables without essential fields are typically read-only
        // If table has no label field and no type field, it's likely a pure relation table
        // But relation tables like sys_file_reference should still be writable
        $hasLabel = !empty($ctrl['label']);
        $hasType = !empty($ctrl['type']);
        $isRelationTable = str_contains($table, '_mm')
                                  || str_contains($table, 'sys_file_reference')
                                  || str_contains($table, 'sys_category_record_mm');

        // If it's a relation table, it should be writable regardless of label field
        if ($isRelationTable) {
            return false;
        }

        // Non-relation tables without label field are typically read-only
        if (!$hasLabel && !$hasType) {
            return true;
        }

        return false;
    }

    /**
     * Check user permissions for a table
     *
     * @return TablePermissions
     */
    private function checkUserPermissions(string $table): array
    {
        $permissions = [
            'read' => false,
            'write' => false,
            'delete' => false,
        ];

        $backendUser = $this->getBackendUser();

        // Admin users have all permissions
        if ($backendUser->isAdmin()) {
            return [
                'read' => true,
                'write' => true,
                'delete' => true,
            ];
        }

        // Check if user has access to the table
        if ($backendUser->check('tables_select', $table)) {
            $permissions['read'] = true;
        }

        if ($backendUser->check('tables_modify', $table)) {
            $permissions['write'] = true;
            $permissions['delete'] = true;
        }

        // For pages table, check page permissions
        if ($table === 'pages') {
            // This is simplified - in real scenarios, page permissions are more complex
            $permissions['read'] = true;
            $permissions['write'] = $backendUser->check('tables_modify', 'pages');
            $permissions['delete'] = $permissions['write'];
        }

        return $permissions;
    }

    /**
     * Get field restrictions for a table
     *
     * @return list<string>
     */
    private function getFieldRestrictions(string $table): array
    {
        $restrictions = [];
        $columns = $this->getTableColumns($table);

        if ($columns === []) {
            return $restrictions;
        }

        foreach ($columns as $fieldName => $fieldConfig) {
            // Check exclude fields
            if (!empty($fieldConfig['exclude']) && !$this->getBackendUser()->check('non_exclude_fields', $table . ':' . $fieldName)) {
                $restrictions[] = "Field '{$fieldName}' is excluded for current user";
            }

            // Check displayCond
            if (!empty($fieldConfig['displayCond'])) {
                // This is simplified - displayCond evaluation is complex
                $restrictions[] = "Field '{$fieldName}' has display conditions";
            }
        }

        return $restrictions;
    }

    /**
     * Check if a specific field can be accessed
     *
     * @param string $table Table name
     * @param string $fieldName Field name
     * @param string $type Record type (optional, for type-specific TSconfig)
     * @return bool
     */
    public function canAccessField(string $table, string $fieldName, string $type = ''): bool
    {
        $fieldConfig = $this->getFieldConfig($table, $fieldName);
        if ($fieldConfig === null) {
            return false;
        }

        $fieldOptions = isset($fieldConfig['config']) && is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
        $fieldType = is_string($fieldOptions['type'] ?? null) ? $fieldOptions['type'] : '';

        // Block inline relations where foreign table isn't writable
        // This automatically filters out relations to:
        // - Tables without workspace support
        // - Read-only tables (sys_file, sys_file_metadata, etc.)
        // - Tables with no user access
        if ($fieldType === 'inline') {
            $foreignTable = is_string($fieldOptions['foreign_table'] ?? null) ? $fieldOptions['foreign_table'] : '';
            if ($foreignTable && !$this->canAccessTable($foreignTable)) {
                return false;
            }
        }

        // Check exclude fields
        if (!empty($fieldConfig['exclude'])) {
            $backendUser = $this->getBackendUser();
            if (!$backendUser->isAdmin() && !$backendUser->check('non_exclude_fields', $table . ':' . $fieldName)) {
                return false;
            }
        }

        // Check TSconfig field visibility (applies to all users including admins)
        $TSconfig = $this->getRelevantPageTsconfig();

        // Check if field is globally disabled via TCEFORM.[table].[field].disabled
        $fieldDisabled = $this->getTsConfigFieldSetting($TSconfig, $table, $fieldName, 'disabled');
        if ($fieldDisabled === '1' || $fieldDisabled === 1 || $fieldDisabled === true) {
            return false;
        }

        if ($this->isFieldDisabledByDefaultPageTsconfig($table, $fieldName)) {
            return false;
        }

        // Check if field is disabled for specific type via TCEFORM.[table].[field].types.[type].disabled
        if (!empty($type)) {
            $typeDisabled = $this->getTsConfigFieldSetting($TSconfig, $table, $fieldName, 'disabled', $type);
            if ($typeDisabled === '1' || $typeDisabled === 1 || $typeDisabled === true) {
                return false;
            }

            if ($this->isFieldDisabledByDefaultPageTsconfig($table, $fieldName, $type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function getRelevantPageTsconfig(): array
    {
        $tsConfig = BackendUtility::getPagesTSconfig(0);
        if (($tsConfig['TCEFORM.'] ?? null) !== null || ($tsConfig['TCEMAIN.'] ?? null) !== null) {
            return $tsConfig;
        }

        // In functional tests and root-level schema lookups, page uid 0 often has no rootline.
        // Fall back to the site root page so default Page TSconfig is still respected.
        $fallbackTsConfig = BackendUtility::getPagesTSconfig(1);
        if (($fallbackTsConfig['TCEFORM.'] ?? null) !== null || ($fallbackTsConfig['TCEMAIN.'] ?? null) !== null) {
            return $fallbackTsConfig;
        }

        return $tsConfig;
    }

    private function isFieldDisabledByDefaultPageTsconfig(string $table, string $fieldName, string $type = ''): bool
    {
        $beConfiguration = $this->getConfigurationSection('BE');
        $defaultPageTsconfig = is_string($beConfiguration['defaultPageTSconfig'] ?? null) ? $beConfiguration['defaultPageTSconfig'] : '';
        if ($defaultPageTsconfig === '') {
            return false;
        }

        $patterns = [
            '/^\s*TCEFORM\.' . preg_quote($table, '/') . '\.' . preg_quote($fieldName, '/') . '\.disabled\s*=\s*1\s*$/m',
        ];

        if ($type !== '') {
            $patterns[] = '/^\s*TCEFORM\.' . preg_quote($table, '/') . '\.' . preg_quote($fieldName, '/') . '\.types\.' . preg_quote($type, '/') . '\.disabled\s*=\s*1\s*$/m';
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $defaultPageTsconfig) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $tsConfig
     */
    private function getTsConfigFieldSetting(array $tsConfig, string $table, string $fieldName, string $setting, string $type = ''): mixed
    {
        $tceForm = $tsConfig['TCEFORM.'] ?? [];
        if (!is_array($tceForm)) {
            return null;
        }

        $tableConfig = $tceForm[$table . '.'] ?? [];
        if (!is_array($tableConfig)) {
            return null;
        }

        $fieldConfig = $tableConfig[$fieldName . '.'] ?? [];
        if (!is_array($fieldConfig)) {
            return null;
        }

        if ($type !== '') {
            $typesConfig = $fieldConfig['types.'] ?? [];
            if (!is_array($typesConfig)) {
                return null;
            }

            $typeConfig = $typesConfig[$type . '.'] ?? [];
            if (!is_array($typeConfig)) {
                return null;
            }

            return $typeConfig[$setting] ?? null;
        }

        return $fieldConfig[$setting] ?? null;
    }

    /**
     * Get control information for a table
     *
     * @return array<string, mixed>
     */
    private function getTableControlInfo(string $table): array
    {
        $ctrl = $this->getTableCtrl($table);

        // Extract only relevant control fields
        $relevantFields = [
            'title', 'label', 'label_alt', 'label_alt_force',
            'descriptionColumn', 'type', 'languageField',
            'transOrigPointerField', 'delete', 'enablecolumns',
            'sortby', 'default_sortby', 'tstamp', 'crdate',
            'versioningWS', 'origUid', 'searchFields',
        ];

        $controlInfo = [];
        foreach ($relevantFields as $field) {
            if (isset($ctrl[$field])) {
                $controlInfo[$field] = $ctrl[$field];
            }
        }

        return $controlInfo;
    }

    // =============================================================================
    // UTILITY METHODS FOR COMMON TCA OPERATIONS
    // =============================================================================

    /**
     * Get the table title (label) for a table
     */
    public function getTableTitle(string $table): string
    {
        $title = $this->getTableCtrl($table)['title'] ?? null;
        return is_string($title) && $title !== '' ? $title : $table;
    }

    /**
     * Get the type field name for a table
     */
    public function getTypeFieldName(string $table): ?string
    {
        $typeField = $this->getTableCtrl($table)['type'] ?? null;
        if (!is_string($typeField) || $typeField === '') {
            return null;
        }

        // Foreign type notation (e.g. "uid_local:type") derives the record type
        // from a related record's field. This is not a local column and must not
        // be used in SQL queries or field lookups.
        if (str_contains($typeField, ':')) {
            return null;
        }

        return $typeField;
    }

    /**
     * Get the label field name for a table
     */
    public function getLabelFieldName(string $table): ?string
    {
        $labelField = $this->getTableCtrl($table)['label'] ?? null;
        return is_string($labelField) && $labelField !== '' ? $labelField : null;
    }

    /**
     * Get the sorting field name for a table
     */
    public function getSortingFieldName(string $table): ?string
    {
        $sortby = $this->getTableCtrl($table)['sortby'] ?? null;
        return is_string($sortby) && $sortby !== '' ? $sortby : null;
    }

    /**
     * Get the default sorting configuration for a table
     */
    public function getDefaultSorting(string $table): ?string
    {
        $defaultSorting = $this->getTableCtrl($table)['default_sortby'] ?? null;
        return is_string($defaultSorting) && $defaultSorting !== '' ? $defaultSorting : null;
    }

    /**
     * Get the timestamp field name for a table
     */
    public function getTimestampFieldName(string $table): ?string
    {
        $timestampField = $this->getTableCtrl($table)['tstamp'] ?? null;
        return is_string($timestampField) && $timestampField !== '' ? $timestampField : null;
    }

    /**
     * Get the creation date field name for a table
     */
    public function getCreationDateFieldName(string $table): ?string
    {
        $creationDateField = $this->getTableCtrl($table)['crdate'] ?? null;
        return is_string($creationDateField) && $creationDateField !== '' ? $creationDateField : null;
    }

    /**
     * Get the language field name for a table
     */
    public function getLanguageFieldName(string $table): ?string
    {
        $languageField = $this->getTableCtrl($table)['languageField'] ?? null;
        return is_string($languageField) && $languageField !== '' ? $languageField : null;
    }

    /**
     * Get the hidden field name for a table
     */
    public function getHiddenFieldName(string $table): ?string
    {
        $enableColumns = $this->getTableCtrl($table)['enablecolumns'] ?? [];
        if (!is_array($enableColumns)) {
            return null;
        }
        $hiddenField = $enableColumns['disabled'] ?? null;
        return is_string($hiddenField) && $hiddenField !== '' ? $hiddenField : null;
    }

    /**
     * Get the translation parent field name for a table
     */
    public function getTranslationParentFieldName(string $table): ?string
    {
        $parentField = $this->getTableCtrl($table)['transOrigPointerField'] ?? null;
        return is_string($parentField) && $parentField !== '' ? $parentField : null;
    }

    /**
     * Get the translation source field name for a table
     */
    public function getTranslationSourceFieldName(string $table): ?string
    {
        $sourceField = $this->getTableCtrl($table)['translationSource'] ?? null;
        return is_string($sourceField) && $sourceField !== '' ? $sourceField : null;
    }

    /**
     * Get fields that are excluded in translations (l10n_mode = 'exclude')
     */
    /**
     * @return list<string>
     */
    public function getExcludedFieldsInTranslation(string $table): array
    {
        $excludedFields = [];
        $columns = $this->getTableColumns($table);

        foreach ($columns as $fieldName => $fieldConfig) {
            $l10nMode = $fieldConfig['l10n_mode'] ?? '';
            if ($l10nMode === 'exclude') {
                $excludedFields[] = $fieldName;
            }
        }

        return $excludedFields;
    }

    /**
     * Check if a field allows language synchronization
     */
    public function isFieldLanguageSynchronizable(string $table, string $field): bool
    {
        $fieldConfig = $this->getFieldConfig($table, $field);
        if (!$fieldConfig) {
            return false;
        }

        $config = isset($fieldConfig['config']) && is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
        $behaviour = isset($config['behaviour']) && is_array($config['behaviour']) ? $config['behaviour'] : [];
        return (bool)($behaviour['allowLanguageSynchronization'] ?? false);
    }

    /**
     * Fields that carry translatable content for the given table.
     *
     * A translate call must populate at least one of these to produce a meaningful
     * translation — DataHandler's localize command otherwise copies the source verbatim
     * and only prefixes the label field with "[Translate to X:]".
     *
     * Includes text-like fields (input/text/link/email) plus FlexForm and inline
     * relations, since those commonly carry translated strings (e.g. plugin settings
     * in pi_flexform, or sys_file_reference metadata on image/assets). System fields
     * (sys_language_uid, l10n_parent, …) and fields with l10n_mode=exclude are filtered
     * out. Fields the current backend user cannot access are also dropped.
     *
     * @return list<string>
     */
    public function getTranslatableContentFields(string $table): array
    {
        $excluded = $this->getExcludedFieldsInTranslation($table);
        $systemFields = array_filter([
            $this->getLanguageFieldName($table),
            $this->getTranslationParentFieldName($table),
            $this->getTranslationSourceFieldName($table),
            'l10n_state',
            'l10n_diffsource',
            't3_origuid',
        ]);

        $translatable = [];
        foreach ($this->getTableColumns($table) as $fieldName => $fieldConfig) {
            if (in_array($fieldName, $excluded, true) || in_array($fieldName, $systemFields, true)) {
                continue;
            }

            $config = isset($fieldConfig['config']) && is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
            $fieldType = is_string($config['type'] ?? null) ? $config['type'] : '';
            if (!in_array($fieldType, ['input', 'text', 'link', 'email', 'flex', 'inline'], true)) {
                continue;
            }

            if (!$this->canAccessField($table, $fieldName)) {
                continue;
            }

            $translatable[] = $fieldName;
        }

        return $translatable;
    }

    /**
     * Get the search fields for a table
     *
     * @return list<string>
     */
    public function getSearchFields(string $table): array
    {
        $searchFields = $this->getTableCtrl($table)['searchFields'] ?? '';

        if (is_string($searchFields) && $searchFields !== '') {
            return GeneralUtility::trimExplode(',', $searchFields, true);
        }

        $fallbackFields = [];
        foreach ($this->getTableColumns($table) as $fieldName => $fieldConfig) {
            $config = isset($fieldConfig['config']) && is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
            $fieldType = is_string($config['type'] ?? null) ? $config['type'] : '';

            if (!in_array($fieldType, ['input', 'text', 'slug', 'email', 'link'], true)) {
                continue;
            }

            if (!$this->canAccessField($table, $fieldName)) {
                continue;
            }

            $fallbackFields[] = $fieldName;
        }

        return array_values(array_unique($fallbackFields));
    }

    /**
     * Get essential fields for a table (fields that should always be included)
     *
     * @return list<string>
     */
    public function getEssentialFields(string $table): array
    {
        $essentialFields = ['uid', 'pid'];

        // Add type field if it exists
        if ($typeField = $this->getTypeFieldName($table)) {
            $essentialFields[] = $typeField;
        }

        // Add label field if it exists
        if ($labelField = $this->getLabelFieldName($table)) {
            $essentialFields[] = $labelField;
        }

        // Add language field if it exists
        if ($languageField = $this->getLanguageFieldName($table)) {
            $essentialFields[] = $languageField;
        }

        // Add timestamp fields if they exist
        if ($tstampField = $this->getTimestampFieldName($table)) {
            $essentialFields[] = $tstampField;
        }

        if ($crdateField = $this->getCreationDateFieldName($table)) {
            $essentialFields[] = $crdateField;
        }

        // Add hidden field if it exists
        if ($hiddenField = $this->getHiddenFieldName($table)) {
            $essentialFields[] = $hiddenField;
        }

        // Add sorting field if it exists
        if ($sortingField = $this->getSortingFieldName($table)) {
            $essentialFields[] = $sortingField;
        }

        return array_values(array_unique($essentialFields));
    }

    /**
     * Get available types for a table
     *
     * @return array<array-key, string>
     */
    public function getAvailableTypes(string $table): array
    {
        $typeField = $this->getTypeFieldName($table);
        if (!$typeField) {
            return ['1' => 'Default'];
        }

        // Defense-in-depth: foreign type notation should already be caught by
        // getTypeFieldName() returning null, but guard here in case that changes.
        if (str_contains($typeField, ':')) {
            return ['0' => 'Default'];
        }

        $typeFieldConfig = $this->getFieldTca($table, $typeField);
        $typeConfig = isset($typeFieldConfig['config']) && is_array($typeFieldConfig['config']) ? $typeFieldConfig['config'] : [];
        $items = (isset($typeConfig['items']) && is_array($typeConfig['items'])) ? $typeConfig['items'] : [];

        // Use the shared parseSelectItems method
        $parsed = $this->parseSelectItems($items);

        // Convert to the expected format (value => label)
        $types = [];
        foreach ($parsed['values'] as $value) {
            $types[(string)$value] = $parsed['labels'][$value] ?? $value;
        }

        if ($table === 'pages' && $typeField === 'doktype') {
            foreach (array_keys($this->pageDoktypeRegistry->exportConfiguration()) as $doktype) {
                $normalizedDoktype = $this->normalizeDoktypeValue($doktype);
                if ($normalizedDoktype === null || isset($types[$normalizedDoktype])) {
                    continue;
                }

                $types[$normalizedDoktype] = 'Registered custom page type';
            }
        }

        return $types;
    }

    /**
     * Get the field configuration for a specific field
     */
    /**
     * @return array<string, mixed>|null
     */
    public function getFieldConfig(string $table, string $fieldName): ?array
    {
        $fieldConfig = $this->getFieldTca($table, $fieldName);
        return $fieldConfig === [] ? null : $fieldConfig;
    }

    /**
     * Check if a field is a date field
     */
    public function isDateField(string $table, string $fieldName): bool
    {
        // Common date fields in TYPO3
        $commonDateFields = ['tstamp', 'crdate', 'starttime', 'endtime', 'lastlogin', 'date'];

        if (in_array($fieldName, $commonDateFields)) {
            return true;
        }

        // Check TCA eval for date/datetime/time
        $fieldConfig = $this->getFieldConfig($table, $fieldName);
        if (!$fieldConfig) {
            return false;
        }

        $config = isset($fieldConfig['config']) && is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];

        // Check eval rules
        $eval = is_string($config['eval'] ?? null) ? $config['eval'] : '';
        if ($eval !== '') {
            $evalRules = GeneralUtility::trimExplode(',', $eval, true);
            if (in_array('date', $evalRules) || in_array('datetime', $evalRules) || in_array('time', $evalRules)) {
                return true;
            }
        }

        // Check renderType for inputDateTime
        if (($config['renderType'] ?? null) === 'inputDateTime') {
            return true;
        }

        return false;
    }

    /**
     * Check if a field is a FlexForm field
     */
    public function isFlexFormField(string $table, string $fieldName): bool
    {
        $fieldConfig = $this->getFieldConfig($table, $fieldName);
        if (!$fieldConfig) {
            return false;
        }

        $config = isset($fieldConfig['config']) && is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
        if (!empty($config['type']) && $config['type'] === 'flex') {
            return true;
        }

        // Check common FlexForm field names
        $knownFlexFormFields = [
            'tt_content' => ['pi_flexform'],
            'pages' => ['tx_templavoila_flex'],
        ];

        if (isset($knownFlexFormFields[$table]) && in_array($fieldName, $knownFlexFormFields[$table])) {
            return true;
        }

        // Check field name pattern
        if (str_contains($fieldName, 'flexform')) {
            return true;
        }

        return false;
    }

    /**
     * Parse default sorting configuration into field/direction pairs
     *
     * @return list<array{field: string, direction: string}>
     */
    public function parseDefaultSorting(string $table): array
    {
        $defaultSorting = $this->getDefaultSorting($table);
        if (!$defaultSorting) {
            return [];
        }

        $sortParts = GeneralUtility::trimExplode(',', $defaultSorting, true);
        $sorting = [];

        foreach ($sortParts as $sortPart) {
            $sortPart = trim($sortPart);

            // Extract field and direction
            if (preg_match('/^(.*?)\s+(ASC|DESC)$/i', $sortPart, $matches)) {
                $field = trim($matches[1]);
                $direction = strtoupper($matches[2]);
                $sorting[] = ['field' => $field, 'direction' => $direction];
            } else {
                // Default to ASC if no direction specified
                $sorting[] = ['field' => $sortPart, 'direction' => 'ASC'];
            }
        }

        return $sorting;
    }

    /**
     * Parse select field items from TCA configuration
     *
     * @param array<int|string, mixed> $items TCA items array
     * @param bool $skipDividers Whether to skip divider items
     * @return SelectItemsResult Array with 'values' and 'labels' keys
     */
    public function parseSelectItems(array $items, bool $skipDividers = true): array
    {
        $result = [
            'values' => [],
            'labels' => [],
        ];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemValue = '';
            $itemLabel = '';

            // Handle both associative and numeric index syntax
            if (isset($item['value']) && isset($item['label'])) {
                // New associative syntax
                $itemValue = $item['value'];
                $itemLabel = $item['label'];
            } elseif (isset($item[0]) && isset($item[1])) {
                // Old numeric index syntax
                $itemValue = $item[1];
                $itemLabel = $item[0];
            } elseif (isset($item['numIndex']) && is_array($item['numIndex'])) {
                // XML converted to array format
                if (isset($item['numIndex']['label']) && isset($item['numIndex']['value'])) {
                    $itemLabel = $item['numIndex']['label'];
                    $itemValue = $item['numIndex']['value'];
                }
            }

            // Skip dividers if requested
            if ($skipDividers && $itemValue === '--div--') {
                continue;
            }

            if (is_scalar($itemValue) && (string)$itemValue !== '') {
                $normalizedValue = (string)$itemValue;
                $result['values'][] = $normalizedValue;
                $result['labels'][$normalizedValue] = is_scalar($itemLabel) ? (string)$itemLabel : '';
            }
        }

        return $result;
    }

    /**
     * Get allowed values for a select field
     *
     * @param string $table Table name
     * @param string $fieldName Field name
     * @return list<string>|null Array of allowed values or null if not a select field
     */
    public function getSelectFieldAllowedValues(string $table, string $fieldName): ?array
    {
        $fieldConfig = $this->getFieldConfig($table, $fieldName);
        if (!$fieldConfig) {
            return null;
        }

        $config = isset($fieldConfig['config']) && is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];

        // Only process select fields
        if (($config['type'] ?? '') !== 'select') {
            return null;
        }

        // pages.doktype is not fully described by the static selector alone.
        // Custom page types may also be registered via TCA types or the
        // PageDoktypeRegistry, so merge all three sources before validating.
        if ($table === 'pages' && $fieldName === 'doktype') {
            $allowedValues = [];

            if (isset($config['items']) && is_array($config['items'])) {
                $parsed = $this->parseSelectItems($config['items']);
                $allowedValues = $parsed['values'];
            }

            $pageTypes = $this->getTableTca('pages')['types'] ?? [];
            if (is_array($pageTypes)) {
                foreach (array_keys($pageTypes) as $doktype) {
                    $normalizedDoktype = $this->normalizeDoktypeValue($doktype);
                    if ($normalizedDoktype !== null) {
                        $allowedValues[] = $normalizedDoktype;
                    }
                }
            }

            foreach (array_keys($this->pageDoktypeRegistry->exportConfiguration()) as $doktype) {
                $normalizedDoktype = $this->normalizeDoktypeValue($doktype);
                if ($normalizedDoktype !== null) {
                    $allowedValues[] = $normalizedDoktype;
                }
            }

            $allowedValues = array_values(array_unique($allowedValues));
            sort($allowedValues, \SORT_NATURAL);

            return $allowedValues === [] ? null : $allowedValues;
        }

        // If it's a foreign table select, we can't validate values here
        if (!empty($config['foreign_table'])) {
            return null;
        }

        // itemsProcFunc fields add dynamic values beyond the static TCA items array.
        // If the static items contain only blank/placeholder entries ('' or '-1'),
        // the proc func is the sole source of valid values (e.g. pages.backend_layout
        // where PageTS identifiers like "pagets__BlogPost" are added dynamically).
        // In that case we cannot validate — return null.
        // Otherwise, validate against the static items as a best-effort check
        // (e.g. tt_content.colPos has meaningful static items 0-3).
        if (!empty($config['itemsProcFunc'])) {
            if (!isset($config['items']) || !is_array($config['items'])) {
                return null;
            }
            $parsed = $this->parseSelectItems($config['items']);
            $values = $parsed['values'];
            $meaningful = array_filter($values, static fn(string $v): bool => $v !== '' && $v !== '-1');
            return $meaningful !== [] ? $values : null;
        }

        // Use the shared parseSelectItems method
        if (isset($config['items']) && is_array($config['items'])) {
            $parsed = $this->parseSelectItems($config['items']);
            return empty($parsed['values']) ? null : $parsed['values'];
        }

        return null;
    }

    private function normalizeDoktypeValue(mixed $doktype): ?string
    {
        if (is_int($doktype)) {
            return (string)$doktype;
        }

        if (is_string($doktype) && $doktype !== '' && ctype_digit($doktype)) {
            return $doktype;
        }

        return null;
    }

    /**
     * Validate a field value based on its TCA configuration
     *
     * @param string $table Table name
     * @param string $fieldName Field name
     * @param mixed $value Field value
     * @return string|null Error message if validation fails, null if valid
     */
    public function validateFieldValue(string $table, string $fieldName, $value): ?string
    {
        $fieldConfig = $this->getFieldConfig($table, $fieldName);
        if (!$fieldConfig) {
            return "Field '{$fieldName}' does not exist in table '{$table}'";
        }

        $config = isset($fieldConfig['config']) && is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
        $fieldType = is_string($config['type'] ?? null) ? $config['type'] : '';

        // Check max length for string fields
        if (in_array($fieldType, ['input', 'text', 'email', 'link', 'slug', 'color']) && is_string($value)) {
            $maxLength = is_numeric($config['max'] ?? null) ? (int)$config['max'] : 0;
            if ($maxLength > 0 && mb_strlen($value) > $maxLength) {
                return "Field '{$fieldName}' value exceeds maximum length of {$maxLength} characters";
            }
        }

        // Validate select fields
        if ($fieldType === 'select' && empty($config['foreign_table'])) {
            $allowedValues = $this->getSelectFieldAllowedValues($table, $fieldName);
            if ($allowedValues !== null) {
                // Handle comma-separated values for multiple select
                $values = is_string($value) ? GeneralUtility::trimExplode(',', $value, true) : [$value];

                foreach ($values as $val) {
                    $normalizedValue = is_scalar($val) ? (string)$val : '';
                    if (!in_array($normalizedValue, $allowedValues, true)) {
                        $allowedList = implode(', ', array_map(static fn(string $v): string => "'{$v}'", $allowedValues));
                        return "Field '{$fieldName}' value '{$normalizedValue}' must be one of: {$allowedList}";
                    }
                }
            }
        }

        // Validate required fields
        $required = (bool)($config['required'] ?? false);
        $eval = is_string($config['eval'] ?? null) ? $config['eval'] : '';
        if ($required || $eval !== '') {
            $evalRules = GeneralUtility::trimExplode(',', $eval, true);
            if ($required || in_array('required', $evalRules, true)) {
                if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                    return "Field '{$fieldName}' is required";
                }
            }
        }

        return null;
    }

    /**
     * Get record title/label using TYPO3's BackendUtility
     *
     * @param array<string, mixed> $record
     */
    public function getRecordTitle(string $table, array $record): string
    {
        return BackendUtility::getRecordTitle($table, $record);
    }

    /**
     * Translate a TCA label using TYPO3's language system
     */
    public static function translateLabel(string $label): string
    {
        if (str_starts_with($label, 'LLL:')) {
            if (isset(self::DEPRECATED_LABEL_FALLBACKS[$label])) {
                return self::DEPRECATED_LABEL_FALLBACKS[$label];
            }

            // Check if language service is available, initialize if not
            if (!isset($GLOBALS['LANG'])) {
                try {
                    $languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
                    $GLOBALS['LANG'] = $languageServiceFactory->create('default');
                } catch (\Throwable) {
                    // If initialization fails, return a fallback
                    // Extract the last part of the LLL path as fallback
                    if (preg_match('/\.([^:]+):?$/', $label, $matches)) {
                        return ucfirst(str_replace('_', ' ', $matches[1]));
                    }
                    return $label;
                }
            }

            $languageService = $GLOBALS['LANG'] ?? null;
            if (!$languageService instanceof LanguageService) {
                return $label;
            }

            $translated = $languageService->sL($label);

            // If translation failed, try to extract a meaningful fallback
            if (empty($translated)) {
                // Extract the last part of the LLL path as fallback
                // e.g., "LLL:EXT:news/Resources/Private/Language/locallang_be.xlf:plugin.news_list.title" -> "news_list"
                if (preg_match('/\.([^.]+)\.title$/', $label, $matches)) {
                    return str_replace('_', ' ', ucfirst($matches[1]));
                }

                // For other patterns, extract the last meaningful part
                if (preg_match('/[:\.]([^:.]+)$/', $label, $matches)) {
                    return str_replace(['_', '-'], ' ', ucfirst($matches[1]));
                }

                // Last resort: return the raw LLL reference (better than empty)
                return $label;
            }

            return $translated;
        }

        return $label;
    }
}
