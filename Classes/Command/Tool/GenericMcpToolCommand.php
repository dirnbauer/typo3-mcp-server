<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;
use Hn\McpServer\MCP\ToolRegistry;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;

final class GenericMcpToolCommand extends AbstractMcpToolCommand
{
    /**
     * @param list<string> $exposedOptions
     */
    public function __construct(
        ToolRegistry $toolRegistry,
        TcaFactory $tcaFactory,
        private readonly string $toolName = '',
        private readonly string $description = '',
        private readonly array $exposedOptions = [],
    ) {
        parent::__construct($toolRegistry, $tcaFactory);
    }

    protected function toolName(): string
    {
        return $this->toolName;
    }

    protected function shortDescription(): string
    {
        return $this->description !== ''
            ? $this->description
            : sprintf('Run the %s MCP tool.', $this->toolName);
    }

    protected function exposedOptions(): array
    {
        return array_values(array_filter($this->exposedOptions, is_string(...)));
    }
}
