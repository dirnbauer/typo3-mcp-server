<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class UploadFileToolTest extends AbstractFunctionalTest
{
    private const PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Z0f8AAAAASUVORK5CYII=';

    private mixed $previousRequest = null;

    /** @var array<string, mixed> */
    private array $originalMcpExtensionSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->originalMcpExtensionSettings = is_array($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] ?? null)
            ? $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']
            : [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $this->originalMcpExtensionSettings;
        parent::tearDown();
    }

    #[Test]
    public function uploadsFileIntoWorkspaceScopedSandboxFolder(): void
    {
        $tool = $this->get(UploadFileTool::class);
        $result = $tool->execute([
            'path' => 'images/pixel.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
            'metadata' => [
                'title' => 'Pixel',
                'alternative' => 'Single pixel image',
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string)$result->content[0]->text, true);

        self::assertSame('uploaded', $json['action']);
        self::assertFalse($json['deduplicated']);
        self::assertGreaterThan(0, $json['workspaceId']);
        self::assertStringStartsWith('1:/mcp/workspaces/ws-' . $json['workspaceId'] . '/images/', $json['identifier']);
        self::assertSame('pixel.png', $json['originalFilename']);
        self::assertNotSame('pixel.png', $json['storedFilename']);
        self::assertSame('Pixel', $json['metadata']['title']);

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $file = $storage->getFile(substr((string)$json['identifier'], 2));
        self::assertSame('Pixel', $file->getMetaData()->get()['title']);
        self::assertSame('Single pixel image', $file->getMetaData()->get()['alternative']);
    }

    #[Test]
    public function rejectsInvalidBase64Payload(): void
    {
        $tool = $this->get(UploadFileTool::class);
        $result = $tool->execute([
            'path' => 'docs/broken.pdf',
            'content_base64' => 'not-base64',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('valid base64', $result->content[0]->text);
    }

    #[Test]
    public function rejectsUploadOutsideConfiguredSandbox(): void
    {
        $tool = $this->get(UploadFileTool::class);
        $result = $tool->execute([
            'path' => '1:/user_upload/evil.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('restricted to the configured MCP file sandbox', $result->content[0]->text);
    }

    #[Test]
    public function acceptsDataUrlPayloadWithoutBackendUserAndFallsBackToBaseSandbox(): void
    {
        $originalBackendUser = $GLOBALS['BE_USER'] ?? null;
        unset($GLOBALS['BE_USER']);

        try {
            $tool = $this->get(UploadFileTool::class);
            $result = $tool->execute([
                'path' => 'images/data-url.png',
                'content_base64' => 'data:image/png;base64,' . self::PIXEL_PNG_BASE64,
            ]);
        } finally {
            $GLOBALS['BE_USER'] = $originalBackendUser;
        }

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string)$result->content[0]->text, true);

        self::assertSame(0, $json['workspaceId']);
        self::assertStringStartsWith('1:/mcp/images/', (string)$json['identifier']);
        self::assertSame('1:/mcp/', $json['uploadFolder']);
    }

    #[Test]
    public function rejectsUploadWithoutFilenameExtension(): void
    {
        $tool = $this->get(UploadFileTool::class);
        $result = $tool->execute([
            'path' => 'images/no-extension',
            'content_base64' => self::PIXEL_PNG_BASE64,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('filename extension', (string)$result->content[0]->text);
    }

    #[Test]
    public function ignoresUnknownMetadataKeys(): void
    {
        $tool = $this->get(UploadFileTool::class);
        $result = $tool->execute([
            'path' => 'images/known-metadata.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
            'metadata' => [
                'title' => 'Known title',
                'custom' => 'should be ignored',
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string)$result->content[0]->text, true);

        self::assertSame(['title' => 'Known title'], $json['metadata']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function executableFileNames(): iterable
    {
        yield 'server-side script' => ['scripts/evil.php'];
        yield 'inner executable extension' => ['images/evil.php.jpg'];
        yield 'per-directory php configuration' => ['.user.ini'];
        yield 'web server configuration' => ['docs/.htaccess'];
        yield 'browser executable document' => ['docs/page.html'];
        yield 'shell script' => ['scripts/run.sh'];
    }

    #[Test]
    #[DataProvider('executableFileNames')]
    public function refusesExecutableAndServerConfigurationFiles(string $path): void
    {
        $result = $this->get(UploadFileTool::class)->execute([
            'path' => $path,
            'content_base64' => base64_encode('<?php echo 1;'),
        ]);

        self::assertTrue($result->isError, $path);
        self::assertStringContainsString('not allowed', (string)$result->content[0]->text);
        self::assertSame(0, (int)$this->getConnectionForTable('sys_file')->count('uid', 'sys_file', []));
    }

    #[Test]
    public function rejectsPayloadsAboveTheConfiguredSizeLimit(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['maxFileSizeMb'] = '1';

        $result = $this->get(UploadFileTool::class)->execute([
            'path' => 'docs/big.txt',
            'content_base64' => base64_encode(str_repeat('a', 1024 * 1024 + 1)),
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('maximum size of 1 MiB', (string)$result->content[0]->text);
    }

    #[Test]
    public function deduplicatesIdenticalContentAcrossFolders(): void
    {
        $tool = $this->get(UploadFileTool::class);
        $first = json_decode((string)$tool->execute(['path' => 'images/first.png', 'content_base64' => self::PIXEL_PNG_BASE64])->content[0]->text, true);

        $secondResult = $tool->execute(['path' => 'other/second.png', 'content_base64' => self::PIXEL_PNG_BASE64]);
        self::assertFalse($secondResult->isError, json_encode($secondResult->jsonSerialize()));
        $second = json_decode((string)$secondResult->content[0]->text, true);

        self::assertTrue($second['deduplicated']);
        self::assertSame($first['uid'], $second['uid']);
        self::assertSame($first['identifier'], $second['identifier']);
        self::assertStringContainsString('identical content', (string)$second['note']);
        self::assertSame(1, (int)$this->getConnectionForTable('sys_file')->count('uid', 'sys_file', []));
    }

    #[Test]
    public function returnsPresignedUploadUrlWhenPayloadIsOmitted(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createRequest('https://example.com/mcp');

        $result = $this->get(UploadFileTool::class)->execute(['path' => 'images/local-photo.jpg']);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string)$result->content[0]->text, true);

        self::assertSame('presigned_upload', $json['action']);
        self::assertSame('https://example.com/mcp_upload', $json['uploadUrl']);
        self::assertSame('PUT', $json['method']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$json['uploadToken']);
        self::assertSame('local-photo.jpg', $json['fileName']);
        self::assertStringStartsWith('1:/mcp/', (string)$json['targetFolder']);
        self::assertStringEndsWith('/images/', (string)$json['targetFolder']);
        self::assertStringContainsString('Authorization: Bearer', (string)$json['instructions']);
        self::assertSame(1, (int)$this->getConnectionForTable('tx_mcpserver_upload_tokens')->count('uid', 'tx_mcpserver_upload_tokens', []));
    }

    #[Test]
    public function presignedUploadUrlHonorsTheSitePathPrefix(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl'] = 'https://example.com/subdir';
        $GLOBALS['TYPO3_REQUEST'] = $this->createRequest('https://example.com/subdir/mcp');

        try {
            $result = $this->get(UploadFileTool::class)->execute(['path' => 'images/sub.jpg']);
        } finally {
            unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl']);
        }

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string)$result->content[0]->text, true);
        self::assertSame('https://example.com/subdir/mcp_upload', $json['uploadUrl']);
    }

    #[Test]
    public function presignedFlowAcceptsFolderOnlyPath(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createRequest('https://example.com/mcp');

        $result = $this->get(UploadFileTool::class)->execute(['path' => 'images/']);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string)$result->content[0]->text, true);
        self::assertNull($json['fileName']);
        self::assertStringContainsString('?fileName=', (string)$json['instructions']);
    }

    #[Test]
    public function presignedFlowRefusesExecutableFileNamesBeforeMintingAToken(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createRequest('https://example.com/mcp');

        $result = $this->get(UploadFileTool::class)->execute(['path' => 'scripts/evil.php']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('not allowed', (string)$result->content[0]->text);
        self::assertSame(0, (int)$this->getConnectionForTable('tx_mcpserver_upload_tokens')->count('uid', 'tx_mcpserver_upload_tokens', []));
    }

    #[Test]
    public function presignedFlowRequiresAnAbsoluteBaseUrl(): void
    {
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl']);

        $result = $this->get(UploadFileTool::class)->execute(['path' => 'images/no-base.jpg']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('absolute upload URL', (string)$result->content[0]->text);
        self::assertSame(0, (int)$this->getConnectionForTable('tx_mcpserver_upload_tokens')->count('uid', 'tx_mcpserver_upload_tokens', []));
    }

    private function createRequest(string $url): ServerRequest
    {
        return (new ServerRequest(new Uri($url), 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }
}
