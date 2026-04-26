<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\File\UploadFileFromUrlTool;
use Hn\McpServer\Service\FileReferenceAttachmentService;
use Hn\McpServer\Service\McpFileSandboxService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileType;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Stage images into the MCP sandbox, optionally run TYPO3 FAL image processing (CropScaleMask),
 * then attach them to a TCA file field as sys_file_reference rows (workspace-aware).
 */
final class AttachImageTool extends AbstractRecordTool
{
    private const ALLOWED_OUTPUT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly FileReferenceAttachmentService $fileReferenceAttachmentService,
        private readonly McpFileSandboxService $fileSandboxService,
        private readonly ResourceFactory $resourceFactory,
        private readonly StorageRepository $storageRepository,
        private readonly UploadFileFromUrlTool $uploadFileFromUrlTool,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'Add one or more images to a TCA file field on an existing record. '
                . 'Images are always staged in the MCP file sandbox first: either provide sys_file_uid of a file already in the sandbox, '
                . 'or pass a public https URL (for example a direct Unsplash image URL) — the same secure server-side download as UploadFileFromUrl applies. '
                . 'Optional transforms use TYPO3\'s native FAL Image.CropScaleMask processing (max/min dimensions, crop JSON, output fileExtension for format conversion). '
                . 'Use renditions to generate multiple processed sizes (each becomes a separate sandbox file and file reference). '
                . 'mode "append" keeps existing references on the field; "replace" sets the field to only the new references. '
                . 'For simple attachment without processing, prefer this tool over hand-building WriteTable file arrays.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'Target table (must contain a TCA type=file field).',
                    ],
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'Live record UID (same stable UID as other record tools).',
                    ],
                    'field' => [
                        'type' => 'string',
                        'description' => 'TCA file field name (e.g. image, assets, media).',
                    ],
                    'source' => [
                        'type' => 'object',
                        'description' => 'Exactly one of: public https url, or sys_file_uid inside the MCP sandbox.',
                        'properties' => [
                            'url' => [
                                'type' => 'string',
                                'description' => 'https URL to download into the sandbox (SSRF-protected; Unsplash direct image URLs work).',
                            ],
                            'sys_file_uid' => [
                                'type' => 'integer',
                                'description' => 'Existing FAL file UID; file must lie within the configured MCP sandbox.',
                            ],
                        ],
                    ],
                    'transform' => [
                        'type' => 'object',
                        'description' => 'Single TYPO3 CropScaleMask processing configuration. Omitted = attach original pixel data.',
                        'properties' => [
                            'maxWidth' => ['type' => 'integer'],
                            'maxHeight' => ['type' => 'integer'],
                            'minWidth' => ['type' => 'integer'],
                            'minHeight' => ['type' => 'integer'],
                            'width' => ['type' => 'string', 'description' => 'Width instruction (e.g. "800" or "800m")'],
                            'height' => ['type' => 'string', 'description' => 'Height instruction'],
                            'crop' => ['type' => 'string', 'description' => 'Crop as JSON string or legacy comma-separated values (TYPO3 crop area)'],
                            'fileExtension' => [
                                'type' => 'string',
                                'description' => 'Target extension for processed output (jpg, webp, png, …). Uses TYPO3 image processing.',
                            ],
                        ],
                    ],
                    'renditions' => [
                        'type' => 'array',
                        'description' => 'If non-empty, each entry is a transform like "transform"; the source is processed once per entry. Ignores "transform" when set.',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                        ],
                    ],
                    'reference' => [
                        'type' => 'object',
                        'description' => 'Optional sys_file_reference metadata applied to every attachment in this call.',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'alternative' => ['type' => 'string'],
                            'link' => ['type' => 'string'],
                            'crop' => ['type' => 'string'],
                            'autoplay' => ['type' => 'boolean'],
                            'showinpreview' => ['type' => 'boolean'],
                        ],
                    ],
                    'mode' => [
                        'type' => 'string',
                        'description' => 'append = keep existing file references; replace = only new references',
                        'enum' => ['append', 'replace'],
                        'default' => 'append',
                    ],
                ],
                'required' => ['table', 'uid', 'field', 'source'],
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
        $table = is_string($params['table'] ?? null) ? $params['table'] : '';
        $uid = isset($params['uid']) && is_numeric($params['uid']) ? (int)$params['uid'] : 0;
        $field = is_string($params['field'] ?? null) ? $params['field'] : '';
        $source = is_array($params['source'] ?? null) ? $params['source'] : [];
        $mode = is_string($params['mode'] ?? null) ? $params['mode'] : 'append';
        $reference = is_array($params['reference'] ?? null) ? $params['reference'] : [];

        if ($table === '' || $uid <= 0 || $field === '') {
            throw new ValidationException(['Parameters "table", "uid", and "field" are required.']);
        }

        if ($mode !== 'append' && $mode !== 'replace') {
            throw new ValidationException(['Parameter "mode" must be "append" or "replace".']);
        }

        $this->ensureTableAccess($table, 'write');

        $fieldConfig = $this->tableAccessService->getFieldConfig($table, $field);
        $fieldOptions = $fieldConfig !== null && isset($fieldConfig['config']) && is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
        if (($fieldOptions['type'] ?? null) !== 'file') {
            throw new ValidationException(['Field "' . $field . '" is not a TCA file field on table "' . $table . '".']);
        }

        if (!$this->tableAccessService->canAccessField($table, $field)) {
            throw new ValidationException(['Field "' . $field . '" is not accessible.']);
        }

        $url = is_string($source['url'] ?? null) ? trim($source['url']) : '';
        $sysFileUid = isset($source['sys_file_uid']) && is_numeric($source['sys_file_uid']) ? (int)$source['sys_file_uid'] : 0;

        if (($url === '' && $sysFileUid <= 0) || ($url !== '' && $sysFileUid > 0)) {
            throw new ValidationException(['Source must include exactly one of "url" or "sys_file_uid".']);
        }

        $workspaceUid = $this->resolveToWorkspaceUid($table, $uid);
        $record = BackendUtility::getRecord($table, $workspaceUid);
        if (!is_array($record)) {
            return $this->createErrorResult('Record not found or not accessible (uid=' . $uid . ').');
        }

        $pid = is_numeric($record['pid'] ?? null) ? (int)$record['pid'] : 0;

        $baseFile = $url !== ''
            ? $this->resolveFileFromUrl($url)
            : $this->resolveFileFromUid($sysFileUid);

        if (!$baseFile->isType(FileType::IMAGE)) {
            throw new ValidationException(['The resolved file is not an image (FAL type image).']);
        }

        $this->fileSandboxService->assertFileAllowed($baseFile);

        $renditions = is_array($params['renditions'] ?? null) ? $params['renditions'] : [];
        $singleTransform = is_array($params['transform'] ?? null) ? $params['transform'] : [];

        $transforms = [];
        if ($renditions !== []) {
            foreach ($renditions as $item) {
                if (is_array($item)) {
                    $transforms[] = $item;
                }
            }
            if ($transforms === []) {
                throw new ValidationException(['Parameter "renditions" must contain at least one object.']);
            }
        } elseif ($singleTransform !== []) {
            $transforms[] = $singleTransform;
        } else {
            $transforms[] = [];
        }

        $fileItems = [];
        foreach ($transforms as $transform) {
            $file = $this->applyTransform($baseFile, $transform);
            $this->fileSandboxService->assertFileAllowed($file);
            $fileItems[] = $this->mergeReferenceMetadata($file->getUid(), $reference);
        }

        $append = $mode === 'append';

        $this->fileReferenceAttachmentService->attachFilesToField(
            $table,
            $workspaceUid,
            $pid,
            $field,
            $fileItems,
            $append,
        );

        return $this->createJsonResult([
            'table' => $table,
            'uid' => $uid,
            'field' => $field,
            'attachedSysFileUids' => array_map(
                static fn(array $item): int => isset($item['uid']) && is_numeric($item['uid']) ? (int)$item['uid'] : 0,
                $fileItems,
            ),
            'source' => $url !== '' ? ['url' => $url] : ['sys_file_uid' => $sysFileUid],
            'mode' => $mode,
        ]);
    }

    private function resolveFileFromUrl(string $url): File
    {
        $result = $this->uploadFileFromUrlTool->execute([
            'url' => $url,
        ]);

        $firstContent = $result->content[0] ?? null;
        if ($result->isError) {
            $message = $firstContent instanceof TextContent ? (string)$firstContent->text : 'Download failed.';
            throw new ValidationException([$message]);
        }

        $json = $firstContent instanceof TextContent ? json_decode((string)$firstContent->text, true) : null;
        if (!is_array($json) || !isset($json['uid']) || !is_numeric($json['uid'])) {
            throw new ValidationException(['Could not resolve uploaded file UID from URL download.']);
        }

        return $this->resourceFactory->getFileObject((int)$json['uid']);
    }

    private function resolveFileFromUid(int $uid): File
    {
        $file = $this->resourceFactory->getFileObject($uid);
        $this->fileSandboxService->assertFileAllowed($file);

        return $file;
    }

    /**
     * @param array<string, mixed> $transform
     */
    private function applyTransform(File $file, array $transform): File
    {
        $config = $this->buildCropScaleMaskConfiguration($transform);
        if ($config === []) {
            return $file;
        }

        $processed = $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, $config);

        if ($processed->usesOriginalFile()) {
            return $file;
        }

        return $this->materializeProcessedFileInSandbox($file, $processed);
    }

    /**
     * @param array<string, mixed> $transform
     * @return array<string, mixed>
     */
    private function buildCropScaleMaskConfiguration(array $transform): array
    {
        $cfg = [];

        foreach (['maxWidth', 'maxHeight', 'minWidth', 'minHeight'] as $key) {
            if (isset($transform[$key]) && is_numeric($transform[$key])) {
                $cfg[$key] = (int)$transform[$key];
            }
        }

        if (isset($transform['width']) && (is_string($transform['width']) || is_numeric($transform['width']))) {
            $cfg['width'] = (string)$transform['width'];
        }
        if (isset($transform['height']) && (is_string($transform['height']) || is_numeric($transform['height']))) {
            $cfg['height'] = (string)$transform['height'];
        }

        if (isset($transform['crop']) && is_string($transform['crop']) && $transform['crop'] !== '') {
            $cfg['crop'] = $transform['crop'];
        }

        if (isset($transform['fileExtension']) && is_string($transform['fileExtension']) && $transform['fileExtension'] !== '') {
            $ext = strtolower(ltrim($transform['fileExtension'], '.'));
            if (!in_array($ext, self::ALLOWED_OUTPUT_EXTENSIONS, true)) {
                throw new ValidationException([
                    'Invalid fileExtension "' . $transform['fileExtension'] . '". Allowed: '
                    . implode(', ', self::ALLOWED_OUTPUT_EXTENSIONS),
                ]);
            }
            $cfg['fileExtension'] = $ext === 'jpeg' ? 'jpg' : $ext;
        }

        return $cfg;
    }

    private function materializeProcessedFileInSandbox(File $original, ProcessedFile $processed): File
    {
        $localPath = $processed->getForLocalProcessing(false);
        if (!is_file($localPath)) {
            throw new ValidationException(['Image processing did not produce a local file.']);
        }

        $ext = strtolower($processed->getExtension() ?: $original->getExtension() ?: 'jpg');
        $target = $this->fileSandboxService->resolveUploadTarget('processed/render-' . bin2hex(random_bytes(6)) . '.' . $ext);

        $storage = $this->resolveStorage($target['storageUid']);
        $folder = $this->ensureFolder($storage, $target['folderPath']);
        $storedName = $this->fileSandboxService->buildStoredUploadFileName('render.' . $ext);

        try {
            $newFile = $storage->addFile($localPath, $folder, $storedName);
        } catch (\Throwable $e) {
            throw new ValidationException(['Failed to store processed image in sandbox: ' . $e->getMessage()]);
        }

        $this->fileSandboxService->assertFileAllowed($newFile);

        return $newFile;
    }

    private function resolveStorage(int $storageUid): ResourceStorage
    {
        $storage = $this->storageRepository->findByUid($storageUid);
        if ($storage === null || !$storage->isOnline()) {
            throw new ValidationException(["Storage {$storageUid} not found or offline."]);
        }
        if (!$storage->isWritable()) {
            throw new ValidationException(["Storage {$storageUid} is read-only."]);
        }

        return $storage;
    }

    private function ensureFolder(ResourceStorage $storage, string $folderPath): Folder
    {
        if ($storage->hasFolder($folderPath)) {
            return $storage->getFolder($folderPath);
        }

        try {
            return $storage->createFolder($folderPath);
        } catch (InsufficientFolderAccessPermissionsException) {
            throw new ValidationException(["Permission denied: Cannot create folder \"{$folderPath}\"."]);
        } catch (ExistingTargetFolderException) {
            return $storage->getFolder($folderPath);
        }
    }

    /**
     * @param array<string, mixed> $reference
     * @return array<string, mixed>
     */
    private function mergeReferenceMetadata(int $fileUid, array $reference): array
    {
        $item = ['uid' => $fileUid];
        foreach (['title', 'description', 'alternative', 'link', 'crop', 'autoplay', 'showinpreview'] as $key) {
            if (!array_key_exists($key, $reference)) {
                continue;
            }
            $val = $reference[$key];
            if (is_string($val) || is_numeric($val)) {
                $item[$key] = $val;
            } elseif (is_bool($val) && in_array($key, ['autoplay', 'showinpreview'], true)) {
                $item[$key] = $val;
            }
        }

        return $item;
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('Backend user context not initialized');
        }

        return $backendUser;
    }

    protected function getCurrentWorkspaceId(): int
    {
        return $this->getBackendUser()->workspace;
    }

    /**
     * @see WriteTableTool::resolveToWorkspaceUid
     */
    protected function resolveToWorkspaceUid(string $table, int $liveUid): int
    {
        $currentWorkspace = $this->getCurrentWorkspaceId();
        if ($currentWorkspace === 0) {
            return $liveUid;
        }

        $versionUid = $this->workspaceContextService->findWorkspaceVersionRowUid($table, $liveUid, $currentWorkspace);
        if ($versionUid !== null) {
            return $versionUid;
        }

        $record = BackendUtility::getRecord($table, $liveUid);
        if (!$record) {
            return $liveUid;
        }

        BackendUtility::workspaceOL($table, $record);
        if (isset($record['uid']) && (int)$record['uid'] !== $liveUid) {
            return (int)$record['uid'];
        }

        return $liveUid;
    }
}
