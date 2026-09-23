<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;

final class WorkspacePageMoveTest extends AbstractFunctionalTest
{
    private WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeTool = $this->getService(WriteTableTool::class);
    }

    public function testCircularMoveThroughADescendantStagedInTheWorkspace(): void
    {
        // Page 6 lives under 1. Staged below 2, it becomes 2's child in the
        // workspace while its live pid still reads 1.
        $staged = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 6,
            'data' => ['pid' => 2],
        ]);
        self::assertFalse($staged->isError, json_encode($staged->jsonSerialize(), JSON_THROW_ON_ERROR));

        // Moving 2 below 6 now closes the cycle 2 -> 6 -> 2, visible only in the
        // workspace.
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 2,
            'data' => ['pid' => 6, 'title' => 'Must not be changed'],
        ]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        self::assertStringContainsString('Error moving record', $result->content[0]->text);

        $versionsBelowSix = $this->getConnectionForTable('pages')->fetchAllAssociative(
            'SELECT uid FROM pages WHERE pid = 6 AND t3ver_oid = 2'
        );
        self::assertSame([], $versionsBelowSix, 'A version of page 2 was moved below page 6.');
        $page = BackendUtility::getRecordWSOL('pages', 2);
        self::assertIsArray($page);
        self::assertSame('About', $page['title']);
    }

    #[DataProvider('circularMovePositions')]
    public function testMoveActionRejectsCyclesBeforeCreatingAVersion(string $position, int $pid): void
    {
        $result = $this->writeTool->execute([
            'action' => 'move', 'table' => 'pages', 'uid' => 2,
            'pid' => $pid, 'position' => $position,
        ]);
        self::assertTrue($result->isError);
        self::assertStringContainsString('into itself or one of its own subpages', $result->content[0]->text);
        self::assertSame(0, $this->getConnectionForTable('pages')->count('*', 'pages', ['t3ver_oid' => 2]));
    }

    public static function circularMovePositions(): array
    {
        return [['top', 4], ['bottom', 2], ['after:5', 1], ['before:4', 1]];
    }

    /**
     * The mirror case: a descendant staged out of the subtree makes a move that
     * live pids would still reject a legitimate one.
     */
    public function testMoveIsAllowedWhenTheDescendantWasStagedOutOfTheSubtree(): void
    {
        // Page 4 lives under 2. Staged under 7, it leaves 2's subtree.
        $staged = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 4,
            'data' => ['pid' => 7],
        ]);
        self::assertFalse($staged->isError, json_encode($staged->jsonSerialize(), JSON_THROW_ON_ERROR));

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 2,
            'data' => ['pid' => 4],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
    }

    /**
     * A destination whose own ancestry already loops must not be walked forever,
     * and must not be reported as a safe place to move a page into. Broken
     * rootlines do occur, hand-edited pid values and botched imports both
     * produce them.
     */
    public function testDestinationWithACircularAncestryIsRefused(): void
    {
        // Make 4 and 5, both live children of 2, each other's parent.
        $connection = $this->getConnectionForTable('pages');
        $connection->update('pages', ['pid' => 5], ['uid' => 4]);
        $connection->update('pages', ['pid' => 4], ['uid' => 5]);

        // Page 6 is unrelated to that loop, so this is no genuine self-move.
        // The walk above page 4 still never terminates on its own.
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 6,
            'data' => ['pid' => 4],
        ]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));

        // Assert the guard's own message, not just that some error came back.
        // Without the guard the move is let through and DataHandler raises a
        // rootline exception a few frames deeper, which also produces an
        // "Error moving record" result, naming a page the caller never mentioned.
        self::assertStringContainsString(
            'rootline of destination pages:4 is circular',
            $result->content[0]->text
        );
    }

}
