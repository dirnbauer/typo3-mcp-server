<?php

declare(strict_types=1);

use Mcp\Client\Client;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$serverCommand = $projectRoot . '/vendor/bin/typo3';

if (!is_file($serverCommand)) {
    fwrite(STDERR, "TYPO3 CLI is missing; run composer install first.\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function runProtocolSmoke(string $mode, string $serverCommand): array
{
    $client = new Client();

    try {
        $session = $client->connect(
            PHP_BINARY,
            [$serverCommand, 'mcp:server'],
            env: protocolSmokeEnvironment(),
            readTimeout: 20.0,
            protocolMode: $mode,
            probeTimeout: 5.0,
        );

        $modern = $session->isModernMode();
        $expectedModern = $mode !== 'legacy';
        if ($expectedModern !== $modern) {
            throw new RuntimeException(sprintf('Mode "%s" negotiated the wrong protocol era.', $mode));
        }

        $expectedVersion = $modern ? '2026-07-28' : '2025-11-25';
        $version = $session->getNegotiatedProtocolVersion();
        if ($version !== $expectedVersion) {
            throw new RuntimeException(sprintf(
                'Mode "%s" negotiated %s; expected %s.',
                $mode,
                $version,
                $expectedVersion,
            ));
        }

        $toolResult = $session->listTools();
        $toolNames = array_map(static fn($tool): string => $tool->name, $toolResult->tools);
        // Six bundled project-authoring/package-management tools are
        // intentionally hidden in a production context; optional Abilities
        // projections may add more. The base production catalog has 39 tools.
        if (count($toolNames) < 39 || !in_array('GetCapabilities', $toolNames, true)) {
            throw new RuntimeException(sprintf(
                'The installed MCP tool catalog is incomplete (%d tools: %s).',
                count($toolNames),
                implode(', ', $toolNames),
            ));
        }

        $promptResult = $session->listPrompts();
        $promptNames = array_map(static fn($prompt): string => $prompt->name, $promptResult->prompts);
        foreach (['typo3-content-edit', 'typo3-translate-page'] as $requiredPrompt) {
            if (!in_array($requiredPrompt, $promptNames, true)) {
                throw new RuntimeException('Missing MCP prompt projection: ' . $requiredPrompt);
            }
        }

        $resourceResult = $session->listResources();
        $resourceUris = array_map(static fn($resource): string => (string)$resource->uri, $resourceResult->resources);
        foreach (['typo3-mcp:///skills', 'typo3-mcp:///skills/typo3-content-edit'] as $requiredResource) {
            if (!in_array($requiredResource, $resourceUris, true)) {
                throw new RuntimeException('Missing MCP skill resource: ' . $requiredResource);
            }
        }

        $capabilityResult = $session->callTool('GetCapabilities', []);
        if ($capabilityResult->isError) {
            throw new RuntimeException('GetCapabilities returned an MCP tool error.');
        }
        if ($capabilityResult->structuredContent === null) {
            throw new RuntimeException('GetCapabilities did not return structuredContent.');
        }

        $prompt = $session->getPrompt('typo3-content-edit', ['request' => 'Protocol smoke test']);
        if ($prompt->messages === []) {
            throw new RuntimeException('The content-edit prompt rendered no messages.');
        }

        $skill = $session->readResource('typo3-mcp:///skills/typo3-content-edit');
        if ($skill->contents === []) {
            throw new RuntimeException('The content-edit skill resource returned no content.');
        }

        if ($modern && $toolResult->resultType !== 'complete') {
            throw new RuntimeException('Modern tools/list is missing resultType=complete.');
        }
        if (!$modern && $toolResult->resultType !== null) {
            throw new RuntimeException('Legacy tools/list leaked a modern resultType field.');
        }

        return [
            'mode' => $mode,
            'protocolVersion' => $version,
            'wireVersion' => $modern ? $session->getModernWireVersion() : null,
            'tools' => count($toolNames),
            'prompts' => count($promptNames),
            'resources' => count($resourceUris),
            'structuredContent' => true,
        ];
    } finally {
        $client->close();
    }
}

/**
 * Keep the SDK's deliberately small subprocess environment while forwarding
 * the variables TYPO3 needs to boot inside DDEV and conventional deployments.
 *
 * @return array<string, string>
 */
function protocolSmokeEnvironment(): array
{
    $environment = [];
    $exactNames = [
        'HOME',
        'LOGNAME',
        'PATH',
        'SHELL',
        'TERM',
        'USER',
        'IS_DDEV_PROJECT',
        'DATABASE_URL',
        'TYPO3_CONTEXT',
        'TYPO3_PATH_APP',
        'TYPO3_PATH_ROOT',
        'TYPO3_PATH_WEB',
    ];

    foreach (getenv() as $name => $value) {
        if (!is_string($name) || !is_string($value)) {
            continue;
        }
        if (!in_array($name, $exactNames, true) && !str_starts_with($name, 'DDEV_')) {
            continue;
        }
        if (str_starts_with($value, '()')) {
            continue;
        }
        $environment[$name] = $value;
    }

    return $environment;
}

try {
    $results = [
        runProtocolSmoke('legacy', $serverCommand),
        // Auto mode must perform server/discover and select the modern era.
        runProtocolSmoke('auto', $serverCommand),
        // Forced mode validates stateless operation for clients that skip
        // discovery because their configuration already pins the RC wire.
        runProtocolSmoke('modern', $serverCommand),
    ];
    $json = json_encode(
        ['ok' => true, 'results' => $results],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    fwrite(STDOUT, $json . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("Protocol smoke test failed: %s\n", $exception->getMessage()));
    exit(1);
}
