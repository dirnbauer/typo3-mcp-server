<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Exception\ValidationException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates or extends XLIFF 2.0 language files (ICU message syntax) inside TYPO3 extensions.
 */
final class LocallangFileService
{
    private const EXTENSION_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';
    private const XLIFF2_NS = 'urn:oasis:names:tc:xliff:document:2.0';

    /**
     * @param list<array{id: string, source: string, target?: string}> $transUnits
     */
    public function createOrExtend(
        string $extensionKey,
        string $fileName,
        array $transUnits,
        string $extensionBasePath = 'packages',
    ): string {
        $this->validateExtensionKey($extensionKey);
        $this->validateFileName($fileName);
        $this->validateTransUnits($transUnits);

        $extensionPath = $this->resolveExtensionPath($extensionKey, $extensionBasePath);
        $targetFile = rtrim($extensionPath, '/') . '/Resources/Private/Language/' . $fileName;
        GeneralUtility::mkdir_deep(dirname($targetFile));

        $document = $this->loadOrCreateDocument($targetFile);
        $fileNode = $document->getElementsByTagName('file')->item(0);
        if (!$fileNode instanceof \DOMElement) {
            throw new ValidationException(['Unable to prepare XLF document root.']);
        }

        $added = 0;
        $updated = 0;
        foreach ($transUnits as $unit) {
            $existing = $this->findUnit($fileNode, $unit['id']);
            if ($existing instanceof \DOMElement) {
                $this->setSegmentText($existing, 'source', $unit['source']);
                if (isset($unit['target']) && $unit['target'] !== '') {
                    $this->setSegmentText($existing, 'target', $unit['target']);
                }
                ++$updated;
                continue;
            }

            $unitElement = $this->createUnitElement($document, $unit['id'], $unit['source'], $unit['target'] ?? null);
            $fileNode->appendChild($unitElement);
            ++$added;
        }

        $document->formatOutput = true;
        if ($document->save($targetFile) === false) {
            throw new ValidationException(['Unable to write language file: ' . $targetFile]);
        }

        return $targetFile . ' (' . $added . ' added, ' . $updated . ' updated)';
    }

    private function resolveExtensionPath(string $extensionKey, string $extensionBasePath): string
    {
        if (ExtensionManagementUtility::isLoaded($extensionKey)) {
            return ExtensionManagementUtility::extPath($extensionKey);
        }

        $composerName = str_replace('_', '-', $extensionKey);
        $candidate = Environment::getProjectPath() . '/' . trim($extensionBasePath, '/') . '/' . $composerName;
        if (is_dir($candidate)) {
            return $candidate . '/';
        }

        throw new ValidationException([
            'Extension "' . $extensionKey . '" is not loaded and no directory was found at ' . $candidate,
        ]);
    }

    private function loadOrCreateDocument(string $targetFile): \DOMDocument
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;

        if (is_file($targetFile)) {
            if (!$document->load($targetFile)) {
                throw new ValidationException(['Existing language file is not valid XML: ' . $targetFile]);
            }

            return $document;
        }

        $xliff = $document->createElementNS(self::XLIFF2_NS, 'xliff');
        $xliff->setAttribute('version', '2.0');
        $xliff->setAttribute('srcLang', 'en');
        $file = $document->createElement('file');
        $file->setAttribute('id', 'messages');
        $file->setAttribute('original', 'messages');
        $xliff->appendChild($file);
        $document->appendChild($xliff);

        return $document;
    }

    private function findUnit(\DOMElement $fileNode, string $id): ?\DOMElement
    {
        foreach ($fileNode->getElementsByTagName('unit') as $node) {
            if ($node instanceof \DOMElement && $node->getAttribute('id') === $id) {
                return $node;
            }
        }

        return null;
    }

    private function createUnitElement(\DOMDocument $document, string $id, string $source, ?string $target): \DOMElement
    {
        $unit = $document->createElement('unit');
        $unit->setAttribute('id', $id);
        $segment = $document->createElement('segment');

        $sourceElement = $document->createElement('source');
        $sourceElement->appendChild($document->createTextNode($source));
        $segment->appendChild($sourceElement);

        if ($target !== null && $target !== '') {
            $targetElement = $document->createElement('target');
            $targetElement->appendChild($document->createTextNode($target));
            $segment->appendChild($targetElement);
        }

        $unit->appendChild($segment);

        return $unit;
    }

    private function setSegmentText(\DOMElement $unit, string $tagName, string $value): void
    {
        $segment = $unit->getElementsByTagName('segment')->item(0);
        if (!$segment instanceof \DOMElement) {
            $document = $unit->ownerDocument;
            if ($document === null) {
                return;
            }
            $segment = $document->createElement('segment');
            $unit->appendChild($segment);
        }

        foreach ($segment->getElementsByTagName($tagName) as $node) {
            if ($node instanceof \DOMElement) {
                while ($node->firstChild !== null) {
                    $node->removeChild($node->firstChild);
                }
                $node->appendChild($node->ownerDocument?->createTextNode($value) ?? new \DOMText($value));

                return;
            }
        }

        $document = $segment->ownerDocument;
        if ($document === null) {
            return;
        }

        $element = $document->createElement($tagName);
        $element->appendChild($document->createTextNode($value));
        $segment->appendChild($element);
    }

    private function validateExtensionKey(string $extensionKey): void
    {
        if (preg_match(self::EXTENSION_KEY_PATTERN, $extensionKey) !== 1) {
            throw new ValidationException(['Invalid extension key "' . $extensionKey . '".']);
        }
    }

    private function validateFileName(string $fileName): void
    {
        if ($fileName === '' || !str_ends_with($fileName, '.xlf')) {
            throw new ValidationException(['fileName must end with .xlf']);
        }
        if (str_contains($fileName, '/') || str_contains($fileName, '\\')) {
            throw new ValidationException(['fileName must not contain path separators.']);
        }
    }

    /**
     * @param list<array{id: string, source: string, target?: string}> $transUnits
     */
    private function validateTransUnits(array $transUnits): void
    {
        if ($transUnits === []) {
            throw new ValidationException(['At least one translation unit is required.']);
        }

        foreach ($transUnits as $index => $unit) {
            if (!isset($unit['id']) || trim($unit['id']) === '') {
                throw new ValidationException(['Translation unit at index ' . $index . ' requires a non-empty id.']);
            }
            if (!isset($unit['source']) || trim($unit['source']) === '') {
                throw new ValidationException(['Translation unit at index ' . $index . ' requires a non-empty source.']);
            }
        }
    }
}
