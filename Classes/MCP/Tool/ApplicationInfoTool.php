<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Composer\InstalledVersions;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageManager;

/** Report the live TYPO3 runtime an agent is developing against. */
#[DevSiteOnly]
final class ApplicationInfoTool extends AbstractTool
{
    public function __construct(
        private readonly PackageManager $packageManager,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Report TYPO3/PHP versions, context, database platform, and active extensions. '
                . 'Dev-site only; set packages=true only when the complete Composer inventory is needed.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'packages' => [
                        'type' => 'boolean',
                        'description' => 'Include every Composer package and version (potentially large).',
                        'default' => false,
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
        $version = new Typo3Version();
        $extensions = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $extensions[$package->getPackageKey()] = [
                'composerName' => $package->getValueFromComposerManifest('name'),
                'version' => $package->getPackageMetaData()->getVersion(),
            ];
        }
        ksort($extensions);

        $payload = [
            'typo3Version' => $version->getVersion(),
            'typo3MajorVersion' => $version->getMajorVersion(),
            'typo3Branch' => $version->getBranch(),
            'phpVersion' => PHP_VERSION,
            'applicationContext' => (string)Environment::getContext(),
            'composerMode' => Environment::isComposerMode(),
            'projectPath' => Environment::getProjectPath(),
            'os' => PHP_OS_FAMILY,
            'database' => $this->databaseInfo(),
            'activeExtensions' => $extensions,
        ];

        $installedPackages = InstalledVersions::getInstalledPackages();
        if (($params['packages'] ?? false) === true) {
            $packages = [];
            foreach ($installedPackages as $packageName) {
                $packages[$packageName] = InstalledVersions::getPrettyVersion($packageName);
            }
            ksort($packages);
            $payload['composerPackages'] = $packages;
        } else {
            $payload['composerPackageCount'] = count($installedPackages);
            $payload['hint'] = 'Pass {"packages":true} only when the full Composer package list is required.';
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new CallToolResult([new TextContent($json !== false ? $json : '{}')]);
    }

    /** @return array<string, string> */
    private function databaseInfo(): array
    {
        try {
            $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
            $platformClass = $connection->getDatabasePlatform()::class;
            $namespaceSeparator = strrpos($platformClass, '\\');

            return [
                'platform' => $namespaceSeparator === false ? $platformClass : substr($platformClass, $namespaceSeparator + 1),
                'serverVersion' => $connection->getServerVersion(),
            ];
        } catch (\Throwable) {
            return ['status' => 'unavailable'];
        }
    }
}
