<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Renders the MCP connection diagnostics panel for Fluid and AJAX refresh.
 */
final readonly class McpDiagnosticsPanelRenderer
{
    public function __construct(
        private ViewFactoryInterface $viewFactory,
    ) {}

    /**
     * @param array{
     *   overallStatus: string,
     *   checks: list<array{
     *     id: string,
     *     status: string,
     *     label: string,
     *     message: string,
     *     howToCheck: string,
     *     fixHint: string
     *   }>
     * } $diagnostics
     */
    public function render(
        array $diagnostics,
        ?string $createWorkspaceUrl = null,
        ?ServerRequestInterface $request = null,
    ): string {
        $view = $this->viewFactory->create(new ViewFactoryData(
            partialRootPaths: ['EXT:mcp_server/Resources/Private/Partials/'],
            templatePathAndFilename: 'EXT:mcp_server/Resources/Private/Partials/McpServerModule/DiagnosticsPanelContent.html',
            request: $request,
            format: 'html',
        ));
        $view->assignMultiple([
            'diagnostics' => $diagnostics,
            'createWorkspaceUrl' => $createWorkspaceUrl,
        ]);

        return $view->render();
    }
}
