#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-off migration: locallang_mod XLIFF 1.2 → XLIFF 2.0 with ICU placeholders.
 *
 * Usage: php Build/Scripts/convert-locallang-mod-to-xliff2.php
 */

$projectRoot = dirname(__DIR__, 2);
$languageDir = $projectRoot . '/Resources/Private/Language/';
$enFile = $languageDir . 'locallang_mod.xlf';
$deLegacyFile = $languageDir . 'de.locallang_mod.xlf';
$deOverridesFile = __DIR__ . '/de-locallang-mod-overrides.php';

if (!is_file($enFile) || !is_file($deLegacyFile)) {
    fwrite(STDERR, "Missing source XLF files.\n");
    exit(1);
}

/** @var array<string, string> $overrides */
$overrides = is_file($deOverridesFile) ? require $deOverridesFile : [];

/**
 * @return array<string, string>
 */
function parseXliff12(string $path, bool $targets): array
{
    $document = new DOMDocument();
    $document->load($path);
    $units = [];
    foreach ($document->getElementsByTagName('trans-unit') as $unit) {
        if (!$unit instanceof DOMElement) {
            continue;
        }
        $id = $unit->getAttribute('id');
        if ($id === '') {
            continue;
        }
        $tag = $targets ? 'target' : 'source';
        $node = $unit->getElementsByTagName($tag)->item(0);
        $units[$id] = trim($node?->textContent ?? '');
    }

    return $units;
}

function normalizeIcu(string $text): string
{
    $text = preg_replace('/%s/u', '{message}', $text) ?? $text;
    $text = preg_replace('/%1\$s/u', '{0}', $text) ?? $text;
    $text = preg_replace('/%2\$s/u', '{1}', $text) ?? $text;
    $text = preg_replace('/%3\$s/u', '{2}', $text) ?? $text;

    return $text;
}

function escapeXml(string $text): string
{
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<string, string> $sources
 * @param array<string, string>|null $targets
 */
function writeXliff2(string $path, array $sources, ?array $targets): void
{
    $srcLang = 'en';
    $trgLang = $targets !== null ? ' trgLang="de"' : '';
    $lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<xliff xmlns="urn:oasis:names:tc:xliff:document:2.0" version="2.0" srcLang="' . $srcLang . '"' . $trgLang . '>',
        '  <file id="mcp_server.mod" original="EXT:mcp_server/Resources/Private/Language/locallang_mod.xlf">',
    ];

    foreach ($sources as $id => $source) {
        $source = normalizeIcu($source);
        $lines[] = '    <unit id="' . escapeXml($id) . '">';
        $lines[] = '      <segment>';
        $lines[] = '        <source>' . escapeXml($source) . '</source>';
        if ($targets !== null) {
            $target = $targets[$id] ?? $source;
            $target = normalizeIcu($target);
            $lines[] = '        <target>' . escapeXml($target) . '</target>';
        }
        $lines[] = '      </segment>';
        $lines[] = '    </unit>';
    }

    $lines[] = '  </file>';
    $lines[] = '</xliff>';
    $lines[] = '';

    $content = implode("\n", $lines);
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Unable to write ' . $path);
    }
}

$sources = parseXliff12($enFile, false);
$legacyTargets = parseXliff12($deLegacyFile, true);

$targets = $legacyTargets;
foreach ($overrides as $id => $target) {
    $targets[$id] = $target;
}

foreach (array_keys($sources) as $id) {
    if (!isset($targets[$id]) || $targets[$id] === '') {
        $targets[$id] = $sources[$id];
        fwrite(STDERR, "Warning: no German target for [{$id}], using English source.\n");
    }
}

$enOut = $languageDir . 'locallang_mod.xlf';
$deOut = $languageDir . 'de.locallang_mod.xlf';

// Backup legacy files once
foreach ([$enOut, $deOut] as $file) {
    $backup = $file . '.1.2.bak';
    if (!is_file($backup) && is_file($file)) {
        copy($file, $backup);
    }
}

writeXliff2($enOut, $sources, null);
writeXliff2($deOut, $sources, $targets);

echo 'Wrote ' . count($sources) . " units to XLIFF 2.0:\n";
echo '  ' . $enOut . "\n";
echo '  ' . $deOut . "\n";
