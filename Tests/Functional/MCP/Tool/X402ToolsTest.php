<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\X402\GetPaidContentTool;
use Hn\McpServer\MCP\Tool\X402\GetPaymentStatsTool;
use Hn\McpServer\MCP\Tool\X402\ListPaidContentTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Service\X402\X402ContentAccessService;
use Hn\McpServer\Service\X402\X402PaymentRequirement;
use Hn\McpServer\Service\X402\X402PaymentVerifierInterface;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class X402ToolsTest extends AbstractFunctionalTest
{
    public function testListPaidContentReturnsConfigurationInfoWhenExtensionMissing(): void
    {
        $tool = $this->getService(ListPaidContentTool::class);
        $result = $tool->execute([]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));

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

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));

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

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertSame('configuration_info', $data['status']);
        self::assertSame('not installed', $data['x402_paywall_extension']);
        self::assertSame('not found', $data['payment_log_table']);
    }

    public function testForgedBase64JsonProofIsDeniedByVerifier(): void
    {
        $this->installPaywallColumns();
        $this->enablePaywallOnPage(1);

        $verifier = new class implements X402PaymentVerifierInterface {
            public bool $verifyCalled = false;

            public function prepareRequirement(
                int $pageUid,
                string $price,
                string $description,
            ): X402PaymentRequirement {
                $data = [
                    'scheme' => 'exact',
                    'resource' => 'https://example.test/api/v1/content/' . $pageUid,
                    'maxAmountRequired' => '10000',
                ];
                return new X402PaymentRequirement(
                    base64_encode((string)json_encode($data, JSON_THROW_ON_ERROR)),
                    $data,
                    'USDC',
                    'base-sepolia',
                    new \stdClass(),
                );
            }

            public function verifyAndSettle(string $paymentProof, X402PaymentRequirement $requirement): bool
            {
                $this->verifyCalled = true;
                return false;
            }
        };
        $tool = $this->createGetPaidContentTool($verifier);
        $forgedProof = base64_encode((string)json_encode([
            'signature' => 'attacker-controlled',
        ], JSON_THROW_ON_ERROR));

        $result = $tool->execute(['pageUid' => 1, 'paymentProof' => $forgedProof]);

        self::assertTrue($verifier->verifyCalled, 'The real verifier boundary must be called for every proof.');
        self::assertTrue($result->isError, 'A syntactically plausible forged proof must not unlock content.');
        self::assertStringContainsString('verification or settlement failed', $this->getFirstTextContent($result));
        self::assertStringNotContainsString('Welcome to our homepage', $this->getFirstTextContent($result));
    }

    public function testGatedContentFailsClosedWhenPaywallVerifierIsUnavailable(): void
    {
        $this->installPaywallColumns();
        $this->enablePaywallOnPage(1);

        $tool = $this->getService(GetPaidContentTool::class);
        $result = $tool->execute(['pageUid' => 1]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('verification is unavailable', $this->getFirstTextContent($result));
        self::assertStringNotContainsString('Welcome to our homepage', $this->getFirstTextContent($result));
    }

    public function testNonAdminOutsideDatabaseMountCannotDiscoverGatedPage(): void
    {
        $this->installPaywallColumns();
        $this->enablePaywallOnPage(1);
        $backendUser = $this->getBackendUser();
        $backendUser->user['admin'] = 0;
        $backendUser->groupData['tables_select'] = 'pages,tt_content';
        $backendUser->groupData['non_exclude_fields'] = implode(',', [
            'pages:tx_x402_paywall_enabled',
            'pages:tx_x402_paywall_price',
            'pages:tx_x402_paywall_description',
        ]);
        $backendUser->groupData['webmounts'] = '2';

        $tool = $this->getService(GetPaidContentTool::class);
        $result = $tool->execute(['pageUid' => 1]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('outside your database mounts', $this->getFirstTextContent($result));
    }

    public function testNonAdminWithoutContentTablePermissionCannotReadPaidContent(): void
    {
        $this->installPaywallColumns();
        $this->enablePaywallOnPage(1);
        $backendUser = $this->getBackendUser();
        $backendUser->user['admin'] = 0;
        $backendUser->groupData['tables_select'] = 'pages';
        $backendUser->groupData['webmounts'] = '1';

        $tool = $this->getService(GetPaidContentTool::class);
        $result = $tool->execute(['pageUid' => 1]);

        self::assertTrue($result->isError);
        self::assertStringContainsString("Cannot access table 'tt_content'", $this->getFirstTextContent($result));
    }

    public function testPaymentStatsRequiresAdminEvenWhenPaywallIsMissing(): void
    {
        $this->getBackendUser()->user['admin'] = 0;

        $tool = $this->getService(GetPaymentStatsTool::class);
        $result = $tool->execute([]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('admin privileges', $this->getFirstTextContent($result));
    }

    public function testVerifiedResponseOmitsHiddenContentElements(): void
    {
        $this->installPaywallColumns();
        $this->enablePaywallOnPage(1);
        $verifier = new class implements X402PaymentVerifierInterface {
            public function prepareRequirement(
                int $pageUid,
                string $price,
                string $description,
            ): X402PaymentRequirement {
                return new X402PaymentRequirement(
                    base64_encode('{}'),
                    ['resource' => 'https://example.test/api/v1/content/' . $pageUid],
                    'USDC',
                    'base-sepolia',
                    new \stdClass(),
                );
            }

            public function verifyAndSettle(string $paymentProof, X402PaymentRequirement $requirement): bool
            {
                return true;
            }
        };
        $tool = $this->createGetPaidContentTool($verifier);

        $result = $tool->execute(['pageUid' => 1, 'paymentProof' => 'test-proof']);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $data = json_decode($this->getFirstTextContent($result), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $content = $data['content'] ?? null;
        self::assertIsArray($content);
        $headers = [];
        foreach ($content as $element) {
            if (is_array($element) && is_string($element['header'] ?? null)) {
                $headers[] = $element['header'];
            }
        }
        self::assertContains('Welcome Header', $headers);
        self::assertNotContains('Hidden Content', $headers);
    }

    private function installPaywallColumns(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $hasPaywallColumn = false;
        foreach ($connection->createSchemaManager()->introspectTableColumnsByUnquotedName('pages') as $column) {
            if ($column->getObjectName()->getIdentifier()->getValue() === 'tx_x402_paywall_enabled') {
                $hasPaywallColumn = true;
                break;
            }
        }
        if (!$hasPaywallColumn) {
            $connection->executeStatement('ALTER TABLE pages ADD COLUMN tx_x402_paywall_enabled INTEGER DEFAULT 0 NOT NULL');
            $connection->executeStatement("ALTER TABLE pages ADD COLUMN tx_x402_paywall_price VARCHAR(20) DEFAULT '' NOT NULL");
            $connection->executeStatement("ALTER TABLE pages ADD COLUMN tx_x402_paywall_description VARCHAR(255) DEFAULT '' NOT NULL");
        }

        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca)) {
            self::fail('TCA is unavailable.');
        }
        $pages = $tca['pages'] ?? [];
        $pages = is_array($pages) ? $pages : [];
        $columns = $pages['columns'] ?? [];
        $columns = is_array($columns) ? $columns : [];
        $columns['tx_x402_paywall_enabled'] = [
            'exclude' => true,
            'config' => ['type' => 'check', 'default' => 0],
        ];
        $columns['tx_x402_paywall_price'] = [
            'exclude' => true,
            'config' => ['type' => 'input', 'default' => ''],
        ];
        $columns['tx_x402_paywall_description'] = [
            'exclude' => true,
            'config' => ['type' => 'input', 'default' => ''],
        ];
        $pages['columns'] = $columns;
        $tca['pages'] = $pages;
        $GLOBALS['TCA'] = $tca;
        GeneralUtility::makeInstance(TcaSchemaFactory::class)->rebuild($tca);
    }

    private function enablePaywallOnPage(int $pageUid): void
    {
        $this->connectionPool->getConnectionForTable('pages')->update('pages', [
            'tx_x402_paywall_enabled' => 1,
            'tx_x402_paywall_price' => '0.01',
            'tx_x402_paywall_description' => 'Paid test page',
        ], ['uid' => $pageUid]);
    }

    private function createGetPaidContentTool(X402PaymentVerifierInterface $paymentVerifier): GetPaidContentTool
    {
        return new GetPaidContentTool(
            $this->getService(TableAccessService::class),
            $this->getService(WorkspaceContextService::class),
            $this->connectionPool,
            new X402ContentAccessService(
                $this->connectionPool,
                $this->getService(TableAccessService::class),
                $this->context,
                $this->languageService,
            ),
            $paymentVerifier,
        );
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            self::fail('Backend user is unavailable.');
        }

        return $backendUser;
    }
}
