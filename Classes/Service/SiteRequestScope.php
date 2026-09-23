<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The request {@see SiteRequestContext} published for one tool call.
 * leave() removes it again (or restores the endpoint's request it stood in
 * for), so a long-running stdio server never carries one call's site into
 * the next; annotate() tells the client when the site was a fallback rather
 * than the record's own.
 */
final readonly class SiteRequestScope
{
    private function __construct(
        public ?ServerRequestInterface $request,
        public ?string $siteIdentifier,
        public ?string $fallbackNote,
        private ?ServerRequestInterface $replacedRequest = null,
    ) {}

    /** A backend request was already active; nothing was published. */
    public static function inactive(): self
    {
        return new self(null, null, null);
    }

    public static function published(ServerRequestInterface $request, ?string $siteIdentifier, ?string $fallbackNote): self
    {
        return new self($request, $siteIdentifier, $fallbackNote);
    }

    /** $request stands in for $replacedRequest until leave() restores it. */
    public static function replaced(ServerRequestInterface $request, ServerRequestInterface $replacedRequest): self
    {
        return new self($request, null, null, $replacedRequest);
    }

    public function isPublished(): bool
    {
        return $this->request !== null;
    }

    public function isFallback(): bool
    {
        return $this->fallbackNote !== null;
    }

    public function leave(): void
    {
        if ($this->request === null || ($GLOBALS['TYPO3_REQUEST'] ?? null) !== $this->request) {
            return;
        }
        if ($this->replacedRequest !== null) {
            $GLOBALS['TYPO3_REQUEST'] = $this->replacedRequest;
        } else {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
    }

    /**
     * Add a "siteContext" entry to a JSON object result, or a note line to a
     * plain-text result, when the site was a fallback. Other results are
     * returned unchanged.
     */
    public function annotate(CallToolResult $result): CallToolResult
    {
        if ($this->fallbackNote === null || count($result->content) !== 1 || !$result->content[0] instanceof TextContent) {
            return $result;
        }

        $text = $result->content[0]->text;
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        if (is_array($decoded) && !array_is_list($decoded)) {
            $decoded['siteContext'] = [
                'site' => $this->siteIdentifier,
                'fallback' => true,
                'note' => $this->fallbackNote,
            ];
            $text = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } else {
            $text = rtrim($text) . "\n\nNote: " . $this->fallbackNote;
        }

        return new CallToolResult([new TextContent($text)], $result->isError);
    }
}
