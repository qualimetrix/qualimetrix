<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DistributedPackage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * {@see ScratchTree}'s two guards, exercised rather than assumed.
 *
 * Neither is reachable through the controls that use the class: their trees
 * hold no symlink and no unreadable directory, so both guards would stay green
 * if they were deleted. A guard nothing executes is the shape this group
 * refuses elsewhere, and the one the class exists to stop being written per
 * control.
 *
 * The symlink case is the older guard and was carried by every copy; the
 * `scandir()` case was not, and was added when consolidating showed that the
 * copies disagreed about which of the two they handled.
 */
final class ScratchTreeRefusesWhatItCannotRemoveTest extends TestCase
{
    #[Test]
    public function itDeletesALinkAndNotWhatTheLinkAddresses(): void
    {
        $tree = ScratchTree::create('qmx-scratch-link-');
        $outside = ScratchTree::create('qmx-scratch-outside-');

        file_put_contents($outside . '/keep.txt', 'outside the tree');
        self::assertTrue(symlink($outside, $tree . '/link'));

        try {
            ScratchTree::remove($tree);

            self::assertDirectoryDoesNotExist($tree, 'The tree survived its own removal.');
            self::assertFileExists(
                $outside . '/keep.txt',
                'Removal followed a directory symlink and deleted outside the tree it was given.',
            );
        } finally {
            ScratchTree::remove($outside);
        }
    }

    #[Test]
    public function itNamesTheDirectoryItCannotReadInsteadOfCirclingOnIt(): void
    {
        $tree = ScratchTree::create('qmx-scratch-unreadable-');
        $closed = $tree . '/closed';

        self::assertTrue(mkdir($closed, 0777, true));
        file_put_contents($closed . '/hidden.txt', 'unreachable');
        self::assertTrue(chmod($closed, 0000));

        try {
            // Running as root, a mode of 0000 does not stop a read, and the
            // branch under test cannot be entered at all. Skipped rather than
            // asserted, so this never reports a guard as proven on a machine
            // that could not have exercised it.
            if (@scandir($closed) !== false) {
                self::markTestSkipped('This process can read a 0000 directory, so scandir() cannot be made to fail.');
            }

            try {
                ScratchTree::remove($tree);
                self::fail('An unreadable directory was not refused, so the tree is removed on a guess.');
            } catch (RuntimeException $refusal) {
                self::assertStringContainsString($closed, $refusal->getMessage(), 'The refusal does not name the directory.');
            }
        } finally {
            chmod($closed, 0777);
            ScratchTree::remove($tree);
        }
    }

    #[Test]
    public function itRefusesAPathItCannotCreate(): void
    {
        $blocker = ScratchTree::create('qmx-scratch-blocked-') . '/file';

        file_put_contents($blocker, 'not a directory');

        try {
            ScratchTree::create(basename(\dirname($blocker)) . '/file/');
            self::fail('A path under an existing file was created, which cannot be a directory.');
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('Could not create', $refusal->getMessage());
        } finally {
            ScratchTree::remove(\dirname($blocker));
        }
    }

    #[Test]
    public function itReturnsAPathThatIsAlreadyResolved(): void
    {
        $tree = ScratchTree::create('qmx-scratch-resolved-');

        try {
            self::assertSame(
                realpath($tree),
                $tree,
                'The path is not resolved, so a caller comparing it against one reported from inside the tree fails on macOS.',
            );
            self::assertDirectoryExists($tree);
            self::assertSame(['.', '..'], scandir($tree), 'The new tree is not empty.');
        } finally {
            ScratchTree::remove($tree);
        }
    }

    #[Test]
    public function itIgnoresAPathThatIsNotADirectory(): void
    {
        $absent = sys_get_temp_dir() . '/qmx-scratch-absent-' . bin2hex(random_bytes(6));

        ScratchTree::remove($absent);

        self::assertDirectoryDoesNotExist($absent);
    }
}
