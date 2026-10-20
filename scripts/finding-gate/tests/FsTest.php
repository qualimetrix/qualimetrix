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
    use QmxFindingGate\ProcessHandle;
    use QmxFindingGate\RemovalFailureDiagnostics;
    use QmxFindingGateControls\Shell;
    use ReflectionMethod;
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

        /** @param class-string<Throwable> $expectedException */
        #[Test]
        #[DataProvider('removers')]
        public function itReportsTheNativeFailureResidualEntriesAndProcessesInsideTheTree(Closure $remove, string $expectedException): void
        {
            $path = $this->root . '/directory';
            $sibling = $this->root . '/directory-sibling';
            self::assertTrue(mkdir($path));
            self::assertTrue(mkdir($sibling));
            $inside = ProcessHandle::start([\PHP_BINARY, '-r', 'sleep(30);'], $path);
            $outside = ProcessHandle::start([\PHP_BINARY, '-r', 'sleep(30);'], $sibling);
            FsNativeProbe::makeNonemptyDuringRmdir($path);

            try {
                $remove($path);
                self::fail('A residual directory must be refused.');
            } catch (Throwable $error) {
                self::assertInstanceOf($expectedException, $error);
                self::assertStringContainsString('Cannot remove directory ' . $path . '.', $error->getMessage());
                self::assertStringContainsString('errno=unknown', $error->getMessage());
                self::assertStringContainsString('native error=', $error->getMessage());
                self::assertStringContainsString('Directory not empty', $error->getMessage());
                self::assertStringContainsString('remaining entries=["residual"]', $error->getMessage());
                self::assertStringContainsString('cwd=' . $path, $error->getMessage());
                self::assertStringNotContainsString('cwd=' . $sibling, $error->getMessage());
            } finally {
                $inside->terminate();
                $outside->terminate();
            }
        }

        #[Test]
        public function itDoesNotLetADiagnosticFailureReplaceTheCleanupRefusal(): void
        {
            $message = RemovalFailureDiagnostics::message(
                'Original cleanup refusal.',
                "invalid\0path",
                'native refusal (errno 39)',
                true,
            );

            self::assertStringStartsWith('Original cleanup refusal. [errno=39;', $message);
            self::assertStringContainsString('native error="native refusal (errno 39)"', $message);
            self::assertStringContainsString('live processes="unavailable (', $message);
        }

        #[Test]
        public function itReportsAnUnavailableLinuxProcessPopulationWhenProcCannotBeListed(): void
        {
            FsNativeProbe::refuseProcListing();

            self::assertSame(
                'unavailable (cannot list /proc: native error not reported)',
                self::linuxProcessDiagnostics($this->root),
            );
        }

        #[Test]
        public function itReportsATruncatedLinuxProcessPopulationAndMatchingResults(): void
        {
            $pids = array_map(strval(...), range(10_000, 14_096));
            $workingDirectories = array_fill_keys(\array_slice($pids, 0, 17), $this->root);
            FsNativeProbe::procPopulation($pids, $workingDirectories);

            $diagnostics = self::linuxProcessDiagnostics($this->root);

            self::assertIsArray($diagnostics);
            self::assertCount(18, $diagnostics);
            self::assertSame('<truncated: more than 16 matching processes>', $diagnostics[16]);
            self::assertSame('<truncated: process population exceeds 4096 inspected entries>', $diagnostics[17]);
        }

        #[Test]
        public function itReportsAnIncompleteLinuxObservationWhenAProcessCwdCannotBeRead(): void
        {
            FsNativeProbe::procPopulation(['100', '101'], ['100' => '/outside']);

            self::assertSame(
                ['<incomplete: cwd unavailable for 1 inspected process entry>'],
                self::linuxProcessDiagnostics($this->root),
            );
        }

        #[Test]
        public function itTransportsGitConfigurationEnvironmentWithoutInterpretingItInControlsShell(): void
        {
            $before = self::gitConfigurationEnvironment();
            $transported = [
                'GIT_CONFIG_COUNT' => 'not-a-count',
                'GIT_CONFIG_KEY_unusual' => 'section.key',
                'GIT_CONFIG_VALUE_unusual' => 'value',
                'GIT_CONFIG_KEY_9' => 'orphan.key',
            ];

            try {
                self::replaceGitConfigurationEnvironment($transported);
                $probe = <<<'PHP'
                    echo json_encode([
                        'GIT_CONFIG_COUNT' => getenv('GIT_CONFIG_COUNT'),
                        'GIT_CONFIG_KEY_unusual' => getenv('GIT_CONFIG_KEY_unusual'),
                        'GIT_CONFIG_VALUE_unusual' => getenv('GIT_CONFIG_VALUE_unusual'),
                        'GIT_CONFIG_KEY_9' => getenv('GIT_CONFIG_KEY_9'),
                        'GIT_CONFIG_VALUE_9' => getenv('GIT_CONFIG_VALUE_9'),
                    ], JSON_THROW_ON_ERROR);
                    PHP;
                $result = Shell::run([\PHP_BINARY, '-r', $probe], $this->root);

                self::assertSame(0, $result['exit']);
                self::assertSame('', $result['stderr']);
                self::assertSame([
                    ...$transported,
                    'GIT_CONFIG_VALUE_9' => false,
                ], json_decode($result['stdout'], true, 512, \JSON_THROW_ON_ERROR));
            } finally {
                self::replaceGitConfigurationEnvironment($before);
            }
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

        /** @return array<string, string> */
        private static function gitConfigurationEnvironment(): array
        {
            $environment = getenv();
            if ($environment === false) {
                throw new RuntimeException('Cannot snapshot the test process environment.');
            }

            $configuration = [];
            foreach ($environment as $name => $value) {
                if ($name === 'GIT_CONFIG_COUNT'
                    || str_starts_with($name, 'GIT_CONFIG_KEY_')
                    || str_starts_with($name, 'GIT_CONFIG_VALUE_')) {
                    $configuration[$name] = $value;
                }
            }

            return $configuration;
        }

        /** @param array<string, string> $replacement */
        private static function replaceGitConfigurationEnvironment(array $replacement): void
        {
            foreach (array_keys(self::gitConfigurationEnvironment()) as $name) {
                if (!putenv($name)) {
                    throw new RuntimeException('Cannot remove ' . $name . ' from the test process environment.');
                }
            }
            foreach ($replacement as $name => $value) {
                if (!putenv($name . '=' . $value)) {
                    throw new RuntimeException('Cannot set ' . $name . ' in the test process environment.');
                }
            }
        }

        /** @return list<string>|string */
        private static function linuxProcessDiagnostics(string $root): array|string
        {
            $method = new ReflectionMethod(RemovalFailureDiagnostics::class, 'linuxProcesses');
            $diagnostics = $method->invoke(null, $root);
            if (\is_string($diagnostics)) {
                return $diagnostics;
            }
            if (!\is_array($diagnostics) || !array_is_list($diagnostics)) {
                throw new RuntimeException('Linux process diagnostics returned an unexpected value.');
            }

            return array_map(static function (mixed $entry): string {
                if (!\is_string($entry)) {
                    throw new RuntimeException('Linux process diagnostics returned a non-string entry.');
                }

                return $entry;
            }, $diagnostics);
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

        private static bool $refuseProcListing = false;

        /** @var list<string>|null */
        private static ?array $procEntries = null;

        /** @var array<array-key, string> */
        private static array $procWorkingDirectories = [];

        public static function reset(): void
        {
            self::$removeAfterDirectoryCheck = null;
            self::$unlink = [];
            self::$rmdir = [];
            self::$refuseProcListing = false;
            self::$procEntries = null;
            self::$procWorkingDirectories = [];
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

        public static function refuseProcListing(): void
        {
            self::$refuseProcListing = true;
        }

        /**
         * @param list<string> $pids
         * @param array<array-key, string> $workingDirectories
         */
        public static function procPopulation(array $pids, array $workingDirectories): void
        {
            self::$procEntries = ['.', '..', ...$pids];
            self::$procWorkingDirectories = $workingDirectories;
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
            if ($path === '/proc' && self::$refuseProcListing) {
                return false;
            }
            if ($path === '/proc' && self::$procEntries !== null) {
                return self::$procEntries;
            }

            return scandir($path);
        }

        public static function readLink(string $path): string|false
        {
            if (self::$procEntries !== null && preg_match('#^/proc/([^/]+)/cwd$#', $path, $matches) === 1) {
                return self::$procWorkingDirectories[$matches[1]] ?? false;
            }

            return readlink($path);
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
