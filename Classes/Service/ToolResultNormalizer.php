<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/** Adds structuredContent without removing the legacy text representation. */
final readonly class ToolResultNormalizer
{
    public function normalize(CallToolResult $result): CallToolResult
    {
        if ($result->isError === true || $result->structuredContent !== null || count($result->content) !== 1) {
            return $result;
        }
        $content = $result->content[0];
        if (!$content instanceof TextContent) {
            return $result;
        }

        try {
            $structured = json_decode($content->text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $result;
        }

        if ($structured === null) {
            $result->setStructuredContentNull();
        } else {
            $result->structuredContent = $structured;
        }
        return $result;
    }
}
