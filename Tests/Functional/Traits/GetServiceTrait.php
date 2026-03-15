<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Traits;

/**
 * Trait providing getService() for tests extending FunctionalTestCase directly.
 *
 * Tests extending AbstractFunctionalTest already have this method.
 */
trait GetServiceTrait
{
    /**
     * Resolve a service from the DI container.
     *
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    protected function getService(string $className): object
    {
        $service = $this->getContainer()->get($className);
        \assert($service instanceof $className);
        /** @var T $service */
        return $service;
    }
}
