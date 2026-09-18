<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Middleware;

use Hn\McpServer\Middleware\BackendUserConfigurationMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class BackendUserConfigurationMiddlewareTest extends TestCase
{
    private mixed $originalBackendUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBackendUser = $GLOBALS['BE_USER'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['BE_USER'] = $this->originalBackendUser;
        parent::tearDown();
    }

    #[Test]
    public function processRepairsMissingUcDefaultsAndPersistsThem(): void
    {
        $backendUser = new class extends BackendUserAuthentication {
            public int $writeUcCalls = 0;

            public function writeUC(): void
            {
                $this->writeUcCalls++;
            }
        };
        $backendUser->user = ['uid' => 5];
        $backendUser->uc = ['lang' => 'de'];
        $GLOBALS['BE_USER'] = $backendUser;

        $response = self::createStub(ResponseInterface::class);
        $handler = new class ($response) implements RequestHandlerInterface {
            public int $calls = 0;

            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls++;
                return $this->response;
            }
        };

        $subject = new BackendUserConfigurationMiddleware();
        $result = $subject->process(
            self::createStub(ServerRequestInterface::class),
            $handler,
        );

        self::assertSame($response, $result);
        self::assertSame(1, $handler->calls);
        self::assertSame(1, $backendUser->writeUcCalls);
        self::assertEquals(
            ['lang' => 'de', 'titleLen' => 50, 'moduleData' => []],
            array_intersect_key($backendUser->uc, ['lang' => 1, 'titleLen' => 1, 'moduleData' => 1]),
        );
    }

    #[Test]
    public function processLeavesCompleteUcUntouched(): void
    {
        $backendUser = new class extends BackendUserAuthentication {
            public int $writeUcCalls = 0;

            public function writeUC(): void
            {
                $this->writeUcCalls++;
            }
        };
        $backendUser->user = ['uid' => 5];
        $backendUser->uc = [
            'moduleData' => [],
            'titleLen' => 50,
            'lang' => 'en',
            'emailMeAtLogin' => 0,
            'edit_docModuleUpload' => '1',
        ];
        $GLOBALS['BE_USER'] = $backendUser;

        $response = self::createStub(ResponseInterface::class);
        $handler = new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $subject = new BackendUserConfigurationMiddleware();
        $result = $subject->process(
            self::createStub(ServerRequestInterface::class),
            $handler,
        );

        self::assertSame($response, $result);
        self::assertSame(0, $backendUser->writeUcCalls);
        self::assertEquals(
            ['titleLen' => 50, 'lang' => 'en'],
            array_intersect_key($backendUser->uc, ['titleLen' => 1, 'lang' => 1]),
        );
    }
}
