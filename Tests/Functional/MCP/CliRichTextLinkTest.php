<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP;

use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\Service\SiteRequestContext;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Fixtures\RequestCapturingDataHandlerHook;
use Mcp\Types\CallToolRequestParams;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Rich text with a t3:// link over the console transports: the CLI tool
 * commands and the stdio server run tools without an HTTP request. With
 * security.backend.htmlSanitizeRte enabled, DataHandler's RTE sanitizer
 * needs one, so the record tools publish a request for the site of the
 * written record - and remove it again after the call.
 *
 * Fixture: site "alpha" (root page 1, https://alpha.example/), site "beta"
 * (root page 300, https://beta.example/) and page 400, a root page without
 * a site.
 */
final class CliRichTextLinkTest extends AbstractFunctionalTest
{
    private const string BODYTEXT = '<p>See <a href="t3://page?uid=2">About</a>.</p>';

    private bool $hadRequest = false;

    private mixed $previousRequest = null;

    private mixed $previousSanitizeFeature = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hadRequest = array_key_exists('TYPO3_REQUEST', $GLOBALS);
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        unset($GLOBALS['TYPO3_REQUEST']);
        $this->previousSanitizeFeature = $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.backend.htmlSanitizeRte'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.backend.htmlSanitizeRte'] = true;
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['mcp-cli-request-test']
            = RequestCapturingDataHandlerHook::class;
        RequestCapturingDataHandlerHook::reset();

        $pages = $this->getConnectionForTable('pages');
        foreach ([[300, 0, 'Beta home'], [301, 300, 'Beta news'], [400, 0, 'Outside every site']] as [$uid, $pid, $title]) {
            $pages->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => $title, 'doktype' => 1, 'slug' => '/' . $uid]);
        }

        $siteWriter = $this->getService(SiteWriter::class);
        $siteWriter->write('alpha', $this->siteConfiguration(1, 'https://alpha.example/'));
        $siteWriter->write('beta', $this->siteConfiguration(300, 'https://beta.example/'));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['mcp-cli-request-test']);
        RequestCapturingDataHandlerHook::reset();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.backend.htmlSanitizeRte'] = $this->previousSanitizeFeature;
        if ($this->hadRequest) {
            $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        } else {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
        parent::tearDown();
    }

    #[Test]
    public function stdioServerSavesATypo3LinkWithTheRequestOfTheRecordsSite(): void
    {
        $result = $this->callOverStdio('WriteTable', [
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 301,
            'data' => ['CType' => 'text', 'header' => 'Beta teaser', 'bodytext' => self::BODYTEXT],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $payload = $this->decode($result);
        self::assertArrayNotHasKey('siteContext', $payload, 'The record has a page inside a site: no fallback note');
        self::assertIsInt($payload['uid'] ?? null);
        self::assertSame(self::BODYTEXT, $this->storedBodytext($payload['uid']));
        $this->assertEveryDatamapSaw('beta', 'beta.example');
        self::assertArrayNotHasKey('TYPO3_REQUEST', $GLOBALS, 'The stdio server must not carry the request into the next call');
    }

    #[Test]
    public function updateUsesTheSiteOfTheStoredRecord(): void
    {
        // tt_content 102 lives on page 2 below root page 1 (site "alpha").
        $result = $this->callOverStdio('WriteTable', [
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 102,
            'data' => ['bodytext' => self::BODYTEXT],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $this->assertEveryDatamapSaw('alpha', 'alpha.example');
    }

    #[Test]
    public function bulkWriteUsesTheSiteOfItsFirstOperation(): void
    {
        $result = $this->callOverStdio('BulkWrite', [
            'operations' => [
                ['action' => 'create', 'table' => 'tt_content', 'pid' => 300, 'data' => ['CType' => 'text', 'header' => 'Bulk', 'bodytext' => self::BODYTEXT]],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $this->assertEveryDatamapSaw('beta', 'beta.example');
    }

    #[Test]
    public function cliToolCommandSavesATypo3Link(): void
    {
        $command = $this->getContainer()->get('Hn\\McpServer\\Command\\Tool\\WriteTableToolCommand');
        self::assertInstanceOf(Command::class, $command);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--action' => 'create',
            '--table' => 'tt_content',
            '--pid' => '301',
            '--params' => json_encode(['data' => ['CType' => 'text', 'header' => 'From the CLI', 'bodytext' => self::BODYTEXT]], JSON_THROW_ON_ERROR),
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertTrue($payload['ok'] ?? false, $tester->getDisplay());
        self::assertIsInt($payload['result']['uid'] ?? null);
        self::assertSame(self::BODYTEXT, $this->storedBodytext($payload['result']['uid']));
        $this->assertEveryDatamapSaw('beta', 'beta.example');
        self::assertArrayNotHasKey('TYPO3_REQUEST', $GLOBALS);
    }

    #[Test]
    public function recordWithoutPageContextFallsBackToTheFirstSiteAndSaysSo(): void
    {
        $firstSite = array_values($this->getService(SiteFinder::class)->getAllSites())[0]->getIdentifier();

        $result = $this->callOverStdio('WriteTable', [
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 400,
            'data' => ['CType' => 'text', 'header' => 'Orphan', 'bodytext' => self::BODYTEXT],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $payload = $this->decode($result);
        self::assertIsInt($payload['uid'] ?? null);
        self::assertSame(self::BODYTEXT, $this->storedBodytext($payload['uid']));
        self::assertSame($firstSite, $payload['siteContext']['site'] ?? null);
        self::assertTrue($payload['siteContext']['fallback'] ?? false);
        self::assertStringContainsString('Page 400 is not part of any site', (string)($payload['siteContext']['note'] ?? ''));
        self::assertSame([$firstSite], array_values(array_unique(array_column(RequestCapturingDataHandlerHook::$captured, 'site'))));
    }

    /**
     * Over HTTP the endpoint's request stays in charge (its host, no site
     * lookup); the tool sees it as a backend request and the endpoint gets
     * its own request back.
     */
    #[Test]
    public function anActiveEndpointRequestIsKept(): void
    {
        $serverParams = ['HTTP_HOST' => 'caller.example', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/mcp'];
        $request = new ServerRequest('https://caller.example/mcp', 'POST', 'php://input', [], $serverParams)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $result = $this->callOverStdio('WriteTable', [
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 301,
            'data' => ['CType' => 'text', 'header' => 'Over HTTP', 'bodytext' => self::BODYTEXT],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $this->assertEveryDatamapSaw(null, 'caller.example');
        self::assertSame($request, $GLOBALS['TYPO3_REQUEST'] ?? null);
    }

    #[Test]
    public function syntheticRequestCarriesTheSiteDefaultLanguageAndNormalizedParams(): void
    {
        $scope = $this->getService(SiteRequestContext::class)->enterForPage(301);
        try {
            $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
            self::assertInstanceOf(ServerRequest::class, $request);
            self::assertSame('https://beta.example/', (string)$request->getUri());
            self::assertSame('beta', $scope->siteIdentifier);
            self::assertFalse($scope->isFallback());
            $normalizedParams = $request->getAttribute('normalizedParams');
            self::assertInstanceOf(NormalizedParams::class, $normalizedParams);
            self::assertSame('https://beta.example', $normalizedParams->getRequestHost());
            self::assertSame(0, $request->getAttribute('language')?->getLanguageId());
        } finally {
            $scope->leave();
        }

        self::assertArrayNotHasKey('TYPO3_REQUEST', $GLOBALS);
    }

    /**
     * A write can take several DataHandler runs (positioning, workspace
     * versioning); every one of them has to see the same backend request.
     */
    private function assertEveryDatamapSaw(?string $site, string $host): void
    {
        self::assertNotSame([], RequestCapturingDataHandlerHook::$captured, 'DataHandler processed no datamap');
        self::assertSame(
            [['site' => $site, 'host' => $host, 'backend' => true]],
            array_values(array_unique(RequestCapturingDataHandlerHook::$captured, SORT_REGULAR)),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callOverStdio(string $tool, array $arguments): CallToolResult
    {
        RequestCapturingDataHandlerHook::reset();
        $handlers = $this->getService(McpServerFactory::class)->createServer()->getHandlers();
        $result = $handlers['tools/call'](new CallToolRequestParams($tool, $arguments));
        self::assertInstanceOf(CallToolResult::class, $result);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(CallToolResult $result): array
    {
        $content = $result->content[0] ?? null;
        self::assertInstanceOf(TextContent::class, $content);
        $decoded = json_decode($content->text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function storedBodytext(int $uid): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (string)$queryBuilder->select('bodytext')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array<string, mixed>
     */
    private function siteConfiguration(int $rootPageId, string $base): array
    {
        return [
            'rootPageId' => $rootPageId,
            'base' => $base,
            'languages' => [
                [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ],
            ],
        ];
    }
}
