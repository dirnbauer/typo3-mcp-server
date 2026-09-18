<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\FileUploadService;
use Hn\McpServer\Service\McpFileSandboxService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * MCP tool for creating and updating text-based files in TYPO3 file storages.
 *
 * Physical files are NOT workspace-versioned in TYPO3.
 * Writing a file affects all workspaces immediately.
 */
final class WriteFileTool extends AbstractTool
{
    public function __construct(
        private readonly McpFileSandboxService $fileSandboxService,
        private readonly FileUploadService $fileUploadService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Create or overwrite a text-based file inside the MCP file sandbox, and/or update its metadata. '
                . 'The configured sandbox defaults to fileadmin/mcp/ and all paths are restricted to that area. '
                . 'In local mode (DDEV / localUnsafeMode=on), combined identifiers may target any accessible FAL file. '
                . 'Supports text files such as .txt, .html, .css, .js, .json, .xml, .csv, .yaml, .md. '
                . '(SVG excluded by default to prevent stored XSS — opt in via TYPO3 SYS textfile_ext + SvgSanitizer.) '
                . 'Binary file uploads (images, PDFs, etc.) are NOT supported. '
                . 'Can also update metadata (title, description, alt text, copyright) on any existing file — including images — without changing the file content. '
                . 'WARNING: Physical files are NOT workspace-versioned — changes take effect immediately across all workspaces.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Target file path inside the MCP file sandbox. '
                            . 'Use either a relative path like "notes/data.json" or an absolute combined identifier inside the sandbox such as "1:/mcp/notes/data.json". '
                            . 'In local mode, combined identifiers may point outside the sandbox. '
                            . 'Parent folders are created automatically when writing content.',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'The text content to write to the file. Omit to only update metadata on an existing file.',
                    ],
                    'overwrite' => [
                        'type' => 'boolean',
                        'description' => 'If true, overwrite an existing file. If false (default), fail when the file already exists.',
                    ],
                    'metadata' => [
                        'type' => 'object',
                        'description' => 'File metadata to set or update. Works on new files and existing files (including images).',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'File title'],
                            'description' => ['type' => 'string', 'description' => 'File description'],
                            'alternative' => ['type' => 'string', 'description' => 'Alternative text (used as alt attribute for images)'],
                            'copyright' => ['type' => 'string', 'description' => 'Copyright notice'],
                        ],
                    ],
                ],
                'required' => ['path'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $path = is_string($params['path'] ?? null) ? $params['path'] : '';
        $content = is_string($params['content'] ?? null) ? $params['content'] : null;
        $overwrite = (bool)($params['overwrite'] ?? false);
        $metadata = $this->fileUploadService->sanitizeMetadata($params['metadata'] ?? null);

        if ($path === '') {
            throw new ValidationException(['Parameter "path" is required. Use a relative path or a combined identifier inside the MCP file sandbox.']);
        }

        if ($content === null && $metadata === []) {
            throw new ValidationException(['Either "content" or "metadata" (or both) must be provided.']);
        }

        $parsed = $this->fileSandboxService->resolveFileTarget($path);
        $storage = $this->fileUploadService->resolveStorage($parsed['storageUid']);

        if ($content === null) {
            return $this->updateMetadataOnly($storage, $parsed, $metadata);
        }

        $this->validateExtension($parsed['fileName']);
        $folder = $this->fileUploadService->ensureFolder($storage, $parsed['folderPath']);

        if ($folder->hasFile($parsed['fileName'])) {
            if (!$overwrite) {
                throw new ValidationException([
                    "File already exists: {$path}. Set overwrite=true to replace it.",
                ]);
            }
            $existingFile = $this->getExistingFile($storage, $folder, $parsed['fileName']);
            $existingFile->setContents($content);
            $this->fileUploadService->applyMetadata($existingFile, $metadata);

            return $this->buildResult('overwritten', $existingFile, $metadata);
        }

        $newFile = $this->fileUploadService->withTemporaryFile(
            static function (string $tempFile) use ($content, $storage, $folder, $parsed): File {
                file_put_contents($tempFile, $content);

                return $storage->addFile($tempFile, $folder, $parsed['fileName']);
            },
        );
        $this->fileUploadService->applyMetadata($newFile, $metadata);

        return $this->buildResult('created', $newFile, $metadata);
    }

    /**
     * @param array{storageUid: int, folderPath: string, fileName: string} $parsed
     * @param array<string, string> $metadata
     */
    private function updateMetadataOnly(ResourceStorage $storage, array $parsed, array $metadata): CallToolResult
    {
        $folderPath = $parsed['folderPath'];
        if (!$storage->hasFolder($folderPath)) {
            throw new ValidationException(["Folder not found: {$parsed['storageUid']}:{$folderPath}"]);
        }
        $folder = $storage->getFolder($folderPath);

        if (!$folder->hasFile($parsed['fileName'])) {
            throw new ValidationException([
                "File not found: {$parsed['storageUid']}:{$folderPath}{$parsed['fileName']}. "
                . 'To update metadata, the file must already exist.',
            ]);
        }

        $file = $this->getExistingFile($storage, $folder, $parsed['fileName']);
        $this->fileUploadService->applyMetadata($file, $metadata);

        return $this->buildResult('metadata_updated', $file, $metadata);
    }

    /**
     * @param array<string, string> $metadata
     */
    private function buildResult(string $action, File $file, array $metadata): CallToolResult
    {
        $result = [
            'action' => $action,
            'identifier' => $file->getCombinedIdentifier(),
            'uid' => $file->getUid(),
            'size' => $file->getSize(),
        ];

        if ($metadata !== []) {
            $result['metadata'] = $metadata;
        }

        return $this->createJsonResult($result);
    }

    private function validateExtension(string $fileName): void
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext === '') {
            throw new ValidationException(['Filename must have an extension (e.g. .txt, .html, .json).']);
        }

        /** @var mixed $confVars */
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sysConfig = is_array($confVars) && is_array($confVars['SYS'] ?? null)
            ? $confVars['SYS']
            : [];
        // SVG is intentionally NOT in the default list — it can carry inline
        // <script> and trigger stored XSS when served from fileadmin/. Operators
        // who need SVG uploads should sanitize via `TYPO3\CMS\Core\Resource\
        // Security\SvgSanitizer` and add `svg` to $TYPO3_CONF_VARS[SYS][textfile_ext]
        // explicitly.
        $configuredTextExtensions = is_string($sysConfig['textfile_ext'] ?? null)
            ? $sysConfig['textfile_ext']
            : 'txt,ts,typoscript,html,htm,css,tmpl,js,sql,xml,csv,xlf,yaml,yml,md,rst,json';
        $textExtensions = GeneralUtility::trimExplode(
            ',',
            $configuredTextExtensions,
            true,
        );

        if (!in_array($ext, $textExtensions, true)) {
            throw new ValidationException([
                "Extension \".{$ext}\" is not allowed for text file creation. "
                . 'Allowed: ' . implode(', ', $textExtensions) . '.',
            ]);
        }
    }

    private function getExistingFile(ResourceStorage $storage, Folder $folder, string $fileName): File
    {
        $file = $storage->getFileInFolder($fileName, $folder);
        if (!$file instanceof File) {
            throw new ValidationException([
                "The target file \"{$fileName}\" could not be resolved as a writable TYPO3 file.",
            ]);
        }

        return $file;
    }
}
