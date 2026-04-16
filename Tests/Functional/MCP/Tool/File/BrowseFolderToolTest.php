<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\File\BrowseFolderTool;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class BrowseFolderToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private string $storageBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/sys_file_storage.csv');

        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;

        $this->createTestFolderStructure();
    }

    /**
     * Create test folders and files in the default storage
     */
    private function createTestFolderStructure(): void
    {
        $this->storageBasePath = $this->instancePath . '/fileadmin';
        @mkdir($this->storageBasePath, 0777, true);

        // Create folder structure
        @mkdir($this->storageBasePath . '/images', 0777, true);
        @mkdir($this->storageBasePath . '/documents', 0777, true);

        // Create test files using GD (1x1px PNG)
        $image = imagecreatetruecolor(1, 1);
        imagepng($image, $this->storageBasePath . '/images/logo.png');
        imagedestroy($image);

        $image = imagecreatetruecolor(1, 1);
        $red = imagecolorallocate($image, 255, 0, 0);
        imagesetpixel($image, 0, 0, $red);
        imagepng($image, $this->storageBasePath . '/images/banner.png');
        imagedestroy($image);

        // Create a simple text file
        file_put_contents($this->storageBasePath . '/documents/readme.txt', 'Test document');
    }

    public function testBrowseRootFolder(): void
    {
        $tool = GeneralUtility::makeInstance(BrowseFolderTool::class);

        $result = $tool->execute(['folder' => '1:/']);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        self::assertStringContainsString('📁 Storage: fileadmin', $content);
        self::assertStringContainsString('📂 /', $content);
        self::assertStringContainsString('images', $content);
        self::assertStringContainsString('documents', $content);
    }

    public function testBrowseSubfolder(): void
    {
        $tool = GeneralUtility::makeInstance(BrowseFolderTool::class);

        $result = $tool->execute(['folder' => '1:/images/']);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        self::assertStringContainsString('📂 /images/', $content);
        self::assertStringContainsString('logo.png', $content);
        self::assertStringContainsString('banner.png', $content);
    }

    public function testShowsFileMetadata(): void
    {
        $tool = GeneralUtility::makeInstance(BrowseFolderTool::class);

        $result = $tool->execute(['folder' => '1:/documents/']);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        self::assertStringContainsString('readme.txt', $content);
        // File should show size info
        self::assertMatchesRegularExpression('/\d+ B/', $content);
    }

    public function testShowsSubfolderFileCount(): void
    {
        $tool = GeneralUtility::makeInstance(BrowseFolderTool::class);

        $result = $tool->execute(['folder' => '1:/']);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        // images folder has 2 files
        self::assertStringContainsString('images (2 files)', $content);
        // documents folder has 1 file
        self::assertStringContainsString('documents (1 files)', $content);
    }

    public function testShowsCombinedIdentifierForSubfolders(): void
    {
        $tool = GeneralUtility::makeInstance(BrowseFolderTool::class);

        $result = $tool->execute(['folder' => '1:/']);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        self::assertStringContainsString('1:/images/', $content);
        self::assertStringContainsString('1:/documents/', $content);
    }

    public function testEmptyFolder(): void
    {
        @mkdir($this->storageBasePath . '/empty', 0777, true);

        $tool = GeneralUtility::makeInstance(BrowseFolderTool::class);

        $result = $tool->execute(['folder' => '1:/empty/']);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        self::assertStringContainsString('(empty folder)', $content);
    }

    public function testRecursiveListing(): void
    {
        $tool = GeneralUtility::makeInstance(BrowseFolderTool::class);

        $result = $tool->execute([
            'folder' => '1:/',
            'recursive' => true,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        // Should show subfolder contents too
        self::assertStringContainsString('images', $content);
        self::assertStringContainsString('logo.png', $content);
        self::assertStringContainsString('banner.png', $content);
        self::assertStringContainsString('documents', $content);
        self::assertStringContainsString('readme.txt', $content);
    }
}
