<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\FileUploadService;
use Hn\McpServer\Service\McpFileSandboxService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Utility\BackendUserUtility;
use Mcp\Types\CallToolResult;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Upload a file into the MCP file sandbox.
 *
 * Two modes:
 *  - content_base64: the payload travels through the model context (small files)
 *  - no payload:     a pre-signed, single-use upload URL is returned so the
 *                    client can HTTP-PUT a local file directly to TYPO3
 *                    (/mcp_upload); binary data never enters the context
 */
final class UploadFileTool extends AbstractTool
{
    public function __construct(
        private readonly McpFileSandboxService $fileSandboxService,
        private readonly FileUploadService $fileUploadService,
        private readonly SiteBaseUrlResolver $baseUrlResolver,
        private readonly SiteInformationService $siteInformationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Upload a binary or text file into the MCP file sandbox. '
                . 'Send small files as "content_base64". Omit "content_base64" to receive a single-use pre-signed upload URL '
                . 'instead: the MCP client then sends the raw bytes of a local file via HTTP PUT to that URL '
                . '(e.g. with curl), so binary data never travels through the model context. '
                . 'All uploads are restricted to the configured sandbox root (default: fileadmin/mcp/). '
                . 'In local mode (DDEV / localUnsafeMode=on), combined identifiers may target any accessible FAL folder. '
                . 'When workspace upload subfolders are enabled, files are stored below a workspace-specific folder to reduce collisions with live content. '
                . 'Existing files are never overwritten; identical content that already exists is returned instead of creating a duplicate; '
                . 'executable and server-configuration files are refused. '
                . sprintf('Maximum file size: %d MiB (extension setting maxFileSizeMb).', $this->fileUploadService->getMaxFileSizeMb()),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Target file path inside the MCP file sandbox. '
                            . 'Use a relative path like "images/product-photo.png" or an absolute combined identifier inside the sandbox. '
                            . 'In local mode, combined identifiers may point outside the sandbox. '
                            . 'The folder path is respected, but the stored filename is randomized for security. '
                            . 'For the pre-signed flow a folder path ending with "/" is accepted; the file name is then supplied at upload time.',
                    ],
                    'content_base64' => [
                        'type' => 'string',
                        'description' => 'Base64-encoded file contents. Data URLs such as "data:image/png;base64,..." are also accepted. '
                            . 'Omit it to request a pre-signed upload URL for a file on the client machine.',
                    ],
                    'metadata' => [
                        'type' => 'object',
                        'description' => 'Optional metadata to set on the uploaded file (ignored for pre-signed uploads and deduplicated files).',
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
                'destructiveHint' => false,
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
        $path = is_string($params['path'] ?? null) ? trim($params['path']) : '';
        $contentBase64 = is_string($params['content_base64'] ?? null) ? trim($params['content_base64']) : '';
        $metadata = $this->fileUploadService->sanitizeMetadata($params['metadata'] ?? null);

        if ($path === '') {
            throw new ValidationException(['Parameter "path" is required. Use a relative path or a combined identifier inside the MCP file sandbox.']);
        }
        if ($contentBase64 === '') {
            return $this->createPresignedUploadResult($path);
        }

        $decodedContent = $this->decodeBase64Content($contentBase64);
        $this->fileUploadService->assertWithinSizeLimit(strlen($decodedContent));

        $target = $this->fileSandboxService->resolveUploadTarget($path);
        $stored = $this->fileUploadService->withTemporaryFile(
            function (string $tempFile) use ($decodedContent, $target, $metadata): array {
                if (file_put_contents($tempFile, $decodedContent) === false) {
                    throw new \RuntimeException('Failed to buffer the uploaded content on the server.');
                }

                return $this->fileUploadService->storeUpload(
                    $tempFile,
                    $target['storageUid'],
                    $target['folderPath'],
                    $target['fileName'],
                    $metadata,
                );
            },
        );

        return $this->createJsonResult(
            ['action' => 'uploaded']
            + $this->fileUploadService->describeUpload($target, $stored, $target['fileName'], $metadata),
        );
    }

    /**
     * Hand out a single-use upload URL the MCP client can PUT a local file to.
     * The target folder (and optionally the exact file name) is validated
     * against the sandbox now and bound to the token.
     */
    private function createPresignedUploadResult(string $path): CallToolResult
    {
        $folderOnly = str_ends_with($path, '/');
        $target = $this->fileSandboxService->resolveUploadTarget($folderOnly ? $path . 'upload.bin' : $path);
        $fileName = $folderOnly ? '' : $target['fileName'];
        if ($fileName !== '') {
            $this->fileUploadService->assertFileNameIsAllowed($fileName);
        }

        // Resolve the endpoint URL before minting a token: a relative upload
        // URL is unusable for the client, so fail hard instead (typically
        // stdio mode without a fully qualified site base).
        $endpointUrl = $this->resolveUploadEndpointUrl();

        $tokenData = $this->fileUploadService->createUploadToken(
            BackendUserUtility::getCurrentUserId(),
            $target['storageUid'] . ':' . $target['folderPath'],
            $fileName,
        );

        // The token travels as an Authorization header, not in the URL: query
        // strings end up in webserver and proxy logs.
        $curlFileName = $fileName !== '' ? $fileName : 'photo.jpg';
        $curlUrl = $endpointUrl . ($fileName === '' ? '?fileName=' . rawurlencode($curlFileName) : '');

        return $this->createJsonResult([
            'action' => 'presigned_upload',
            'uploadUrl' => $endpointUrl,
            'uploadToken' => $tokenData['token'],
            'method' => 'PUT',
            'targetFolder' => $target['storageUid'] . ':' . $target['folderPath'],
            'fileName' => $fileName !== '' ? $fileName : null,
            'validUntil' => date('c', $tokenData['validUntil']),
            'baseFolder' => $target['baseFolder'],
            'uploadFolder' => $target['uploadFolder'],
            'workspaceId' => $target['workspaceId'],
            'instructions' => 'Send the raw file bytes via HTTP PUT (or POST) to the uploadUrl, '
                . 'passing the uploadToken as bearer token, e.g.: '
                . "curl -sS -T '" . $curlFileName . "' -H 'Authorization: Bearer " . $tokenData['token'] . "' '" . $curlUrl . "'"
                . ($fileName === '' ? ' - replace the fileName query parameter with the actual file name including extension.' : '')
                . ' The token is single-use and expires at validUntil; a failed attempt consumes it, so request a fresh URL to retry. '
                . 'The endpoint responds with JSON containing the stored file (uid, identifier, storedFilename, ...); '
                . 'use that uid with WriteTable or AttachImage to reference the file from a record.',
        ]);
    }

    private function resolveUploadEndpointUrl(): string
    {
        $baseUrl = null;
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface && $request->getUri()->getHost() !== '') {
            $baseUrl = $this->baseUrlResolver->resolveFromRequest($request);
        } else {
            $baseUrl = $this->baseUrlResolver->getConfiguredBaseUrl();
        }

        $endpointUrl = $baseUrl !== null
            ? rtrim($baseUrl, '/') . '/mcp_upload'
            : $this->siteInformationService->makeAbsoluteUrl('/mcp_upload');

        if (!is_string($endpointUrl) || preg_match('#^https?://[^/]+#i', $endpointUrl) !== 1) {
            throw new ValidationException([
                'Cannot build an absolute upload URL because no public base URL of this TYPO3 instance is known '
                . '(no HTTP request context and no site with a fully qualified base URL). '
                . 'Configure a site base URL including scheme and host (or SYS.reverseProxyBaseUrl), '
                . 'or upload via "content_base64" or UploadFileFromUrl instead.',
            ]);
        }

        return $endpointUrl;
    }

    private function decodeBase64Content(string $contentBase64): string
    {
        $payload = trim($contentBase64);
        if (str_starts_with($payload, 'data:')) {
            $commaPosition = strpos($payload, ',');
            if ($commaPosition === false) {
                throw new ValidationException(['Invalid data URL. Expected a comma before the base64 payload.']);
            }
            $payload = substr($payload, $commaPosition + 1);
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new ValidationException(['Parameter "content_base64" must contain valid base64 data or a valid data URL.']);
        }

        return $decoded;
    }
}
