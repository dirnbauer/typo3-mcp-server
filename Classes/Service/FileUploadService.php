<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Exception\ValidationException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;
use TYPO3\CMS\Core\Resource\Service\ResourceConsistencyService;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Validation\ResultException;

/**
 * Upload rules shared by the sandboxed UploadFile / UploadFileFromUrl tools
 * and the pre-signed /mcp_upload endpoint.
 *
 * Files are deliberately create-only through MCP: physical files are not
 * workspace-versioned in TYPO3, so overwriting or deleting them would be
 * immediately live and irreversible. Stored file names are randomized by the
 * sandbox, identical content is deduplicated, and files that could be
 * executed - by the server or by a visitor's browser - or that reconfigure
 * the web server are refused independently of TYPO3's configurable
 * fileDenyPattern.
 */
final readonly class FileUploadService
{
    public const UPLOAD_TOKEN_LIFETIME = 900;

    private const DEFAULT_MAX_FILE_SIZE_MB = 500;
    private const TOKEN_TABLE = 'tx_mcpserver_upload_tokens';

    /**
     * Formats a browser would execute in the site's origin (stored XSS).
     * TYPO3's fileDenyPattern does not cover these - it only guards against
     * server-side execution.
     */
    private const BROWSER_EXECUTABLE_EXTENSIONS = ['htm', 'html', 'xhtml', 'js', 'mjs', 'svgz', 'swf', 'hta'];

    /**
     * Formats the server itself could execute. TYPO3's fileDenyPattern already
     * blocks these, but it is a configurable setting an integrator can loosen,
     * so uploads coming in through MCP refuse them independently.
     */
    private const SERVER_EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps', 'phpsh', 'phtml', 'phtm', 'pht', 'phar',
        'shtml', 'shtm', 'cgi', 'pl', 'py', 'rb', 'sh', 'htaccess',
    ];

    /**
     * Exact file names that reconfigure the server rather than being executed
     * themselves. ".user.ini" is the notable one: with PHP running as CGI/FPM
     * it sets per-directory PHP options such as auto_prepend_file, and unlike
     * ".htaccess" it is NOT part of TYPO3's fileDenyPattern.
     */
    private const DENIED_FILE_NAMES = ['.user.ini', '.htaccess', '.htpasswd', 'web.config'];

    public function __construct(
        private ConnectionPool $connectionPool,
        private ExtensionConfiguration $extensionConfiguration,
        private StorageRepository $storageRepository,
        private FileIndexRepository $fileIndexRepository,
        private ResourceFactory $resourceFactory,
        private McpFileSandboxService $fileSandboxService,
        private TableAccessService $tableAccessService,
        private ResourceConsistencyService $resourceConsistencyService,
    ) {}

    public function resolveStorage(int $storageUid): ResourceStorage
    {
        $storage = $this->storageRepository->findByUid($storageUid);
        if ($storage === null || !$storage->isOnline()) {
            throw new ValidationException(["Storage {$storageUid} not found or offline."]);
        }
        if (!$storage->isWritable()) {
            throw new ValidationException(["Storage {$storageUid} ({$storage->getName()}) is read-only."]);
        }

        return $storage;
    }

    public function ensureFolder(ResourceStorage $storage, string $folderPath): Folder
    {
        if ($storage->hasFolder($folderPath)) {
            return $storage->getFolder($folderPath);
        }

        try {
            return $storage->createFolder($folderPath);
        } catch (InsufficientFolderWritePermissionsException|InsufficientFolderAccessPermissionsException) {
            throw new ValidationException(["Permission denied: Cannot create folder \"{$folderPath}\"."]);
        } catch (ExistingTargetFolderException) {
            return $storage->getFolder($folderPath);
        }
    }

    /**
     * Refuse files that could be executed - by the server or by a visitor's
     * browser - or that reconfigure the server. Deliberately independent of
     * TYPO3's configurable fileDenyPattern and of the mime-consistency check,
     * so the guarantee holds no matter how an installation is configured.
     */
    public function assertFileNameIsAllowed(string $fileName): void
    {
        $fileName = trim($fileName);
        $lowerName = strtolower($fileName);
        if ($fileName === '' || in_array($lowerName, ['.', '..'], true)) {
            throw new ValidationException(['Path must include a valid filename.']);
        }
        if (in_array($lowerName, self::DENIED_FILE_NAMES, true)) {
            throw new ValidationException([
                'The file name "' . $fileName . '" is not allowed because a file with that name reconfigures '
                . 'the web server or PHP.',
            ]);
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new ValidationException(['Uploaded files must include a filename extension.']);
        }
        if (in_array($extension, self::SERVER_EXECUTABLE_EXTENSIONS, true)) {
            throw new ValidationException([
                'Files with the extension ".' . $extension . '" are not allowed because they could be executed '
                . 'on the server.',
            ]);
        }
        if (in_array($extension, self::BROWSER_EXECUTABLE_EXTENSIONS, true)) {
            throw new ValidationException([
                'Files with the extension ".' . $extension . '" are not allowed because they could run '
                . 'script code in the browser when served from the file storage.',
            ]);
        }

        // "evil.php.jpg" and friends: on servers that map by any extension in
        // the name, an inner executable extension is enough.
        foreach (explode('.', $lowerName) as $part) {
            if (in_array($part, self::SERVER_EXECUTABLE_EXTENSIONS, true)) {
                throw new ValidationException([
                    'The file name "' . $fileName . '" is not allowed because it contains the potentially '
                    . 'executable extension ".' . $part . '".',
                ]);
            }
        }

        if (!GeneralUtility::makeInstance(FileNameValidator::class)->isValid($fileName)) {
            throw new ValidationException([
                sprintf('File extension "%s" is not allowed.', $extension),
            ]);
        }
    }

    /**
     * Reserve a randomized stored file name inside the folder. The requested
     * name is validated by assertFileNameIsAllowed() first.
     */
    public function reserveStoredFileName(Folder $folder, string $requestedFileName): string
    {
        $this->assertFileNameIsAllowed($requestedFileName);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $this->fileSandboxService->buildStoredUploadFileName($requestedFileName);
            if (!$folder->hasFile($candidate)) {
                return $candidate;
            }
        }

        throw new ValidationException(['Could not reserve a unique upload filename. Please try again.']);
    }

    /**
     * Maximum accepted file size in bytes for base64 payloads, URL downloads,
     * and pre-signed uploads; configurable via the extension setting
     * "maxFileSizeMb".
     */
    public function getMaxFileBytes(): int
    {
        $configured = 0;
        try {
            $value = $this->extensionConfiguration->get('mcp_server', 'maxFileSizeMb');
            if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
                $configured = (int)$value;
            }
        } catch (\Throwable) {
            $configured = 0;
        }

        return ($configured > 0 ? $configured : self::DEFAULT_MAX_FILE_SIZE_MB) * 1024 * 1024;
    }

    public function getMaxFileSizeMb(): int
    {
        return intdiv($this->getMaxFileBytes(), 1024 * 1024);
    }

    public function assertWithinSizeLimit(int $bytes): void
    {
        if ($bytes > $this->getMaxFileBytes()) {
            throw new ValidationException([$this->buildSizeLimitMessage()]);
        }
    }

    public function buildSizeLimitMessage(): string
    {
        return sprintf(
            'File exceeds maximum size of %d MiB (extension setting "maxFileSizeMb").',
            $this->getMaxFileSizeMb(),
        );
    }

    /**
     * Store a local temp file under the reserved name: identical content that
     * already exists in the storage (and is visible to the current user and
     * the file sandbox) is returned instead of creating a copy, which also
     * makes retries after timeouts idempotent.
     *
     * @return array{file: File, deduplicated: bool}
     */
    public function storeFile(string $tempPath, string $storedFileName, Folder $folder): array
    {
        $storage = $folder->getStorage();

        $sha1 = sha1_file($tempPath);
        if ($sha1 !== false) {
            $existingFile = $this->findIdenticalFile($storage, $sha1);
            if ($existingFile !== null) {
                return ['file' => $existingFile, 'deduplicated' => true];
            }
        }

        try {
            // The extension itself was vetted by assertFileNameIsAllowed(); the
            // consistency service (also run by addFile()) additionally rejects
            // content that does not match the extension/mime type. Validate
            // explicitly so the failure surfaces as a clear ValidationException.
            $this->resourceConsistencyService->validate($storage, $tempPath, $storedFileName);
            $file = $storage->addFile($tempPath, $folder, $storedFileName);
        } catch (ResultException) {
            throw new ValidationException([
                'The file was rejected because its content does not match the file extension "'
                . pathinfo($storedFileName, PATHINFO_EXTENSION) . '". '
                . 'Make sure the payload or URL actually delivers this file type.',
            ]);
        } catch (ExistingTargetFileNameException) {
            throw new ValidationException(['Could not reserve a unique upload filename. Please try again.']);
        }

        // Uploads can be rewritten while being stored (e.g. the core SVG
        // sanitizer), so the pre-upload hash above misses duplicates of such
        // files. Re-check with the hash of the stored content and drop the
        // fresh copy when it turns out to be one.
        $existingFile = $this->findIdenticalFile($storage, $file->getSha1(), $file->getUid());
        if ($existingFile !== null) {
            try {
                $storage->deleteFile($file);
                return ['file' => $existingFile, 'deduplicated' => true];
            } catch (\Throwable) {
                // The user may create but not delete files; keep the copy then.
            }
        }

        return ['file' => $file, 'deduplicated' => false];
    }

    /**
     * Find a non-missing file with identical content (same SHA1) in the storage
     * via TYPO3's file index. Non-admin users only get matches within their file
     * mounts, and the strict file sandbox only matches files inside the sandbox,
     * otherwise the dedupe would leak (and hand out) files the caller may not
     * access through MCP.
     */
    public function findIdenticalFile(ResourceStorage $storage, string $sha1, int $excludeFileUid = 0): ?File
    {
        if ($sha1 === '') {
            return null;
        }
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            // Without a backend user there is no way to judge access: never
            // hand out an existing file in that case.
            return null;
        }
        $isAdmin = $backendUser->isAdmin();

        foreach ($this->fileIndexRepository->findByContentHash($sha1) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($this->toInt($row['storage'] ?? 0) !== $storage->getUid() || $this->toInt($row['missing'] ?? 0) === 1) {
                continue;
            }
            $uid = $this->toInt($row['uid'] ?? 0);
            if ($uid <= 0 || $uid === $excludeFileUid) {
                continue;
            }
            if (!$isAdmin && !$this->tableAccessService->canAccessFileUid($uid)) {
                continue;
            }
            try {
                $file = $this->resourceFactory->getFileObject($uid, $row);
            } catch (\Throwable) {
                continue;
            }
            if (!$this->fileSandboxService->isUnrestricted()) {
                try {
                    $this->fileSandboxService->assertFileAllowed($file);
                } catch (ValidationException) {
                    continue;
                }
            }
            if (!$file->exists()) {
                continue;
            }

            return $file;
        }

        return null;
    }

    /**
     * Sniff a downloaded or uploaded payload for an HTML document. Deliberately
     * content-based rather than header-based: servers mislabel real files as
     * text/html often enough that a Content-Type check alone would reject
     * valid downloads.
     */
    public function looksLikeHtmlDocument(string $tempPath): bool
    {
        $handle = @fopen($tempPath, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = fread($handle, 1024);
        fclose($handle);
        if (!is_string($head) || $head === '') {
            return false;
        }

        // Skip a UTF-8 BOM and leading whitespace, then look for the markers a
        // browser would use to decide it is dealing with a document.
        $head = strtolower(ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? ''));
        foreach (['<!doctype html', '<html', '<head', '<body'] as $marker) {
            if (str_starts_with($head, $marker)) {
                return true;
            }
        }

        // Documents that open with a comment or an XML declaration
        return preg_match('/^(<\?xml[^>]*\?>|<!--.{0,200}?-->)\s*(<!doctype html|<html)/s', $head) === 1;
    }

    /**
     * Parse a file name from a Content-Disposition header, supporting both the
     * RFC 5987 filename*= form and the plain filename= form.
     */
    public function extractFileNameFromContentDisposition(string $header): ?string
    {
        if ($header === '') {
            return null;
        }
        if (preg_match("/filename\\*\\s*=\\s*utf-8''([^;]+)/i", $header, $matches) === 1) {
            $name = basename(rawurldecode(trim($matches[1], " \t\"")));
            return $name !== '' ? $name : null;
        }
        if (preg_match('/filename\s*=\s*"([^"]*)"/', $header, $matches) === 1
            || preg_match('/filename\s*=\s*([^;]+)/', $header, $matches) === 1
        ) {
            $name = basename(trim($matches[1], " \t\""));
            return $name !== '' ? $name : null;
        }

        return null;
    }

    /**
     * Build the shared response data for a stored file.
     *
     * @return array<string, mixed>
     */
    public function describeFile(File $file, string $originalFileName, bool $deduplicated): array
    {
        $data = [
            'identifier' => $file->getCombinedIdentifier(),
            'uid' => $file->getUid(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
            'originalFilename' => $originalFileName,
            'storedFilename' => $file->getName(),
            'deduplicated' => $deduplicated,
        ];
        if ($deduplicated) {
            $data['note'] = 'A file with identical content already exists at "' . $file->getCombinedIdentifier()
                . '" (possibly in a different folder than requested); it is returned instead of creating a duplicate.';
        }

        return $data;
    }

    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int)$value : 0;
    }

    // -----------------------------------------------------------------
    // Pre-signed upload tokens
    // -----------------------------------------------------------------

    /**
     * Create a single-use upload token bound to a backend user, a sandbox
     * validated target folder and (optionally) an exact file name. Only the
     * hash of the token is stored.
     *
     * @return array{token: string, validUntil: int}
     */
    public function createUploadToken(int $beUserId, string $targetFolder, string $fileName = ''): array
    {
        if ($beUserId <= 0) {
            throw new ValidationException(['Pre-signed uploads require an authenticated backend user.']);
        }
        if (preg_match('#^\d+:/#', $targetFolder) !== 1) {
            throw new ValidationException(['The upload target must be a combined folder identifier.']);
        }
        $fileName = basename(trim($fileName));
        if ($fileName !== '') {
            $this->assertFileNameIsAllowed($fileName);
        }

        $token = bin2hex(random_bytes(32));
        $now = time();
        $validUntil = $now + self::UPLOAD_TOKEN_LIFETIME;

        $connection = $this->connectionPool->getConnectionForTable(self::TOKEN_TABLE);

        // Lazy garbage collection: drop tokens that expired more than a day ago
        $gcQuery = $connection->createQueryBuilder();
        $gcQuery->delete(self::TOKEN_TABLE)
            ->where($gcQuery->expr()->lt('expires', $gcQuery->createNamedParameter($now - 86400, Connection::PARAM_INT)))
            ->executeStatement();

        $connection->insert(self::TOKEN_TABLE, [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'token' => hash('sha256', $token),
            'be_user_uid' => $beUserId,
            'target_folder' => rtrim($targetFolder, '/') . '/',
            'file_name' => $fileName,
            'expires' => $validUntil,
            'used' => 0,
        ]);

        return ['token' => $token, 'validUntil' => $validUntil];
    }

    /**
     * Validate and atomically consume a plain upload token. Returns the token
     * data exactly once: parallel or repeated requests with the same token get
     * null, as do unknown, expired, or already used tokens.
     *
     * The token is consumed BEFORE the upload runs, so a failed upload burns
     * it too - a leaked token is a single attempt, not a 15-minute upload
     * permit. Clients simply request a fresh URL to retry.
     *
     * @return array{uid: int, be_user_uid: int, target_folder: string, file_name: string}|null
     */
    public function consumeUploadToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $connection = $this->connectionPool->getConnectionForTable(self::TOKEN_TABLE);
        $row = $connection->select(['*'], self::TOKEN_TABLE, ['token' => hash('sha256', $token)])->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }

        $uid = $this->toInt($row['uid'] ?? 0);
        $used = $this->toInt($row['used'] ?? 0);
        $expires = $this->toInt($row['expires'] ?? 0);
        if ($uid <= 0 || $used !== 0 || $expires < time()) {
            return null;
        }

        $affected = $connection->update(
            self::TOKEN_TABLE,
            ['used' => time(), 'tstamp' => time()],
            ['uid' => $uid, 'used' => 0],
        );
        if ($affected !== 1) {
            return null;
        }

        return [
            'uid' => $uid,
            'be_user_uid' => $this->toInt($row['be_user_uid'] ?? 0),
            'target_folder' => is_string($row['target_folder'] ?? null) ? $row['target_folder'] : '',
            'file_name' => is_string($row['file_name'] ?? null) ? $row['file_name'] : '',
        ];
    }
}
