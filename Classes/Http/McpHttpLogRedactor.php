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
     * @param array<array-key, mixed> $queryParams
     * @return array<array-key, mixed>
     */
    public static function redactQueryParamsForLog(array $queryParams): array
    {
        $redacted = [];
        foreach ($queryParams as $key => $value) {
            if (is_string($key) && self::isSensitiveQueryKey($key)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }
            $redacted[$key] = is_array($value)
                ? self::redactQueryParamsForLog($value)
                : $value;
        }

        return $redacted;
    }

    private static function isSensitiveQueryKey(string $key): bool
    {
        $key = strtolower(trim($key));
        if (in_array($key, [
            'token',
            'access_token',
            'refresh_token',
            'code',
            'code_verifier',
            'client_secret',
            'api_key',
            'key',
            'password',
            'state',
        ], true)) {
            return true;
        }

        return preg_match('/(?:token|secret|password)$/D', $key) === 1;
    }
}
