<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

/**
 * Redacts sensitive HTTP data before writing to logs (MCP endpoint, etc.).
 */
final class McpHttpLogRedactor
{
    /**
     * @var list<string>
     */
    private const SENSITIVE_HEADER_NAMES = [
        'authorization',
        'cookie',
        'proxy-authorization',
        'x-api-key',
        'set-cookie',
    ];

    /**
     * @param array<string, string[]> $headers
     * @return array<string, string>
     */
    public static function redactHeadersForLog(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, self::SENSITIVE_HEADER_NAMES, true)) {
                $out[$name] = '[REDACTED]';
            } else {
                $out[$name] = implode(', ', $values);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public static function redactQueryParamsForLog(array $queryParams): array
    {
        if (array_key_exists('token', $queryParams)) {
            $queryParams['token'] = '[REDACTED]';
        }

        return $queryParams;
    }
}
