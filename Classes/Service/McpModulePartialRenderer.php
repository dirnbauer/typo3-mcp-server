<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Renders the backend module's token list and connection check on their
 * own, so the AJAX refreshes return the exact markup the page renders.
 */
final readonly class McpModulePartialRenderer
{
    private const string PARTIALS = 'EXT:mcp_server/Resources/Private/Partials/';

    public function __construct(
        private ViewFactoryInterface $viewFactory,
    ) {}

    /**
     * @param list<array{uid: int, clientName: string, created: string, createdIso: string, expires: string, expiresIso: string, lastUsed: string, lastUsedIso: string}> $tokens
     */
    public function renderTokens(array $tokens, ?ServerRequestInterface $request = null): string
    {
        return $this->render('McpServerModule/Tokens', ['tokens' => $tokens], $request);
    }

    /**
     * @param array{
     *   overallStatus: string,
     *   checks: list<array{id: string, status: string, label: string, message: string, howToCheck: string, fixHint: string}>
     * } $diagnostics
     */
    public function renderDiagnostics(
        array $diagnostics,
        bool $hasWorkspace,
        string $createWorkspaceUrl,
        ?ServerRequestInterface $request = null,
    ): string {
        return $this->render('McpServerModule/Diagnostics', [
            'diagnostics' => $diagnostics,
            'hasWorkspace' => $hasWorkspace,
            'createWorkspaceUrl' => $createWorkspaceUrl,
        ], $request);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $partial, array $variables, ?ServerRequestInterface $request): string
    {
        $view = $this->viewFactory->create(new ViewFactoryData(
            partialRootPaths: [self::PARTIALS],
            templatePathAndFilename: self::PARTIALS . $partial . '.fluid.html',
            request: $request,
            format: 'html',
        ));
        $view->assignMultiple($variables);

        return $view->render();
    }
}
