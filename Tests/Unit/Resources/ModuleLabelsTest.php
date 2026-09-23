<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every label the backend module asks for exists in English and German.
 *
 * A missing unit renders as an empty string in Fluid and JavaScript, so a
 * typo in a template would otherwise only show up as a blank button.
 */
final class ModuleLabelsTest extends TestCase
{
    private const LANGUAGE = __DIR__ . '/../../../Resources/Private/Language/';
    private const PRIVATE = __DIR__ . '/../../../Resources/Private/';

    #[Test]
    public function everyReferencedLabelExistsInBothLanguages(): void
    {
        $english = self::unitIds(self::LANGUAGE . 'locallang_mod.xlf');
        $german = self::unitIds(self::LANGUAGE . 'de.locallang_mod.xlf');

        $referenced = $this->referencedIds();
        self::assertGreaterThan(100, count($referenced), 'the scan found the module labels');
        foreach ($referenced as $id) {
            self::assertContains($id, $english, 'English label ' . $id);
            self::assertContains($id, $german, 'German label ' . $id);
        }
    }

    #[Test]
    public function bothLanguagesHaveTheSameUnitsAndNoEmptyTranslation(): void
    {
        foreach (['locallang_mod.xlf', 'Modules/mcp_server.xlf'] as $file) {
            $english = self::unitIds(self::LANGUAGE . $file);
            $german = self::unitIds(self::LANGUAGE . preg_replace('#([^/]+)$#', 'de.$1', $file));
            self::assertSame($english, $german, $file);

            $xml = self::load(self::LANGUAGE . preg_replace('#([^/]+)$#', 'de.$1', $file));
            foreach ($xml->xpath('//x:unit') ?: [] as $unit) {
                $unit->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:2.0');
                $target = $unit->xpath('x:segment/x:target');
                self::assertNotEmpty($target, $file . ' ' . $unit['id']);
                self::assertNotSame('', trim((string)$target[0]), $file . ' ' . $unit['id']);
            }
        }
    }

    #[Test]
    public function theModuleLabelFileCarriesTheV14Keys(): void
    {
        self::assertSame(
            ['title', 'short_description', 'description'],
            self::unitIds(self::LANGUAGE . 'Modules/mcp_server.xlf'),
        );
    }

    /**
     * @return list<string>
     */
    private function referencedIds(): array
    {
        $ids = [];
        $templates = array_merge(
            glob(self::PRIVATE . 'Templates/McpServerModule/*.html') ?: [],
            glob(self::PRIVATE . 'Partials/McpServerModule/*.html') ?: [],
        );
        foreach ($templates as $file) {
            $source = (string)file_get_contents($file);
            preg_match_all('/<f:translate\s+id="([^"{}]+)"\s+domain="mcp_server\.mod"/', $source, $tags);
            preg_match_all('/f:translate\(id: \'([^\']+)\', domain: \'mcp_server\.mod\'\)/', $source, $inline);
            preg_match_all('/(?:titleId|labelId|copyId): \'([^\']+)\'/', $source, $sectionArguments);
            $ids = array_merge($ids, $tags[1], $inline[1], $sectionArguments[1]);
        }

        $javaScript = (string)file_get_contents(__DIR__ . '/../../../Resources/Public/JavaScript/mcp-module.js');
        preg_match_all('/labels\.get\(\'([^\']+)\'/', $javaScript, $javaScriptLabels);
        $ids = array_merge($ids, $javaScriptLabels[1]);

        $controller = (string)file_get_contents(__DIR__ . '/../../../Classes/Controller/McpServerModuleController.php');
        preg_match_all('/(?:translate|jsonError)\(\'([^\']+)\'/', $controller, $controllerLabels);
        // "title" comes from the module label file (see the last test).
        $ids = array_merge($ids, array_diff($controllerLabels[1], ['title']));

        // Built from the check status in the diagnostics partial and in JavaScript.
        foreach (['ok', 'warning', 'error', 'info'] as $status) {
            $ids[] = 'diagnostic.status.' . $status;
        }

        $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => !str_ends_with($id, '.'))));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function unitIds(string $file): array
    {
        $xml = self::load($file);

        return array_values(array_map(static fn(\SimpleXMLElement $unit): string => (string)$unit['id'], $xml->xpath('//x:unit') ?: []));
    }

    private static function load(string $file): \SimpleXMLElement
    {
        $xml = simplexml_load_file($file);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, $file);
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:2.0');

        return $xml;
    }
}
