<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\X402\GetPaidContentTool;
use Hn\McpServer\MCP\Tool\X402\GetPaymentStatsTool;
use Hn\McpServer\MCP\Tool\X402\ListPaidContentTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class X402ToolsTest extends AbstractFunctionalTest
{
    public function testListPaidContentReturnsConfigurationInfoWhenExtensionMissing(): void
    {
        $tool = $this->getService(ListPaidContentTool::class);
        $result = $tool->execute([]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertSame('configuration_info', $data['status']);
        self::assertSame('not installed', $data['x402_paywall_extension']);
        self::assertSame([], $data['pages']);
    }

    public function testGetPaidContentReturnsConfigurationInfoWhenExtensionMissing(): void
    {
        $tool = $this->getService(GetPaidContentTool::class);
        $result = $tool->execute(['pageUid' => 1]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertSame('configuration_info', $data['status']);
        self::assertSame(1, $data['pageUid']);
        self::assertSame('not installed', $data['x402_paywall_extension']);
    }

    public function testGetPaymentStatsReturnsConfigurationInfoWhenExtensionMissing(): void
    {
        $tool = $this->getService(GetPaymentStatsTool::class);
        $result = $tool->execute([]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertSame('configuration_info', $data['status']);
        self::assertSame('not installed', $data['x402_paywall_extension']);
        self::assertSame('not found', $data['payment_log_table']);
    }
}
