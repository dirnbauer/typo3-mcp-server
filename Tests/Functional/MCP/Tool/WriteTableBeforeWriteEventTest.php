<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\Event\BeforeRecordWriteEvent;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reroutes `data.pid` from a BeforeRecordWriteEvent listener; the tool must
 * pick the listener's page up after dispatching the event.
 */
final class ReroutePidListener
{
    public function __construct(private readonly int $reroutedPid) {}

    public function __invoke(BeforeRecordWriteEvent $event): void
    {
        if ($event->getAction() !== 'create') {
            return;
        }
        $data = $event->getData();
        $data['pid'] = $this->reroutedPid;
        $event->setData($data);
    }
}

/**
 * Verify that a `BeforeRecordWriteEvent` listener can reroute the target page
 * by editing `data.pid` before the create runs. The tool must re-read pid
 * from data after dispatching the event so listener-driven changes take
 * effect. Without that, the listener's pid mutation would be silently ignored
 * and the record would land on the originally requested page.
 */
final class WriteTableBeforeWriteEventTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    public function testBeforeWriteListenerCanReroutePidOnCreate(): void
    {
        $requestedPid = 1; // "Home" in the standard fixtures
        $reroutedPid = 2;  // "About"

        $container = GeneralUtility::getContainer();
        $container->set(ReroutePidListener::class, new ReroutePidListener($reroutedPid));
        $container->get(ListenerProvider::class)->addListener(BeforeRecordWriteEvent::class, ReroutePidListener::class);

        $result = $this->get(WriteTableTool::class)->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => $requestedPid,
                'CType' => 'textmedia',
                'header' => 'Listener Reroute Test',
                'colPos' => 0,
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $payload = json_decode((string)$result->content[0]->text, true);
        $newUid = (int)($payload['uid'] ?? 0);
        self::assertGreaterThan(0, $newUid, 'Expected a created record uid in the response');

        // Confirm the record landed on the listener's chosen page, not the
        // page the caller originally specified.
        $record = BackendUtility::getRecord('tt_content', $newUid, 'pid');
        self::assertNotNull($record, "Created record $newUid not found");
        self::assertSame(
            $reroutedPid,
            (int)$record['pid'],
            'Listener edited data.pid but the record landed on the originally requested page — '
            . 'pid must be re-read from data after BeforeRecordWriteEvent dispatch.',
        );
    }
}
