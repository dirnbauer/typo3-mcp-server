<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\Abilities;

use Hn\McpServer\Service\AbilityBackendUserContextService;
use Hn\McpServer\Service\McpToolCatalogService;

/** @internal Loaded only when webconsulting/typo3-abilities is installed. */
abstract class AbstractMcpCatalogAbility extends AbstractMcpAbility
{
    public function __construct(
        protected readonly McpToolCatalogService $catalog,
        ?AbilityBackendUserContextService $backendUserContext = null,
    ) {
        parent::__construct($backendUserContext);
    }

}
