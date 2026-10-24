<?php

declare(strict_types=1);

namespace Webconsulting\RuleDocGeneratorContracts\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PublicApiTest extends TestCase
{
    public function testAllUpstreamSymbolsAndPublicSignaturesRemainCompatible(): void
    {
        /** @var array<class-string, array<string, mixed>> $expected */
        $expected = json_decode((string)file_get_contents(__DIR__ . '/Fixtures/upstream-11.2-api.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($expected as $class => $signature) {
            $reflection = new ReflectionClass($class);
            $methods = [];
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $parameters = [];
                foreach ($method->getParameters() as $parameter) {
                    $parameters[] = [
                        'name' => $parameter->getName(),
                        'type' => (string)$parameter->getType(),
                        'optional' => $parameter->isOptional(),
                        'byReference' => $parameter->isPassedByReference(),
                        'variadic' => $parameter->isVariadic(),
                    ];
                }
                $methods[$method->getName()] = [
                    'returnType' => (string)$method->getReturnType(),
                    'parameters' => $parameters,
                    'static' => $method->isStatic(),
                ];
            }
            self::assertSame($signature, [
                'interface' => $reflection->isInterface(),
                'abstract' => $reflection->isAbstract(),
                'final' => $reflection->isFinal(),
                'parent' => $reflection->getParentClass() ? $reflection->getParentClass()->getName() : null,
                'interfaces' => $reflection->getInterfaceNames(),
                'methods' => $methods,
            ], $class);
        }
        self::assertCount(13, $expected);
    }
}
