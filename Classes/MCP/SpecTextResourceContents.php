<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Mcp\Types\TextResourceContents;

/**
 * Text resource contents with MCP-spec JSON output.
 *
 * The SDK's base ResourceContents serializer currently leaks its internal
 * ExtraFieldsTrait storage as "extraFields", which strict MCP clients reject.
 */
final class SpecTextResourceContents extends TextResourceContents
{
    public function jsonSerialize(): mixed
    {
        $data = [
            'uri' => $this->uri,
        ];

        if ($this->mimeType !== null) {
            $data['mimeType'] = $this->mimeType;
        }

        $data['text'] = $this->text;

        return $data;
    }
}
