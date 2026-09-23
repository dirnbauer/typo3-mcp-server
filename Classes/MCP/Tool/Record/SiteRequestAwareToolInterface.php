<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

/**
 * A record tool that hands field values - rich text in particular - to
 * DataHandler. For such a call AbstractRecordTool::execute() publishes a
 * request for the site of the page resolveSiteRequestPageId() names when the
 * tool runs without an HTTP request (CLI commands, the stdio server), and
 * shows the endpoint's frontend request as a backend request over HTTP.
 * See SiteRequestContext.
 */
interface SiteRequestAwareToolInterface
{
    /**
     * Whether this call hands field values to DataHandler (a delete, a move
     * or an analysis does not).
     *
     * @param array<string, mixed> $params the tool arguments
     */
    public function needsSiteRequest(array $params): bool;

    /**
     * The page the written record lives on: the target page of a create or
     * copy, the page record itself, or the stored pid of an existing record.
     * Null when the call has no page context (a root-level record, invalid
     * parameters); validation errors are reported by the tool itself.
     *
     * @param array<string, mixed> $params the tool arguments
     */
    public function resolveSiteRequestPageId(array $params): ?int;
}
