<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\BackendUserContextService;
use Hn\McpServer\Service\FileMetadataIndexService;
use Hn\McpServer\Service\FileUploadService;
use Hn\McpServer\Service\SiteInformationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientUserPermissionsException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Target of the pre-signed upload URLs handed out by the UploadFile MCP tool.
 *
 * Accepts the raw file bytes as PUT/POST body (curl -T style) or a multipart
 * form upload, authenticated by a single-use token bound to a backend user
 * and a sandbox-validated target folder. This is the "out-of-band upload"
 * pattern for MCP: binary data never travels through the model's context -
 * the client's harness uploads directly and gets the created sys_file back
 * as JSON.
 */
final readonly class FileUploadEndpoint
{
    use CorsHeadersTrait;

    public function __construct(
        private LoggerInterface $logger,
        private FileUploadService $fileUploadService,
        private BackendUserContextService $backendUserContext,
        private FileMetadataIndexService $fileMetadataIndexService,
        private SiteInformationService $siteInformationService,
        private AuthenticationRateLimiter $authenticationRateLimiter,
        private TcaFactory $tcaFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->authenticationRateLimiter->handle(
            $request,
            AuthenticationRateLimiter::UPLOAD,
            fn(): ResponseInterface => $this->handleRequest($request),
        );
    }

    private function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $tempPath = null;
        try {
            $corsRejection = $this->rejectDisallowedCorsRequest($request);
            if ($corsRejection instanceof ResponseInterface) {
                return $corsRejection;
            }
            if ($request->getMethod() === 'OPTIONS') {
                return $this->handlePreflightRequest($request);
            }
            if (!in_array($request->getMethod(), ['PUT', 'POST'], true)) {
                return $this->jsonError($request, 'Use HTTP PUT (or POST) with the raw file bytes as request body.', 405)
                    ->withHeader('Allow', 'PUT, POST, OPTIONS');
            }

            $tokenRow = $this->fileUploadService->consumeUploadToken($this->extractToken($request));
            if ($tokenRow === null) {
                return $this->jsonError($request, 'Invalid, expired, or already used upload token.', 401);
            }

            // Same door as /mcp: a token-authenticated request impersonates the
            // backend user without the regular authentication flow, so the
            // stored user configuration (uc) must be restored here as well.
            if ($this->backendUserContext->initializeFromUserId($tokenRow['be_user_uid']) === null) {
                // Do not reveal whether the user exists, is disabled, or expired.
                return $this->jsonError($request, 'The backend user this upload token belongs to is not available.', 403);
            }
            $GLOBALS['TCA'] = $this->tcaFactory->get();
            $this->siteInformationService->setCurrentRequest($request);

            [$body, $clientFileName] = $this->extractUpload($request);

            // A file name fixed in the token wins: the token authorizes exactly
            // that upload, the request must not widen it to another file type.
            $fileName = $tokenRow['file_name'];
            if ($fileName === '') {
                $queryFileName = $request->getQueryParams()['fileName'] ?? '';
                $fileName = is_string($queryFileName) ? basename(trim($queryFileName)) : '';
            }
            if ($fileName === '' && $clientFileName !== null) {
                $fileName = $clientFileName;
            }
            if ($fileName === '') {
                $fileName = $this->fileUploadService->extractFileNameFromContentDisposition(
                    $request->getHeaderLine('Content-Disposition'),
                ) ?? '';
            }
            if ($fileName === '') {
                return $this->jsonError(
                    $request,
                    'No file name given. Pass it as ?fileName=<name.ext> query parameter or Content-Disposition header.',
                    400,
                );
            }

            // Before buffering or touching the storage: a rejected name should
            // cost neither disk space nor a freshly created folder.
            $this->fileUploadService->assertFileNameIsAllowed($fileName);

            $tempPath = $this->bufferToTempFile($body, $this->fileUploadService->getMaxFileBytes());

            [$storageUid, $folderPath] = $this->parseTargetFolder($tokenRow['target_folder']);
            $storage = $this->fileUploadService->resolveStorage($storageUid);
            $folder = $this->fileUploadService->ensureFolder($storage, $folderPath);
            $storedFileName = $this->fileUploadService->reserveStoredFileName($folder, $fileName);
            $stored = $this->fileUploadService->storeFile($tempPath, $storedFileName, $folder);
            if (!$stored['deduplicated']) {
                $this->fileMetadataIndexService->ensureImageMetadataForFile($stored['file']);
            }

            $data = ['action' => 'uploaded']
                + $this->fileUploadService->describeFile($stored['file'], $fileName, $stored['deduplicated'])
                + ['targetFolder' => $folder->getCombinedIdentifier()];

            return $this->addSecurityHeaders($this->addCorsHeaders(new JsonResponse($data, 201), $request));
        } catch (ValidationException $e) {
            return $this->jsonError($request, $e->getUserMessage(), 400);
        } catch (\LengthException $e) {
            return $this->jsonError($request, $e->getMessage(), 413);
        } catch (InsufficientFolderAccessPermissionsException|InsufficientFolderWritePermissionsException|InsufficientUserPermissionsException $e) {
            return $this->jsonError($request, 'No permission for the target folder: ' . $e->getMessage(), 403);
        } catch (\Throwable $e) {
            // Log the details, but do not leak exception messages (paths etc.)
            $this->logger->error('Pre-signed upload failed', ['exception' => $e]);
            return $this->jsonError($request, 'Upload failed due to an unexpected server error (see TYPO3 log).', 500);
        } finally {
            if ($tempPath !== null && file_exists($tempPath)) {
                // $tempPath always comes from TYPO3's tempnam(), never from request input.
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                unlink($tempPath);
            }
        }
    }

    /**
     * Preferred: Authorization header (query strings end up in server logs);
     * the ?token= query parameter is kept as fallback for clients that cannot
     * set headers.
     */
    private function extractToken(ServerRequestInterface $request): string
    {
        if (preg_match('/^Bearer[ \t]+([A-Za-z0-9\-._~+\/=]+)$/i', trim($request->getHeaderLine('Authorization')), $matches) === 1) {
            return $matches[1];
        }

        $queryToken = $request->getQueryParams()['token'] ?? '';

        return is_string($queryToken) ? trim($queryToken) : '';
    }

    /**
     * Get the upload source: a multipart file upload when present (browser
     * forms, curl -F), otherwise the raw request body (curl -T / PUT).
     *
     * @return array{0: StreamInterface, 1: string|null} stream and optional client file name
     */
    private function extractUpload(ServerRequestInterface $request): array
    {
        $uploadedFiles = $request->getUploadedFiles();
        if ($uploadedFiles !== []) {
            $first = reset($uploadedFiles);
            if (is_array($first)) {
                $first = reset($first);
            }
            if ($first instanceof UploadedFileInterface) {
                $clientName = $first->getClientFilename();
                $clientName = is_string($clientName) ? basename(trim($clientName)) : '';

                return [$first->getStream(), $clientName !== '' ? $clientName : null];
            }
        }

        return [$request->getBody(), null];
    }

    /**
     * Stream the upload into a temp file, enforcing the size limit.
     */
    private function bufferToTempFile(StreamInterface $body, int $maxBytes): string
    {
        $tempPath = GeneralUtility::tempnam('mcp_upload_');
        $handle = fopen($tempPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to create a temporary file for the upload.');
        }
        $bytes = 0;
        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            while (!$body->eof()) {
                $chunk = $body->read(65536);
                if ($chunk === '') {
                    break;
                }
                $bytes += strlen($chunk);
                if ($bytes > $maxBytes) {
                    throw new \LengthException($this->fileUploadService->buildSizeLimitMessage());
                }
                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }

        if ($bytes === 0) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use
            unlink($tempPath);
            throw new ValidationException(['The request body was empty - send the raw file bytes as PUT/POST body.']);
        }

        return $tempPath;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function parseTargetFolder(string $targetFolder): array
    {
        if (preg_match('#^(\d+):(/.*)$#', $targetFolder, $matches) !== 1) {
            throw new ValidationException(['The upload token does not reference a valid target folder.']);
        }

        return [(int)$matches[1], '/' . trim($matches[2], '/') . '/'];
    }

    private function jsonError(ServerRequestInterface $request, string $message, int $status): ResponseInterface
    {
        return $this->addSecurityHeaders($this->addCorsHeaders(
            new JsonResponse(['error' => $message], $status),
            $request,
        ));
    }
}
