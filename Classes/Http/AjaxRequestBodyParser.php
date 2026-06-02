<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Parses backend AJAX POST bodies that may arrive as form fields or JSON.
 */
final class AjaxRequestBodyParser
{
    /**
     * @return array<string, string>
     */
    public function parseStringFields(ServerRequestInterface $request): array
    {
        $fromParsedBody = $this->normalizeStringMap($request->getParsedBody());
        if ($fromParsedBody !== []) {
            return $fromParsedBody;
        }

        $rawBody = $request->getBody()->getContents();
        $request->getBody()->rewind();
        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeStringMap($decoded);
    }

    /**
     * @return array<string, string>
     */
    private function normalizeStringMap(mixed $source): array
    {
        if (!is_array($source)) {
            return [];
        }

        $result = [];
        foreach ($source as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
