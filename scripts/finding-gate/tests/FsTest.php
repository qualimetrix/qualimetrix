<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests {
    require_once __DIR__ . '/Fixtures/FsNativeDelegates.php';

    use Closure;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\Test;
    use PHPUnit\Framework\TestCase;
    use QmxFindingGate\Fs;
    use QmxFindingGate\GateError;
    use QmxFindingGateControls\Shell;
    use RuntimeException;
    use Throwable;

    final class FsTest extends TestCase
    {
        private string $root;

        public static function setUpBeforeClass(): void
        {
            require_once \dirname(__DIR__) . '/classes.php';
            require_once \dirname(__DIR__, 2) . '/finding-gate-controls/Shell.php';
        }

        /** @return iterable<string, array{Closure(string): void, class-string<Throwable>}> */
        public static function removers(): iterable
        {
            yield 'finding gate' => [static function (string $path): void {
                Fs::removeRecursively($path);
            }, GateError::class];
            yield 'controls' => [static function (string $path): void {
                Shell::removeRecursively($path);
            }, RuntimeException::class];
        }

        protected function setUp(): void
        {
            FsNativeProbe::reset();
            $this->root = Fs::temporaryDirectory('finding-gate-fs-test-');
        }

        protected function tearDown(): void
        {
            FsNativeProbe::reset();
            @chmod($this->root . '/blocked', 0o700);
            Fs::removeRecursively($this->root);
        }

        #[Test]
        #[DataProvider('removers')]
        public function itTreatsADirectoryDisappearingBetweenInspectionAndListingAsRemoved(Closure $remove, string $expectedException): void
        {
            $path = $this->root . '/race';
            self::assertTrue(mkdir($path));
            FsNativeProbe::removeAfterDirectoryCheck($path);

            $remove($path);

            self::assertFileDoesNotExist($path);
        }

        #[Test]
        #[DataProvider('removers')]
        public function itTreatsAFileDisappearingDuringUnlinkAsRemoved(Closure $remove, string $expectedException): void
        {
            $path = $this->root . '/file';
            self::assertNotFalse(file_put_contents($path, 'content'));
            FsNativeProbe::removeDuringUnlink($path);

            $remove($path);

            self::assertFileDoesNotExist($path);
        }

        #[Test]
        #[DataProvider('removers')]
        public function itTreatsAFileAndItsParentDisappearingDuringUnlinkAsRemoved(Closure $remove, string $expectedException): void
        {
            $directory = $this->root . '/directory';
            $path = $directory . '/file';
            self::assertTrue(mkdir($directory));
            self::assertNotFalse(file_put_contents($path, 'content'));
            FsNativeProbe::removeWithParentDuringUnlink($path);

            $remove($directory);

            self::assertDirectoryDoesNotExist($directory);
        }

        #[Test]
        #[DataProvider('removers')]
        public function itTreatsADirectoryDisappearingDuringRmdirAsRemoved(Closure $remove, string $expectedException): void
        {
            $path = $this->root . '/directory';
            self::assertTrue(mkdir($path));
            FsNativeProbe::removeDuringRmdir($path);

            $remove($path);

            self::assertDirectoryDoesNotExist($path);
        }

        /** @param class-string<Throwable> $expectedException */
        #[Test]
        #[DataProvider('removers')]
        public function itRefusesAnUnlinkFailureThatLeavesTheEntryPresent(Closure $remove, string $expectedException): void
        {
            $path = $this->root . '/file';
            self::assertNotFalse(file_put_contents($path, 'content'));
            FsNativeProbe::refuseUnlink($path);

            $this->expectException($expectedException);
            $this->expectExceptionMessage('Cannot unlink ' . $path . ' while removing it.');

            $remove($path);
        }

        /** @param class-string<Throwable> $expectedException */
        #[Test]
        #[DataProvider('removers')]
        public function itRefusesAnInaccessibleEntryInsteadOfCallingItAbsent(Closure $remove, string $expectedException): void
        {
            $directory = $this->root . '/blocked';
            $path = $directory . '/file';
            self::assertTrue(mkdir($directory));
            self::assertNotFalse(file_put_contents($path, 'content'));
            self::assertTrue(chmod($directory, 0o000));

            $this->expectException($expectedException);
            $this->expectExceptionMessage('Cannot unlink ' . $path . ' while removing it.');

            $remove($path);
        }

        /** @param class-string<Throwable> $expectedException */
        #[Test]
        #[DataProvider('removers')]
        public function itRefusesAResidualNonemptyDirectory(Closure $remove, string $expectedException): void
        {
            $path = $this->root . '/directory';
            self::assertTrue(mkdir($path));
            FsNativeProbe::makeNonemptyDuringRmdir($path);

            $this->expectException($expectedException);
            $this->expectExceptionMessage('Cannot remove directory ' . $path . '.');

            $remove($path);
        }

        #[Test]
        #[DataProvider('removers')]
        public function itUnlinksASymlinkWithoutWalkingItsTarget(Closure $remove, string $expectedException): void
        {
            $target = $this->root . '/target';
            $link = $this->root . '/link';
            self::assertTrue(mkdir($target));
            self::assertNotFalse(file_put_contents($target . '/kept', 'content'));
            self::assertTrue(symlink($target, $link));

            $remove($link);

            self::assertFalse(is_link($link));
            self::assertFileExists($target . '/kept');
        }

        #[Test]
        #[DataProvider('removers')]
        public function itRemovesADanglingSymlink(Closure $remove, string $expectedException): void
        {
            $link = $this->root . '/link';
            self::assertTrue(symlink($this->root . '/missing-target', $link));

            $remove($link);

            self::assertFalse(is_link($link));
        }
    }

    final class FsNativeProbe
    {
        private const string REMOVE_DURING_UNLINK = 'remove-during-unlink';
        private const string REMOVE_WITH_PARENT_DURING_UNLINK = 'remove-with-parent-during-unlink';
        private const string REFUSE_UNLINK = 'refuse-unlink';
        private const string REMOVE_DURING_RMDIR = 'remove-during-rmdir';
        private const string MAKE_NONEMPTY_DURING_RMDIR = 'make-nonempty-during-rmdir';

        private static ?string $removeAfterDirectoryCheck = null;

        /** @var array<string, string> */
        private static array $unlink = [];

        /** @var array<string, string> */
        private static array $rmdir = [];

        public static function reset(): void
        {
            self::$removeAfterDirectoryCheck = null;
            self::$unlink = [];
            self::$rmdir = [];
        }

        public static function removeAfterDirectoryCheck(string $path): void
        {
            self::$removeAfterDirectoryCheck = $path;
        }

        public static function removeDuringUnlink(string $path): void
        {
            self::$unlink[$path] = self::REMOVE_DURING_UNLINK;
        }

        public static function refuseUnlink(string $path): void
        {
            self::$unlink[$path] = self::REFUSE_UNLINK;
        }

        public static function removeWithParentDuringUnlink(string $path): void
        {
            self::$unlink[$path] = self::REMOVE_WITH_PARENT_DURING_UNLINK;
        }

        public static function removeDuringRmdir(string $path): void
        {
            self::$rmdir[$path] = self::REMOVE_DURING_RMDIR;
        }

        public static function makeNonemptyDuringRmdir(string $path): void
        {
            self::$rmdir[$path] = self::MAKE_NONEMPTY_DURING_RMDIR;
        }

        public static function isDirectory(string $path): bool
        {
            $isDirectory = is_dir($path);

            if ($isDirectory && self::$removeAfterDirectoryCheck === $path) {
                self::$removeAfterDirectoryCheck = null;
                rmdir($path);
            }

            return $isDirectory;
        }

        /** @return list<string>|false */
        public static function scanDirectory(string $path): array|false
        {
            return scandir($path);
        }

        public static function unlink(string $path): bool
        {
            $behaviour = self::$unlink[$path] ?? null;

            if ($behaviour === self::REMOVE_DURING_UNLINK) {
                unset(self::$unlink[$path]);
                unlink($path);

                return false;
            }

            if ($behaviour === self::REMOVE_WITH_PARENT_DURING_UNLINK) {
                unset(self::$unlink[$path]);
                unlink($path);
                rmdir(\dirname($path));

                return false;
            }

            if ($behaviour === self::REFUSE_UNLINK) {
                return false;
            }

            return unlink($path);
        }

        public static function removeDirectory(string $path): bool
        {
            $behaviour = self::$rmdir[$path] ?? null;

            if ($behaviour === self::REMOVE_DURING_RMDIR) {
                unset(self::$rmdir[$path]);
                rmdir($path);

                return false;
            }

            if ($behaviour === self::MAKE_NONEMPTY_DURING_RMDIR) {
                unset(self::$rmdir[$path]);
                file_put_contents($path . '/residual', 'content');
            }

            return rmdir($path);
        }
    }
}
