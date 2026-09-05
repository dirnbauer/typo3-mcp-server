<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Core\Environment;

/** Bounded tail-reader for TYPO3 file logs. */
final class DeveloperLogReader
{
    private const TAIL_BYTES = 262144;

    private const LEVEL_RANKS = [
        'EMERGENCY' => 0,
        'ALERT' => 1,
        'CRITICAL' => 2,
        'ERROR' => 3,
        'WARNING' => 4,
        'NOTICE' => 5,
        'INFO' => 6,
        'DEBUG' => 7,
    ];

    /** @return list<string> */
    public function listLogFiles(bool $includeDeprecations = false): array
    {
        $logDirectory = Environment::getVarPath() . '/log';
        $realLogDirectory = realpath($logDirectory);
        if ($realLogDirectory === false) {
            return [];
        }
        $files = glob($logDirectory . '/typo3_*.log');
        $files = $files !== false ? $files : [];
        $files = array_values(array_filter($files, static function (string $file) use ($includeDeprecations, $realLogDirectory): bool {
            $realFile = realpath($file);
            return $realFile !== false
                && str_starts_with($realFile, $realLogDirectory . DIRECTORY_SEPARATOR)
                && is_file($realFile)
                && is_readable($realFile)
                && (str_contains(basename($realFile), 'deprecations') === $includeDeprecations);
        }));
        usort($files, static fn(string $left, string $right): int => self::modificationTime($right) <=> self::modificationTime($left));

        return $files;
    }

    /**
     * @param list<string> $files
     * @return list<array{timestamp: ?string, level: string, message: string, file: string}>
     */
    public function readEntries(array $files, int $limit, ?string $minimumLevel = null): array
    {
        $maximumRank = $minimumLevel !== null
            ? (self::LEVEL_RANKS[strtoupper($minimumLevel)] ?? 7)
            : 7;
        $entries = [];
        foreach ($files as $file) {
            foreach (array_reverse($this->parseFile($file)) as $entry) {
                if ((self::LEVEL_RANKS[$entry['level']] ?? 7) > $maximumRank) {
                    continue;
                }
                $entry['file'] = basename($file);
                $entries[] = $entry;
                if (count($entries) >= max(1, $limit)) {
                    return $entries;
                }
            }
        }

        return $entries;
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

    private static function modificationTime(string $file): int
    {
        $time = filemtime($file);

        return $time !== false ? $time : 0;
    }
}
