<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Discovery\DirectoryWalk;
use Qualimetrix\Tests\Analysis\Run\Support\Discovery\RefusingRecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The failure this covers is a race — a branch that answers the readability
 * check and refuses the descent that follows it — so the refusal is injected
 * rather than staged on a filesystem: there is no hook between the check and
 * the descent for a fixture to change permissions in, and a test that raced
 * for one would report the scheduler, not the walk.
 */
#[CoversClass(DirectoryWalk::class)]
final class DirectoryWalkTest extends TestCase
{
    #[Test]
    public function itHandsBackTheBranchItCouldNotEnter(): void
    {
        $refused = [];

        $this->walk('/tree/blocked', $refused);

        self::assertSame(['/tree/blocked' => 'Failed to open directory: Permission denied'], $refused);
    }

    /** The point of absorbing the refusal: the rest of the tree still arrives. */
    #[Test]
    public function itKeepsWalkingTheSiblingsOfTheBranchItCouldNotEnter(): void
    {
        $refused = [];

        self::assertSame(
            ['/tree/open/Kept.php'],
            $this->walk('/tree/blocked', $refused),
        );
    }

    /** Nothing is claimed about a walk that met no refusal. */
    #[Test]
    public function itStaysSilentWhenEveryBranchOpens(): void
    {
        $refused = [];

        self::assertSame(
            ['/tree/blocked/Hidden.php', '/tree/open/Kept.php'],
            $this->walk(null, $refused),
        );
        self::assertSame([], $refused);
    }

    /**
     * @param array<string, string> $refused
     *
     * @return list<string>
     */
    private function walk(?string $refusedChild, array &$refused): array
    {
        $walk = new DirectoryWalk(
            RefusingRecursiveIterator::tree($refusedChild),
            RecursiveIteratorIterator::LEAVES_ONLY,
            static function (string $pathname, string $message) use (&$refused): void {
                $refused[$pathname] = $message;
            },
        );

        $seen = [];
        foreach ($walk as $entry) {
            $seen[] = $entry instanceof SplFileInfo ? $entry->getPathname() : (string) $entry;
        }
        sort($seen);

        return $seen;
    }
}
