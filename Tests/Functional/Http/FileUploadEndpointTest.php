<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\FileUploadEndpoint;
use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\Service\FileUploadService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests for the pre-signed upload endpoint (/mcp_upload).
 *
 * Flow under test: the UploadFile MCP tool (or FileUploadService directly)
 * mints a single-use token bound to a sandbox folder; the client PUTs raw
 * file bytes to the endpoint; the endpoint stores the file as the token's
 * backend user under a randomized name and returns the sys_file as JSON.
 */
final class FileUploadEndpointTest extends AbstractFunctionalTest
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
    public function uploadStoresFileInSandboxAndReturnsJson(): void
    {
        $response = $this->dispatchUpload($this->createToken('uploaded.png'), $this->pngBytes());

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $data = $this->decode($response);

        self::assertSame('uploaded', $data['action']);
        self::assertSame('uploaded.png', $data['originalFilename']);
        self::assertNotSame('uploaded.png', $data['storedFilename'], 'Stored names are randomized');
        self::assertStringEndsWith('.png', (string)$data['storedFilename']);
        self::assertStringStartsWith('1:/mcp/', (string)$data['identifier']);
        self::assertFalse($data['deduplicated']);
        self::assertGreaterThan(0, $data['uid']);
        self::assertSame('1:/mcp/', $data['targetFolder']);

        $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject((int)$data['uid']);
        self::assertSame($this->pngBytes(), $file->getContents());
    }

    #[Test]
    public function tokenIsSingleUse(): void
    {
        $token = $this->createToken('once.png');

        self::assertSame(201, $this->dispatchUpload($token, $this->pngBytes())->getStatusCode());
        self::assertSame(401, $this->dispatchUpload($token, $this->pngBytes())->getStatusCode(), 'A used token must be rejected');
    }

    #[Test]
    public function invalidTokenIsRejected(): void
    {
        self::assertSame(401, $this->dispatchUpload('not-a-real-token', $this->pngBytes())->getStatusCode());
    }

    #[Test]
    public function queryParameterTokenIsAcceptedAsFallback(): void
    {
        $token = $this->createToken('query-token.png');
        $response = $this->dispatchUpload('', $this->pngBytes(), ['token' => $token]);

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function fileNameFromQueryParameterIsUsedWhenTokenHasNone(): void
    {
        $response = $this->dispatchUpload($this->createToken(''), $this->pngBytes(), ['fileName' => 'from-query.png']);

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('from-query.png', $this->decode($response)['originalFilename']);
    }

    #[Test]
    public function fileNameFromContentDispositionHeaderIsUsed(): void
    {
        $response = $this->dispatchUpload(
            $this->createToken(''),
            $this->pngBytes(),
            [],
            'PUT',
            ['Content-Disposition' => 'attachment; filename="from-header.png"'],
        );

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('from-header.png', $this->decode($response)['originalFilename']);
    }

    #[Test]
    public function tokenFileNameBeatsQueryParameter(): void
    {
        // The token authorizes exactly the file it was minted for; the request
        // must not be able to widen it to another name/type.
        $response = $this->dispatchUpload($this->createToken('bound-name.png'), $this->pngBytes(), ['fileName' => 'sneaky-other.png']);

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('bound-name.png', $this->decode($response)['originalFilename']);
    }

    #[Test]
    public function missingFileNameIsRejected(): void
    {
        $response = $this->dispatchUpload($this->createToken(''), $this->pngBytes());

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('file name', (string)$this->decode($response)['error']);
    }

    #[Test]
    public function emptyBodyIsRejected(): void
    {
        $response = $this->dispatchUpload($this->createToken('empty.png'), '');

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('empty', (string)$this->decode($response)['error']);
    }

    #[Test]
    public function failedUploadConsumesTheToken(): void
    {
        // The token is consumed on the attempt, not on success: a leaked token
        // must be a single attempt, not a 15-minute upload permit with retries.
        $token = $this->createToken('empty.png');

        self::assertSame(400, $this->dispatchUpload($token, '')->getStatusCode());
        self::assertSame(401, $this->dispatchUpload($token, $this->pngBytes())->getStatusCode(), 'A token must not survive a failed upload attempt');
    }

    #[Test]
    public function expiredTokenIsRejected(): void
    {
        $token = $this->createToken('late.png');
        $this->getConnectionForTable('tx_mcpserver_upload_tokens')
            ->update('tx_mcpserver_upload_tokens', ['expires' => time() - 10], ['used' => 0]);

        self::assertSame(401, $this->dispatchUpload($token, $this->pngBytes())->getStatusCode());
    }

    #[Test]
    public function multipartUploadUsesTheClientFileName(): void
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($this->pngBytes());
        $stream->rewind();
        $uploadedFile = new UploadedFile($stream, strlen($this->pngBytes()), UPLOAD_ERR_OK, 'from-form.png');

        $request = $this->createRequest('POST')
            ->withHeader('Authorization', 'Bearer ' . $this->createToken(''))
            ->withUploadedFiles(['file' => $uploadedFile]);

        $response = $this->endpoint()($request);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('from-form.png', $this->decode($response)['originalFilename']);
    }

    #[Test]
    public function executableAndServerConfigurationFilesAreRejected(): void
    {
        foreach (['evil.php', 'evil.php.jpg', '.user.ini', 'page.html'] as $fileName) {
            // Names are bound when the token is minted, so mint tokens without a name here.
            $response = $this->dispatchUpload($this->createToken(''), '<?php echo 1;', ['fileName' => $fileName]);

            self::assertSame(400, $response->getStatusCode(), $fileName . ': ' . (string)$response->getBody());
            self::assertStringContainsString('not allowed', (string)$this->decode($response)['error'], $fileName);
        }
        self::assertSame(0, $this->countSysFiles(), 'No file may be created for a rejected name');
    }

    #[Test]
    public function executableFileNameCannotBeBoundToAToken(): void
    {
        $this->expectExceptionMessageMatches('/not allowed/');
        $this->createToken('evil.php');
    }

    #[Test]
    public function oversizedUploadIsRejected(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['maxFileSizeMb'] = '1';

        $response = $this->dispatchUpload($this->createToken('big.bin'), str_repeat('x', 1024 * 1024 + 1));

        self::assertSame(413, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('maxFileSizeMb', (string)$this->decode($response)['error']);
        self::assertSame(0, $this->countSysFiles());
    }

    #[Test]
    public function identicalContentIsDeduplicated(): void
    {
        $first = $this->decode($this->dispatchUpload($this->createToken('first.png'), $this->pngBytes()));
        $secondResponse = $this->dispatchUpload($this->createToken('second.png'), $this->pngBytes());
        $second = $this->decode($secondResponse);

        self::assertSame(201, $secondResponse->getStatusCode());
        self::assertTrue($second['deduplicated']);
        self::assertSame($first['uid'], $second['uid']);
        self::assertSame($first['identifier'], $second['identifier']);
        self::assertSame(1, $this->countSysFiles());
    }

    /**
     * Same hazard as #107 on the /mcp endpoint: the upload endpoint impersonates
     * a backend user without the regular authentication flow, so it has to
     * restore the stored uc itself. Otherwise a writeUC() during the upload
     * overwrites the user's backend preferences with a nearly empty array.
     */
    #[Test]
    public function storedUserConfigurationSurvivesAnUpload(): void
    {
        $connection = $this->getConnectionForTable('be_users');
        $connection->update('be_users', ['uc' => serialize(['titleLen' => 77, 'lang' => 'de', 'emailMeAtLogin' => 1])], ['uid' => 1]);

        $response = $this->dispatchUpload($this->createToken('with-uc.png'), $this->pngBytes());
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());

        self::assertSame(77, $GLOBALS['BE_USER']->uc['titleLen'] ?? null, 'The impersonated user must carry the stored uc');

        $GLOBALS['BE_USER']->writeUC();
        $persisted = unserialize((string)$connection->select(['uc'], 'be_users', ['uid' => 1])->fetchOne(), ['allowed_classes' => false]);
        self::assertIsArray($persisted);
        self::assertSame(77, $persisted['titleLen'] ?? null, 'writeUC() must not destroy the stored backend preferences');
        self::assertSame('de', $persisted['lang'] ?? null);
    }

    #[Test]
    public function disabledBackendUserCannotUpload(): void
    {
        $token = $this->createToken('blocked.png');
        $this->getConnectionForTable('be_users')->update('be_users', ['disable' => 1], ['uid' => 1]);

        $response = $this->dispatchUpload($token, $this->pngBytes());

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('not available', (string)$this->decode($response)['error']);
    }

    #[Test]
    public function getMethodIsRejected(): void
    {
        self::assertSame(405, $this->dispatchUpload($this->createToken('x.png'), '', [], 'GET')->getStatusCode());
    }

    #[Test]
    public function responsesCarrySecurityHeaders(): void
    {
        $response = $this->dispatchUpload('bogus', $this->pngBytes());

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * Full round trip: the UploadFile tool mints the URL, the endpoint consumes it.
     */
    #[Test]
    public function endToEndFlowFromTool(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createToolRequest();

        $result = $this->get(UploadFileTool::class)->execute(['path' => 'images/roundtrip.png']);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $toolData = json_decode((string)$result->content[0]->text, true);
        self::assertIsArray($toolData);

        self::assertSame('presigned_upload', $toolData['action']);
        self::assertSame('https://example.com/mcp_upload', $toolData['uploadUrl']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$toolData['uploadToken']);
        self::assertStringNotContainsString('token=', (string)$toolData['uploadUrl'], 'The token must not appear in the URL');
        self::assertStringStartsWith('1:/mcp/', (string)$toolData['targetFolder']);
        self::assertStringEndsWith('/images/', (string)$toolData['targetFolder']);

        // Token travels as Authorization header, like the tool instructions say
        $response = $this->dispatchUpload((string)$toolData['uploadToken'], $this->pngBytes());
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());

        $data = $this->decode($response);
        self::assertSame('roundtrip.png', $data['originalFilename'], 'File name preset in the tool call must be used');
        self::assertSame($toolData['targetFolder'], $data['targetFolder']);
        self::assertStringStartsWith((string)$toolData['targetFolder'], (string)$data['identifier']);
    }

    #[Test]
    public function folderOnlyPathDefersTheFileNameToUploadTime(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createToolRequest();

        $result = $this->get(UploadFileTool::class)->execute(['path' => 'images/']);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $toolData = json_decode((string)$result->content[0]->text, true);
        self::assertIsArray($toolData);
        self::assertNull($toolData['fileName']);
        self::assertStringContainsString('fileName=', (string)$toolData['instructions']);

        $response = $this->dispatchUpload((string)$toolData['uploadToken'], $this->pngBytes(), ['fileName' => 'late.png']);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('late.png', $this->decode($response)['originalFilename']);
    }

    private function createToolRequest(): ServerRequest
    {
        return (new ServerRequest(new Uri('https://example.com/mcp'), 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    private function endpoint(): FileUploadEndpoint
    {
        $endpoint = $this->getService(FileUploadEndpoint::class);
        self::assertInstanceOf(FileUploadEndpoint::class, $endpoint);

        return $endpoint;
    }

    /**
     * Create an upload token for the admin user, targeting the sandbox root.
     */
    private function createToken(string $fileName): string
    {
        $service = $this->getService(FileUploadService::class);
        self::assertInstanceOf(FileUploadService::class, $service);

        return $service->createUploadToken(1, '1:/mcp/', $fileName)['token'];
    }

    private function createRequest(string $method): ServerRequest
    {
        $request = GeneralUtility::makeInstance(ServerRequestFactory::class)
            ->createServerRequest($method, 'https://example.com/mcp_upload', ['REMOTE_ADDR' => '198.51.100.80']);
        self::assertInstanceOf(ServerRequest::class, $request);

        return $request;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function dispatchUpload(string $token, string $body, array $query = [], string $method = 'PUT', array $headers = []): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        $request = $this->createRequest($method)->withBody($stream)->withQueryParams($query);
        if ($token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->endpoint()($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $data = json_decode((string)$response->getBody(), true);
        self::assertIsArray($data, (string)$response->getBody());

        return $data;
    }

    private function countSysFiles(): int
    {
        return (int)$this->getConnectionForTable('sys_file')->count('uid', 'sys_file', []);
    }

    private function pngBytes(): string
    {
        return (string)base64_decode(self::PIXEL_PNG_BASE64, true);
    }
}
