<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Generate a workspace preview URL for a record.
 *
 * Returns an `ADMCMD_prev`-style URL that lets the editor (or anyone with the
 * preview link) view the unpublished workspace state of a page or content
 * element. Mirrors the "View page" / "Show workspace preview" buttons in the
 * TYPO3 backend, exposed as an MCP capability so an LLM can drop verification
 * links into a chat without leaving it.
 *
 * Pages: returns the preview URL for the page.
 * tt_content: returns the preview URL of the parent page anchored to the
 * element (`#c<uid>`), so the editor lands on the changed element.
 */
final class GetPreviewUrlTool extends AbstractRecordTool
{
    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly SiteInformationService $siteInformationService,
        private readonly LanguageService $languageService,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        $properties = [
            'table' => [
                'type' => 'string',
                'description' => 'Table name. Currently supported: "pages" (returns the page preview URL) and "tt_content" (returns the parent page URL anchored to the element).',
                'enum' => ['pages', 'tt_content'],
            ],
            'uid' => [
                'type' => 'integer',
                'description' => 'Live UID of the record. Workspace UIDs are not exposed by other tools, so always pass the value other MCP tools returned.',
            ],
        ];

        if (count($this->languageService->getAvailableIsoCodes()) > 1) {
            $properties['language'] = [
                'type' => 'string',
                'description' => 'Optional language ISO code (e.g. "de"). Defaults to the record\'s own language, or the site default.',
                'enum' => $this->languageService->getAvailableIsoCodes(),
            ];
        }

        return [
            'description' => 'Build a workspace preview URL for a page or content element. The link works without a backend login — '
                . 'send it to a stakeholder for review. The URL is signed against the active workspace; '
                . 'when the change is published or the workspace deleted the link becomes a normal frontend URL.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => ['table', 'uid'],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $table = is_string($params['table'] ?? null) ? $params['table'] : '';
        $uid = is_numeric($params['uid'] ?? null) ? (int)$params['uid'] : 0;
        $language = is_string($params['language'] ?? null) ? $params['language'] : null;

        if ($table === '' || !in_array($table, ['pages', 'tt_content'], true)) {
            throw new ValidationException(['Parameter "table" must be "pages" or "tt_content".']);
        }
        if ($uid <= 0) {
            throw new ValidationException(['Parameter "uid" is required and must be > 0.']);
        }
        $this->ensureTableAccess($table, 'read');

        $languageId = 0;
        if ($language !== null && $language !== '') {
            $resolved = $this->languageService->getUidFromIsoCode($language);
            if ($resolved === null) {
                throw new ValidationException(['Unknown language code: ' . $language]);
            }
            $languageId = $resolved;
        }

        if ($table === 'tt_content') {
            $row = $this->fetchContentRow($uid);
            if ($row === null) {
                throw new ValidationException(['No content element found with UID ' . $uid . '.']);
            }
            $pageId = is_numeric($row['pid'] ?? null) ? (int)$row['pid'] : 0;
            $anchor = '#c' . $uid;
            if ($language === null && is_numeric($row['sys_language_uid'] ?? null)) {
                $languageId = (int)$row['sys_language_uid'];
            }
        } else {
            $pageId = $uid;
            $anchor = '';
            if ($language === null) {
                $row = $this->fetchPageRow($uid);
                if ($row !== null && is_numeric($row['sys_language_uid'] ?? null)) {
                    $languageId = (int)$row['sys_language_uid'];
                }
            }
        }

        $additionalParams = [];
        if ($languageId > 0) {
            $additionalParams['_language'] = $languageId;
        }

        $previewUriBuilder = PreviewUriBuilder::create($pageId)
            ->withAdditionalQueryParameters($additionalParams);

        $uri = $previewUriBuilder->buildUri();
        if ($uri === null) {
            $fallback = $this->siteInformationService->generatePageUrl($pageId, $languageId);
            if ($fallback === null) {
                throw new ValidationException([
                    'Could not build a preview URL — no site is configured for page ' . $pageId . '.',
                ]);
            }
            return $this->createJsonResult([
                'previewUrl' => $fallback . $anchor,
                'pageId' => $pageId,
                'language' => $this->languageService->getIsoCodeFromUid($languageId) ?? null,
                'anchor' => $anchor !== '' ? $anchor : null,
                'workspaceId' => $this->getWorkspaceId(),
                'note' => 'No signed workspace preview was issued; link points at the live frontend URL.',
            ]);
        }

        $url = (string)$uri;
        $absolute = $this->siteInformationService->makeAbsoluteUrl($url);

        return $this->createJsonResult([
            'previewUrl' => ($absolute ?? $url) . $anchor,
            'pageId' => $pageId,
            'language' => $this->languageService->getIsoCodeFromUid($languageId) ?? null,
            'anchor' => $anchor !== '' ? $anchor : null,
            'workspaceId' => $this->getWorkspaceId(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchContentRow(int $uid): ?array
    {
        $rows = $this->fetchRow('tt_content', $uid, ['uid', 'pid', 'sys_language_uid']);
        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPageRow(int $uid): ?array
    {
        return $this->fetchRow('pages', $uid, ['uid', 'sys_language_uid']);
    }

    /**
     * Lightweight reader that respects workspace overlay so we resolve the
     * effective row for the current MCP workspace.
     *
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $table, int $uid, array $fields): ?array
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        $workspaceId = $beUser instanceof BackendUserAuthentication ? (int)$beUser->workspace : 0;

        $connection = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $connection->getRestrictions()->removeAll();
        $row = $connection->select(...$fields)
            ->from($table)
            ->where(
                $connection->expr()->or(
                    $connection->expr()->eq('uid', $connection->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)),
                    $connection->expr()->eq('t3ver_oid', $connection->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }
        if ($workspaceId > 0) {
            \TYPO3\CMS\Backend\Utility\BackendUtility::workspaceOL($table, $row, $workspaceId);
            if (!is_array($row)) {
                return null;
            }
        }
        return $row;
    }

    private function getWorkspaceId(): int
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        return $beUser instanceof BackendUserAuthentication ? (int)$beUser->workspace : 0;
    }
}
