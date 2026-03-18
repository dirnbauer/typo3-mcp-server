<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\McpFileHarnessService;
use Hn\McpServer\Service\WorkspaceContextService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class McpFileHarnessServiceTest extends TestCase
{
    private mixed $originalBackendUser;
    private array $originalExtensionSettings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBackendUser = $GLOBALS['BE_USER'] ?? null;
        $this->originalExtensionSettings = is_array($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] ?? null)
            ? $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']
            : [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['BE_USER'] = $this->originalBackendUser;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $this->originalExtensionSettings;
        parent::tearDown();
    }

    #[Test]
    public function describeHarnessUsesDefaultBaseFolderAndWorkspaceUploadFolder(): void
    {
        $this->setBackendUserWorkspace(7);
        $subject = $this->createSubject([]);

        $description = $subject->describeHarness();

        self::assertSame('1:/mcp/', $description['baseFolder']);
        self::assertSame('1:/mcp/workspaces/ws-7/', $description['uploadFolder']);
        self::assertSame(7, $description['workspaceId']);
        self::assertTrue($description['workspaceUploads']);
    }

    #[Test]
    public function fileadminStyleHarnessPathIsNormalized(): void
    {
        $this->setBackendUserWorkspace(3);
        $subject = $this->createSubject([
            'fileHarnessRoot' => 'fileadmin/custom-mcp',
        ]);

        $target = $subject->resolveFileTarget('notes/readme.md');

        self::assertSame('1:/custom-mcp/', $subject->getBaseFolderIdentifier());
        self::assertSame('1:/custom-mcp/notes/readme.md', $target['combinedIdentifier']);
        self::assertSame('/custom-mcp/notes/', $target['folderPath']);
        self::assertSame('readme.md', $target['fileName']);
    }

    #[Test]
    public function absoluteIdentifierInsideHarnessIsAcceptedForReadTarget(): void
    {
        $subject = $this->createSubject([
            'fileHarnessRoot' => '1:/secure-area/',
        ]);

        $target = $subject->resolveFileTarget('1:/secure-area/docs/guide.txt');

        self::assertSame(1, $target['storageUid']);
        self::assertSame('/secure-area/docs/', $target['folderPath']);
        self::assertSame('guide.txt', $target['fileName']);
        self::assertSame('1:/secure-area/docs/guide.txt', $target['combinedIdentifier']);
    }

    #[Test]
    public function uploadTargetRejectsAbsolutePathOutsideWorkspaceUploadFolder(): void
    {
        $this->setBackendUserWorkspace(5);
        $subject = $this->createSubject([]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Workspace uploads must stay inside "1:/mcp/workspaces/ws-5/"');

        $subject->resolveUploadTarget('1:/mcp/images/pixel.png');
    }

    #[Test]
    public function uploadTargetUsesBaseFolderWhenWorkspaceUploadsAreDisabled(): void
    {
        $this->setBackendUserWorkspace(5);
        $subject = $this->createSubject([
            'workspaceUploadSubfolders' => '0',
        ]);

        $target = $subject->resolveUploadTarget('images/pixel.png');

        self::assertSame('1:/mcp/', $target['uploadFolder']);
        self::assertSame('/mcp/images/', $target['folderPath']);
        self::assertSame('pixel.png', $target['fileName']);
        self::assertSame(5, $target['workspaceId']);
    }

    #[Test]
    public function uploadTargetFallsBackToLiveWorkspaceWhenNoBackendUserExists(): void
    {
        unset($GLOBALS['BE_USER']);
        $subject = $this->createSubject([]);

        $target = $subject->resolveUploadTarget('images/public.png');

        self::assertSame('1:/mcp/', $target['uploadFolder']);
        self::assertSame('/mcp/images/', $target['folderPath']);
        self::assertSame(0, $target['workspaceId']);
    }

    #[Test]
    public function folderTargetRejectsDirectoryTraversal(): void
    {
        $subject = $this->createSubject([]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Directory traversal is not allowed in folder paths.');

        $subject->resolveFolderTarget('../outside');
    }

    #[Test]
    public function storedUploadFilenameIsSanitizedAndKeepsExtension(): void
    {
        $subject = $this->createSubject([]);

        $fileName = $subject->buildStoredUploadFileName(' My unsafe file @#$%.PNG ');

        self::assertMatchesRegularExpression('/^My-unsafe-file-[a-f0-9]{16}\.png$/', $fileName);
    }

    private function createSubject(array $configuration): McpFileHarnessService
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $configuration;

        $workspaceContextService = new WorkspaceContextService(
            $this->createMock(ConnectionPool::class),
            new Context(),
            self::createStub(LoggerInterface::class),
            $this->createMock(\TYPO3\CMS\Workspaces\Service\WorkspaceService::class),
        );

        return new McpFileHarnessService(
            new ExtensionConfiguration(),
            $workspaceContextService,
        );
    }

    private function setBackendUserWorkspace(int $workspaceId): void
    {
        $backendUser = new BackendUserAuthentication();
        $backendUser->workspace = $workspaceId;
        $GLOBALS['BE_USER'] = $backendUser;
    }
}
