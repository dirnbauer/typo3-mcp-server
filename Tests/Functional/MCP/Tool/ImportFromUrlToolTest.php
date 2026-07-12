<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ImportFromUrlTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

final class ImportFromUrlToolTest extends AbstractFunctionalTest
{
    private ImportFromUrlTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['enforceCapabilityManifest'] = '0';
        $this->tool = $this->getService(ImportFromUrlTool::class);
    }

    public function testRejectsNonHttpSchemes(): void
    {
        $result = $this->tool->execute([
            'url' => 'file:///etc/passwd',
            'targetPid' => 1,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Only http and https URLs are allowed', $this->getFirstTextContent($result));
    }

    public function testRejectsPrivateIpv4Targets(): void
    {
        $result = $this->tool->execute([
            'url' => 'http://127.0.0.1/private',
            'targetPid' => 1,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('private or reserved network address', $this->getFirstTextContent($result));
    }

    public function testRejectsInvalidModeBeforeFetching(): void
    {
        $result = $this->tool->execute([
            'url' => 'https://example.com/article',
            'targetPid' => 1,
            'mode' => 'preview',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('mode must be "analyze" or "execute"', $this->getFirstTextContent($result));
    }

    public function testManifestRejectsUndeclaredOutboundHostBeforeFetching(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['enforceCapabilityManifest'] = '1';

        $result = $this->tool->execute([
            'url' => 'https://93.184.216.34/article',
            'targetPid' => 1,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('permission to network', $this->getFirstTextContent($result));
    }

    public function testRedirectResponseIsNotFollowed(): void
    {
        $body = new Stream('php://temp', 'rw');
        $body->write('redirect');
        $body->rewind();
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects($this->once())
            ->method('request')
            ->with(
                'https://93.184.216.34/article',
                'GET',
                self::callback(static fn(array $options): bool => ($options['allow_redirects'] ?? null) === false),
            )
            ->willReturn(new Response($body, 302, ['Location' => 'http://127.0.0.1/private']));

        $tool = new ImportFromUrlTool(
            $this->getService(TableAccessService::class),
            $this->getService(WorkspaceContextService::class),
            $requestFactory,
            $this->readToolDependency('capabilityManifest'),
            $this->readToolDependency('localMode'),
            $this->readToolDependency('outboundUrlGuard'),
        );
        $result = $tool->execute([
            'url' => 'https://93.184.216.34/article',
            'targetPid' => 1,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('status 302', $this->getFirstTextContent($result));
    }

    public function testSchemaConservativelyAdvertisesExecuteModeAsWrite(): void
    {
        self::assertFalse($this->tool->getSchema()['annotations']['readOnlyHint']);
    }

    public function testResponseBodyIsRejectedWhileStreamingPastFiveMegabytes(): void
    {
        $body = new Stream('php://temp', 'rw');
        $body->write(str_repeat('x', (5 * 1024 * 1024) + 1));
        $body->rewind();
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects($this->once())
            ->method('request')
            ->willReturn(new Response($body, 200, ['Content-Type' => 'text/html']));

        $tool = new ImportFromUrlTool(
            $this->getService(TableAccessService::class),
            $this->getService(WorkspaceContextService::class),
            $requestFactory,
            $this->readToolDependency('capabilityManifest'),
            $this->readToolDependency('localMode'),
            $this->readToolDependency('outboundUrlGuard'),
        );
        $result = $tool->execute([
            'url' => 'https://93.184.216.34/oversized',
            'targetPid' => 1,
        ]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('exceeds maximum size', $this->getFirstTextContent($result));
    }

    private function readToolDependency(string $property): object
    {
        $reflection = new \ReflectionProperty($this->tool, $property);
        $value = $reflection->getValue($this->tool);
        self::assertIsObject($value);
        return $value;
    }
}
