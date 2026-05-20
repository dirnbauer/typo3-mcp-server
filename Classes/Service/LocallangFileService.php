<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use DOMDocument;
use DOMElement;
use Hn\McpServer\Exception\ValidationException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates or extends XLF language files inside TYPO3 extensions.
 */
final class LocallangFileService
{
    private const EXTENSION_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

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
        if (!$fileNode instanceof DOMElement) {
            throw new ValidationException(['Unable to prepare XLF document root.']);
        }

        $added = 0;
        $updated = 0;
        foreach ($transUnits as $unit) {
            $existing = $this->findTransUnit($fileNode, $unit['id']);
            if ($existing instanceof DOMElement) {
                $this->setTransUnitText($existing, 'source', $unit['source']);
                if (isset($unit['target']) && $unit['target'] !== '') {
                    $this->setTransUnitText($existing, 'target', $unit['target']);
                }
                ++$updated;
                continue;
            }

            $transUnit = $document->createElement('trans-unit');
            $transUnit->setAttribute('id', $unit['id']);
            $source = $document->createElement('source', $unit['source']);
            $transUnit->appendChild($source);
            if (isset($unit['target']) && $unit['target'] !== '') {
                $target = $document->createElement('target', $unit['target']);
                $transUnit->appendChild($target);
            }
            $fileNode->appendChild($transUnit);
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

    private function loadOrCreateDocument(string $targetFile): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;

        if (is_file($targetFile)) {
            if (!$document->load($targetFile)) {
                throw new ValidationException(['Existing language file is not valid XML: ' . $targetFile]);
            }
            return $document;
        }

        $xliff = $document->createElement('xliff');
        $xliff->setAttribute('version', '1.2');
        $xliff->setAttribute('xmlns', 'urn:oasis:names:tc:xliff:document:1.2');
        $file = $document->createElement('file');
        $file->setAttribute('source-language', 'en');
        $file->setAttribute('datatype', 'plaintext');
        $file->setAttribute('original', 'messages');
        $xliff->appendChild($file);
        $document->appendChild($xliff);

        return $document;
    }

    private function findTransUnit(DOMElement $fileNode, string $id): ?DOMElement
    {
        foreach ($fileNode->getElementsByTagName('trans-unit') as $node) {
            if ($node instanceof DOMElement && $node->getAttribute('id') === $id) {
                return $node;
            }
        }

        return null;
    }

    private function setTransUnitText(DOMElement $transUnit, string $tagName, string $value): void
    {
        foreach ($transUnit->getElementsByTagName($tagName) as $node) {
            if ($node instanceof DOMElement) {
                while ($node->firstChild !== null) {
                    $node->removeChild($node->firstChild);
                }
                $node->appendChild($node->ownerDocument?->createTextNode($value) ?? new \DOMText($value));
                return;
            }
        }

        $document = $transUnit->ownerDocument;
        if ($document === null) {
            return;
        }
        $element = $document->createElement($tagName, $value);
        $transUnit->appendChild($element);
    }

    private function validateExtensionKey(string $extensionKey): void
    {
        if (!preg_match(self::EXTENSION_KEY_PATTERN, $extensionKey)) {
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
