<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Core\Environment;

/** Bounded tail-reader for TYPO3 file logs. */
final class DeveloperLogReader
{
    private const TAIL_BYTES = 262144;

    /** @return list<string> */
    private function listLogFiles(): array
    {
        $logDirectory = Environment::getVarPath() . '/log';
        $realLogDirectory = realpath($logDirectory);
        if ($realLogDirectory === false) {
            return [];
        }
        $files = glob($logDirectory . '/typo3_*.log');
        $files = $files !== false ? $files : [];
        $files = array_values(array_filter($files, static function (string $file) use ($realLogDirectory): bool {
            $realFile = realpath($file);
            return $realFile !== false
                && str_starts_with($realFile, $realLogDirectory . DIRECTORY_SEPARATOR)
                && is_file($realFile)
                && is_readable($realFile)
                && !str_contains(basename($realFile), 'deprecations');
        }));

        return $files;
    }

    /** @return array{timestamp: ?string, level: string, message: string, file: string}|null */
    public function readLatestError(): ?array
    {
        $latest = null;
        $latestTimestamp = 0;
        foreach ($this->listLogFiles() as $file) {
            foreach ($this->parseFile($file) as $entry) {
                if (!in_array($entry['level'], ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR'], true)) {
                    continue;
                }
                $parsedTimestamp = strtotime($entry['timestamp'] ?? '');
                $timestamp = $parsedTimestamp !== false ? $parsedTimestamp : 0;
                if ($latest === null || $timestamp >= $latestTimestamp) {
                    $latest = $entry + ['file' => basename($file)];
                    $latestTimestamp = $timestamp;
                }
            }
        }

        return $latest;
    }

    /** @return list<array{timestamp: ?string, level: string, message: string}> */
    private function parseFile(string $file): array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $fileSize = filesize($file);
            $size = $fileSize !== false ? $fileSize : 0;
            if ($size > self::TAIL_BYTES) {
                fseek($handle, $size - self::TAIL_BYTES);
                fgets($handle);
            }

            $entries = [];
            $current = null;
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^(.*?)\[(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG)\]\s*(.*)$/', $line, $matches) === 1) {
                    if ($current !== null) {
                        $entries[] = $current;
                    }
                    $current = [
                        'timestamp' => trim($matches[1]) !== '' ? trim($matches[1]) : null,
                        'level' => $matches[2],
                        'message' => $matches[3],
                    ];
                } elseif ($current !== null) {
                    $current['message'] .= "\n" . $line;
                }
            }
            if ($current !== null) {
                $entries[] = $current;
            }

            return $entries;
        } finally {
            fclose($handle);
        }
    }

}
