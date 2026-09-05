<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

/** Convert verbose TYPO3 exception log lines into focused diagnostic data. */
final class DeveloperLogEntryParser
{
    public const DEFAULT_TRACE_FRAMES = 5;

    private const EXCEPTION_PATTERN = '/(?P<class>[\w\\\\]+),\s*code\s*#(?P<code>\d+),\s*file\s*(?P<file>.+?),\s*line\s*(?P<line>\d+):\s*(?P<message>.*)$/s';

    /**
     * @param array{timestamp: ?string, level: string, message: string, file: string} $entry
     * @return array<string, mixed>
     */
    public function parse(array $entry, bool $full = false, int $traceFrames = self::DEFAULT_TRACE_FRAMES): array
    {
        if ($full) {
            return $entry;
        }

        $parsed = [
            'timestamp' => $entry['timestamp'],
            'level' => $entry['level'],
            'logFile' => $entry['file'],
        ];
        $context = $this->extractContext($entry['message']);
        $summary = $this->stripContext($entry['message']);
        $exception = $this->exceptionFromContext($context) ?? $this->exceptionFromSummary($summary);
        if ($exception !== null) {
            $parsed['exception'] = $exception;
        } else {
            $parsed['message'] = $this->truncate($summary, 2000);
        }

        if (isset($context['request_url']) && is_string($context['request_url'])) {
            $parsed['requestUrl'] = $context['request_url'];
        }
        foreach (['mode', 'application_mode'] as $key) {
            if (isset($context[$key]) && is_string($context[$key])) {
                $parsed[$key === 'mode' ? 'mode' : 'applicationMode'] = $context[$key];
            }
        }

        $trace = isset($context['exception']) && is_string($context['exception'])
            ? $this->trimTrace($context['exception'], $traceFrames)
            : null;
        if ($trace !== null) {
            $parsed['trace'] = $trace['frames'];
            if ($trace['omitted'] > 0) {
                $parsed['traceOmittedFrames'] = $trace['omitted'];
                $parsed['hint'] = 'Stack trace truncated. Pass {"full": true} for the complete entry.';
            }
        }

        return $parsed;
    }

    /** @return array<string, mixed> */
    private function extractContext(string $message): array
    {
        $start = strpos($message, ' - {');
        if ($start === false) {
            return [];
        }
        $decoded = json_decode(substr($message, $start + 3), true);
        if (!is_array($decoded)) {
            return [];
        }
        $context = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    private function stripContext(string $message): string
    {
        $start = strpos($message, ' - {');
        $summary = $start === false ? $message : substr($message, 0, $start);
        if (preg_match('/^request="[^"]*"\s+component="[^"]*":\s*(.*)$/s', $summary, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($summary);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function exceptionFromContext(array $context): ?array
    {
        if (!isset($context['exception_class']) || !is_string($context['exception_class'])) {
            return null;
        }
        $exception = ['class' => $context['exception_class']];
        foreach (['exception_code' => 'code', 'file' => 'file', 'line' => 'line', 'message' => 'message'] as $source => $target) {
            if (isset($context[$source]) && (is_string($context[$source]) || is_int($context[$source]))) {
                $exception[$target] = $target === 'message'
                    ? $this->truncate((string)$context[$source], 2000)
                    : $context[$source];
            }
        }

        return $exception;
    }

    /** @return array<string, mixed>|null */
    private function exceptionFromSummary(string $summary): ?array
    {
        if (preg_match(self::EXCEPTION_PATTERN, $summary, $matches) !== 1) {
            return null;
        }

        return [
            'class' => $matches['class'],
            'code' => (int)$matches['code'],
            'file' => $matches['file'],
            'line' => (int)$matches['line'],
            'message' => $this->truncate(trim($matches['message']), 2000),
        ];
    }

    /** @return array{frames: list<string>, omitted: int}|null */
    private function trimTrace(string $exception, int $keep): ?array
    {
        if (preg_match_all('/#\d+\s[^\n]*/', $exception, $matches) === 0) {
            return null;
        }
        $frames = $matches[0];

        return [
            'frames' => array_slice($frames, 0, max(0, $keep)),
            'omitted' => max(0, count($frames) - max(0, $keep)),
        ];
    }

    private function truncate(string $value, int $maximumLength): string
    {
        return strlen($value) <= $maximumLength
            ? $value
            : substr($value, 0, $maximumLength) . '… [truncated]';
    }
}
