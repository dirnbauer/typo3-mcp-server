<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\AccessDeniedException;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Hn\McpServer\Service\PageAccessService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/** Compile effective frontend TypoScript for a permitted page. */
#[DevSiteOnly]
final class TypoScriptTool extends AbstractTool
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SysTemplateRepository $sysTemplateRepository,
        private readonly FrontendTypoScriptFactory $frontendTypoScriptFactory,
        private readonly PageAccessService $pageAccessService,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Compile effective frontend TypoScript for a page, including Site Sets and sys_template. '
                . 'Dev-site only; omit path for a compact index or drill into one dot-path.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'pageId' => [
                        'type' => 'integer',
                        'description' => 'Page UID; defaults to the root page of the first configured site.',
                        'minimum' => 1,
                    ],
                    'section' => [
                        'type' => 'string',
                        'enum' => ['setup', 'constants', 'config'],
                        'description' => 'Compiled section to inspect; default setup.',
                        'default' => 'setup',
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'Dot-path such as page.10 or plugin.tx_news.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $section = is_string($params['section'] ?? null) ? $params['section'] : 'setup';
        if (!in_array($section, ['setup', 'constants', 'config'], true)) {
            throw new ValidationException(['section must be one of: setup, constants, config.']);
        }
        $path = is_string($params['path'] ?? null) ? trim($params['path'], ". \t\n\r\0\x0B") : '';
        $pageId = is_numeric($params['pageId'] ?? null) ? (int)$params['pageId'] : $this->defaultPageId();
        $this->pageAccessService->assertPageAccess($pageId, 'compile TypoScript');

        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            throw new ValidationException([sprintf('No configured site contains page %d.', $pageId)]);
        }

        try {
            $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageId)->get();
            $templateRows = $this->sysTemplateRepository->getSysTemplateRowsByRootline($rootline);
            $frontendTypoScript = $this->frontendTypoScriptFactory->createSettingsAndSetupConditions(
                $site,
                $templateRows,
                [],
                null,
            );
            if ($section !== 'constants') {
                $frontendTypoScript = $this->frontendTypoScriptFactory->createSetupConfigOrFullSetup(
                    true,
                    $frontendTypoScript,
                    $site,
                    $templateRows,
                    [],
                    '0',
                    null,
                    null,
                );
            }
            $data = match ($section) {
                'constants' => $frontendTypoScript->getFlatSettings(),
                'config' => $frontendTypoScript->getConfigArray(),
                default => $frontendTypoScript->getSetupArray(),
            };
        } catch (\Throwable $exception) {
            throw new ValidationException([
                'TypoScript compilation failed against TYPO3 internal APIs: ' . $exception->getMessage(),
            ]);
        }
        /** @var array<string, mixed> $data */
        $payload = $this->present($data, $section, $pageId, $site->getIdentifier(), $path);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new CallToolResult([new TextContent($json !== false ? $json : '{}')]);
    }

    private function defaultPageId(): int
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($this->pageAccessService->canAccessPage($site->getRootPageId())) {
                return $site->getRootPageId();
            }
        }

        throw new AccessDeniedException('configured site root', 'compile TypoScript');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function present(array $data, string $section, int $pageId, string $siteIdentifier, string $path): array
    {
        $payload = [
            'pageId' => $pageId,
            'site' => $siteIdentifier,
            'section' => $section,
        ];

        if ($path !== '') {
            if ($section === 'constants' && array_key_exists($path, $data)) {
                $value = $data[$path];
            } else {
                $value = $this->valueAtPath($data, $path, $section);
            }
            $payload['path'] = $path;
            $payload['value'] = $value;

            return $payload;
        }

        if ($section === 'constants') {
            $keys = array_keys($data);
            sort($keys, SORT_STRING);
            $payload['settingCount'] = count($keys);
            $payload['settings'] = array_slice($keys, 0, 200);
            $payload['truncated'] = count($keys) > 200;
        } else {
            $keys = [];
            foreach ($data as $key => $value) {
                $keys[rtrim((string)$key, '.')] = is_array($value) ? 'tree' : 'value';
            }
            ksort($keys);
            $payload['topLevelKeys'] = $keys;
        }
        $payload['hint'] = 'Pass path to retrieve one value or subtree.';

        return $payload;
    }

    /** @param array<string, mixed> $data */
    private function valueAtPath(array $data, string $path, string $section): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            $key = is_array($value) && array_key_exists($segment . '.', $value) ? $segment . '.' : $segment;
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new ValidationException([
                    sprintf('Path "%s" was not found in compiled %s at segment "%s".', $path, $section, $segment),
                ]);
            }
            $value = $value[$key];
        }

        return $value;
    }
}
