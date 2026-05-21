<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

/**
 * Result severity for MCP connection diagnostics shown in the backend module.
 */
enum DiagnosticStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    case Info = 'info';

    public function isWorseThan(self $other): bool
    {
        $order = [
            self::Ok->value => 0,
            self::Info->value => 1,
            self::Warning->value => 2,
            self::Error->value => 3,
        ];

        return $order[$this->value] > $order[$other->value];
    }
}
