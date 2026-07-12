<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\X402;

use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Service\X402\X402ContentAccessService;
use Hn\McpServer\Service\X402\X402PaymentRequirement;
use Hn\McpServer\Service\X402\X402PaymentVerifierInterface;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Retrieves visible TYPO3 content after facilitator-backed x402 verification.
 */
final class GetPaidContentTool extends AbstractRecordTool
{
    private const MAX_PAYMENT_PROOF_BYTES = 64 * 1024;

    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly ConnectionPool $connectionPool,
        private readonly X402ContentAccessService $contentAccess,
        private readonly X402PaymentVerifierInterface $paymentVerifier,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'Retrieve visible x402-gated TYPO3 content. A gated page is returned only after the '
                . 'configured x402 facilitator verifies paymentProof. Without a proof, the tool returns the exact '
                . 'PAYMENT-REQUIRED value that must be paid.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'pageUid' => [
                        'type' => 'integer',
                        'description' => 'Page UID to retrieve paid content from',
                    ],
                    'paymentProof' => [
                        'type' => 'string',
                        'description' => 'x402 PAYMENT-SIGNATURE value verified by the configured facilitator.',
                        'maxLength' => self::MAX_PAYMENT_PROOF_BYTES,
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Optional TYPO3 site language ISO code.',
                    ],
                ],
                'required' => ['pageUid'],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $pageUidRaw = $params['pageUid'] ?? 0;
        $pageUid = is_numeric($pageUidRaw) ? (int)$pageUidRaw : 0;
        $paymentProofRaw = $params['paymentProof'] ?? '';
        $paymentProof = is_string($paymentProofRaw) ? trim($paymentProofRaw) : '';
        $language = isset($params['language']) && is_string($params['language'])
            ? trim($params['language'])
            : null;

        if ($pageUid <= 0) {
            return $this->createErrorResult('pageUid is required and must be positive');
        }
        if (strlen($paymentProof) > self::MAX_PAYMENT_PROOF_BYTES) {
            return $this->createErrorResult('paymentProof exceeds the 64 KiB limit.');
        }

        if (!$this->hasPaywallColumns()) {
            return $this->returnConfigStatus($pageUid);
        }

        // These calls also establish the requested read-workspace context.
        $this->ensureTableAccess('pages', 'read');
        $this->ensureTableAccess('tt_content', 'read');

        $page = $this->contentAccess->getPage($pageUid, $language);
        if ($page === null) {
            return $this->createErrorResult("Page $pageUid not found or not visible");
        }

        $isGated = (bool)($page['tx_x402_paywall_enabled'] ?? false);
        if (!$isGated) {
            return $this->returnFullContent($pageUid, $page, false, $language);
        }

        $priceRaw = $page['tx_x402_paywall_price'] ?? '0.01';
        $price = is_scalar($priceRaw) && (string)$priceRaw !== '' ? (string)$priceRaw : '0.01';
        $descRaw = $page['tx_x402_paywall_description'] ?? $page['title'] ?? '';
        $description = is_scalar($descRaw) ? (string)$descRaw : '';
        $requirement = $this->paymentVerifier->prepareRequirement($pageUid, $price, $description);

        // A gated page must never be exposed if the optional integration is
        // absent, its site configuration is invalid, or requirement building fails.
        if (!$requirement instanceof X402PaymentRequirement) {
            return $this->createErrorResult(
                'x402 payment verification is unavailable or not configured for this page. Gated content remains locked.',
            );
        }

        if ($paymentProof === '') {
            return $this->returnPaymentRequired($pageUid, $description, $page, $requirement);
        }

        if (!$this->paymentVerifier->verifyAndSettle($paymentProof, $requirement)) {
            return $this->createErrorResult('Payment verification or settlement failed. Gated content remains locked.');
        }

        return $this->returnFullContent($pageUid, $page, true, $language);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function returnFullContent(
        int $pageUid,
        array $page,
        bool $paid,
        ?string $language,
    ): CallToolResult {
        $result = [
            'page' => $this->contentAccess->projectPage($page),
            'content' => $this->contentAccess->getContentElements($pageUid, $language),
            'x402' => [
                'paid' => $paid,
                'verification' => $paid ? 'facilitator_verified' : 'not_required',
            ],
        ];

        return $this->jsonResult($result);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function returnPaymentRequired(
        int $pageUid,
        string $description,
        array $page,
        X402PaymentRequirement $requirement,
    ): CallToolResult {
        $pageData = $this->contentAccess->projectPage($page);
        $result = [
            'status' => 'payment_required',
            'page' => [
                'uid' => $pageUid,
                'title' => $pageData['title'] ?? '',
                'description' => $description,
            ],
            'x402' => [
                'version' => '2',
                'currency' => $requirement->currency,
                'network' => $requirement->network,
                'paymentRequired' => $requirement->encoded,
                'requirements' => [$requirement->data],
                'instruction' => 'Pay this exact requirement and pass the resulting PAYMENT-SIGNATURE as paymentProof.',
            ],
            'preview' => [
                'abstract' => $pageData['abstract'] ?? '',
                'subtitle' => $pageData['subtitle'] ?? '',
            ],
        ];

        return $this->jsonResult($result);
    }

    private function hasPaywallColumns(): bool
    {
        return $this->columnExists('pages', 'tx_x402_paywall_enabled')
            && $this->columnExists('pages', 'tx_x402_paywall_price')
            && $this->columnExists('pages', 'tx_x402_paywall_description');
    }

    private function returnConfigStatus(int $pageUid): CallToolResult
    {
        return $this->jsonResult([
            'status' => 'configuration_info',
            'pageUid' => $pageUid,
            'x402_paywall_extension' => 'not installed',
            'message' => 'Install webconsulting/typo3-x402-paywall to access paid content.',
        ]);
    }

    private function columnExists(string $table, string $column): bool
    {
        if ($table === '') {
            return false;
        }
        try {
            $connection = $this->connectionPool->getConnectionForTable($table);
            foreach ($connection->createSchemaManager()->introspectTableColumnsByUnquotedName($table) as $tableColumn) {
                if ($tableColumn->getObjectName()->getIdentifier()->getValue() === $column) {
                    return true;
                }
            }
            return false;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResult(array $data): CallToolResult
    {
        return new CallToolResult([
            new TextContent(json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            )),
        ]);
    }
}
