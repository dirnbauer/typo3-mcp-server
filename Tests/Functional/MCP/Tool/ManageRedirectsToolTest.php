<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ManageRedirectsTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class ManageRedirectsToolTest extends AbstractFunctionalTest
{
    public function testReturnsConfigurationInfoWhenRedirectsSurfaceIsMissing(): void
    {
        $tool = $this->getService(ManageRedirectsTool::class);
        $result = $tool->execute([
            'action' => 'list',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertSame('configuration_info', $data['status']);
        self::assertSame('ManageRedirects', $data['tool']);
        self::assertSame('not available', $data['redirects_extension']);
    }
}
