<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Composer\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Composer\ComposerManifestReader;

#[CoversClass(ComposerManifestReader::class)]
final class ComposerManifestReaderTest extends TestCase
{
    private ComposerManifestReader $reader;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->reader = new ComposerManifestReader();
        $this->tempDir = sys_get_temp_dir() . '/composer_reader_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function itCachesInvalidBytesAndSymlinkAliasesWithoutConstructorReads(): void
    {
        self::assertSame([], $this->reader->observedIssues());
        file_put_contents($this->tempDir . '/composer.json', '[]');
        $root = AbsolutePath::fromString($this->tempDir);
        $invalid = $this->reader->read($root);
        $alias = $this->tempDir . '-alias';
        symlink($this->tempDir, $alias);
        try {
            file_put_contents($this->tempDir . '/composer.json', '{"name":"fixed/project"}');
            self::assertSame($invalid, $this->reader->read(AbsolutePath::fromString($alias)));
            self::assertSame(\Qualimetrix\Analysis\ProjectManifest\Contract\ManifestReadState::Invalid, $invalid->state);
            self::assertCount(1, $this->reader->observedIssues());
            $this->reader->beginInvocation();
            self::assertSame('fixed/project', $this->reader->read($root)->name);
        } finally {
            unlink($alias);
        }
    }

    #[Test]
    public function itExtractsPathsFromPsr4Autoload(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'Tests\\' => 'tests/',
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets();

        self::assertSame(['src', 'tests'], $paths);
    }

    #[Test]
    public function itAnswersNullWhenTheComposerFileDoesNotExist(): void
    {
        self::assertSame([], $this->reader->read(AbsolutePath::fromString('/nonexistent'))->productionTargets());
    }

    #[Test]
    public function itAnswersNullWhenThereIsNoAutoloadSection(): void
    {
        $this->writeComposerJson(['name' => 'test/package']);

        self::assertSame([], $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets());
    }

    #[Test]
    public function itExtractsAllPathsFromAMultiPathPsr4Mapping(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => ['src/', 'lib/'],
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets();

        self::assertSame(['src', 'lib'], $paths);
    }

    #[Test]
    public function itReadsAutoloadDevPathsOnlyThroughTheirOwnQuestion(): void
    {
        $composerJson = [
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\' => 'tests/',
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        self::assertSame([], $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets());
        self::assertSame(['tests'], $this->reader->read(AbsolutePath::fromString($this->tempDir))->developmentTargets());
    }

    #[Test]
    public function itLeavesAutoloadDevOutUnlessAsked(): void
    {
        $this->writeComposerJson([
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        ]);

        self::assertSame(['src'], $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets());
    }

    #[Test]
    public function itReadsAutoloadAndAutoloadDevApart(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\' => 'tests/',
                    'Fixtures\\' => ['fixtures/', 'test-data/'],
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        self::assertSame(['src'], $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets());
        self::assertSame(
            ['tests', 'fixtures', 'test-data'],
            $this->reader->read(AbsolutePath::fromString($this->tempDir))->developmentTargets(),
        );
    }

    #[Test]
    public function itAnswersForAPathSharedByBothSectionsInEach(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\' => 'src/',
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        self::assertSame(['src'], $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets());
        self::assertSame(['src'], $this->reader->read(AbsolutePath::fromString($this->tempDir))->developmentTargets());
    }

    #[Test]
    public function itDeduplicatesRepeatedPathsWithinAutoload(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'App\\Sub\\' => 'src/',
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets();

        self::assertSame(['src'], $paths);
    }

    #[Test]
    public function itMapsAnEmptyPsr4PathToTheProjectRoot(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => '',
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets();

        self::assertSame(['.'], $paths);
    }

    #[Test]
    public function itMapsAnEmptyPathInAMultiPathMappingToTheProjectRoot(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => ['', 'src/'],
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets();

        self::assertSame(['.', 'src'], $paths);
    }

    #[Test]
    public function itStripsTrailingSlashesFromExtractedPaths(): void
    {
        $composerJson = [
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src///',
                ],
            ],
        ];
        $this->writeComposerJson($composerJson);

        $paths = $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets();

        self::assertSame(['src'], $paths);
    }

    /**
     * Composer expands `*` in a `classmap` entry to the directories it
     * matches; left unexpanded the entry names no path, and a run over the
     * default paths would be refused over a manifest Composer accepts.
     */
    #[Test]
    public function itExpandsAClassmapWildcardToTheDirectoriesItMatches(): void
    {
        mkdir($this->tempDir . '/modules/beta/lib', 0777, true);
        mkdir($this->tempDir . '/modules/alpha/lib', 0777, true);
        touch($this->tempDir . '/modules/stray.php');
        $this->writeComposerJson([
            'autoload' => ['classmap' => ['modules/*/lib', 'modules/*']],
            'autoload-dev' => ['classmap' => ['modules/*/lib/']],
        ]);

        self::assertSame(
            ['modules/alpha/lib', 'modules/beta/lib', 'modules/alpha', 'modules/beta'],
            $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets(),
        );
        self::assertSame(
            ['modules/alpha/lib', 'modules/beta/lib'],
            $this->reader->read(AbsolutePath::fromString($this->tempDir))->developmentTargets(),
        );
    }

    /**
     * A wildcard matching nothing is kept as written: it then meets the same
     * refusal as any other missing target, as it does in Composer. Only
     * `classmap` expands — Composer gives `*` no meaning in the other forms.
     */
    #[Test]
    public function itKeepsAWildcardMatchingNothingAndLeavesOtherFormsUnexpanded(): void
    {
        mkdir($this->tempDir . '/lib/a', 0777, true);
        $this->writeComposerJson([
            'autoload' => ['classmap' => ['nowhere/*'], 'files' => ['lib/*'], 'psr-4' => ['App\\' => 'lib/*']],
        ]);

        self::assertSame(
            ['lib/*', 'nowhere/*'],
            $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets(),
        );
    }

    /**
     * Both directions of the question the coverage gate asks, in one table:
     * every production section contributes its paths, and everything that is
     * not a production declaration contributes none.
     *
     * `classmap`, `psr-0` and `files` are production path targets like PSR-4.
     * `null` is reserved for a manifest that declares no production autoload.
     *
     * @param array<string, mixed> $manifest
     * @param ?list<string> $expected
     */
    #[Test]
    #[DataProvider('provideManifests')]
    public function itReadsEveryProductionAutoloadSectionAsPaths(array $manifest, ?array $expected): void
    {
        $this->writeComposerJson($manifest);

        self::assertSame(
            $expected ?? [],
            $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets(),
        );
    }

    /** @return iterable<string, array{array<string, mixed>, ?list<string>}> */
    public static function provideManifests(): iterable
    {
        $psr4 = ['psr-4' => ['App\\' => 'src/']];

        yield 'classmap alone' => [['autoload' => ['classmap' => ['legacy/']]], ['legacy']];
        yield 'classmap beside psr-4' => [['autoload' => $psr4 + ['classmap' => ['legacy/']]], ['src', 'legacy']];
        yield 'psr-0 beside psr-4' => [['autoload' => $psr4 + ['psr-0' => ['Legacy_' => 'legacy/']]], ['src', 'legacy']];
        yield 'files beside psr-4' => [['autoload' => $psr4 + ['files' => ['src/helpers.php']]], ['src', 'src/helpers.php']];
        yield 'a multi-path prefix contributes each of its paths' => [
            ['autoload' => ['psr-0' => ['Legacy_' => ['legacy/', 'compat/']]]],
            ['legacy', 'compat'],
        ];
        yield 'the same path declared twice is one target' => [
            ['autoload' => $psr4 + ['classmap' => ['src/']]],
            ['src'],
        ];
        yield 'an empty path means the project root' => [['autoload' => ['psr-4' => ['App\\' => '']]], ['.']];

        yield 'psr-4 alone' => [['autoload' => $psr4], ['src']];

        // The honest "cannot judge": nothing production was declared.
        yield 'an empty classmap declares nothing' => [['autoload' => ['classmap' => []]], null];
        yield 'a scalar in that position is not a declaration' => [['autoload' => ['classmap' => 'legacy/']], null];
        yield 'a dev section is not production' => [[
            'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'tests/'], 'classmap' => ['tests/Fixtures/']],
        ], null];
        yield 'exclude-from-classmap removes rather than declares' => [[
            'autoload' => ['exclude-from-classmap' => ['src/Generated/']],
        ], null];
        yield 'no autoload section at all' => [['name' => 'acme/demo'], null];
    }

    #[Test]
    public function itDeclaresNoProductionTargetsWhenTheManifestIsAbsent(): void
    {
        self::assertSame([], $this->reader->read(AbsolutePath::fromString($this->tempDir . '/nowhere'))->productionTargets());
    }

    #[Test]
    public function itDeclaresNoProductionTargetsWhenTheManifestDoesNotParse(): void
    {
        file_put_contents($this->tempDir . '/composer.json', '{ this is not json');

        self::assertSame([], $this->reader->read(AbsolutePath::fromString($this->tempDir))->productionTargets());
    }

    #[Test]
    public function itSnapshotsCanonicalRootsIncludingFailuresUntilTheNextInvocation(): void
    {
        $root = AbsolutePath::fromString($this->tempDir);
        $absent = $this->reader->read($root);
        $this->writeComposerJson(['name' => 'first/project']);
        self::assertSame($absent, $this->reader->read($root));
        self::assertSame($absent, $this->reader->read(AbsolutePath::fromString($this->tempDir . '/.')));
        self::assertCount(1, $this->reader->observedIssues());
        $this->reader->beginInvocation();
        self::assertSame([], $this->reader->observedIssues());
        $first = $this->reader->read($root);
        self::assertSame('first/project', $first->name);
        $this->writeComposerJson(['name' => 'second/project']);
        self::assertSame($first, $this->reader->read($root));
        $this->reader->beginInvocation();
        self::assertSame('second/project', $this->reader->read($root)->name);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode($data, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff((scandir($dir) !== false ? scandir($dir) : []), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    #[Test]
    public function itReadsEveryAutoloadDevSectionAsTargets(): void
    {
        $this->writeComposerJson([
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
            'autoload-dev' => [
                'psr-4' => ['Tests\\' => 'tests/'],
                'classmap' => ['legacy-tests/'],
                'files' => ['tests/bootstrap.php'],
            ],
        ]);

        self::assertSame(
            ['tests', 'legacy-tests', 'tests/bootstrap.php'],
            $this->reader->read(AbsolutePath::fromString($this->tempDir))->developmentTargets(),
        );
    }

    #[Test]
    public function itAnswersNullWhenAutoloadDevDeclaresNothing(): void
    {
        $this->writeComposerJson(['autoload' => ['psr-4' => ['App\\' => 'src/']]]);

        self::assertSame([], $this->reader->read(AbsolutePath::fromString($this->tempDir))->developmentTargets());
    }
}
