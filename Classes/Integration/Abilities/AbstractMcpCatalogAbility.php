<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\Abilities;

use Hn\McpServer\Service\BackendUserContextService;
use Hn\McpServer\Service\McpToolCatalogService;

/** @internal Loaded only when webconsulting/typo3-abilities is installed. */
abstract class AbstractMcpCatalogAbility extends AbstractMcpAbility
{
    public function __construct(
        protected readonly McpToolCatalogService $catalog,
        ?BackendUserContextService $backendUserContext = null,
    ) {
        parent::__construct($backendUserContext);
    }

}
