<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Discovery\EntryInspector;
use Qualimetrix\Analysis\Run\Discovery\GeneratedFileFilter;
use Qualimetrix\Analysis\Run\Discovery\ProjectFiles;
use Qualimetrix\Analysis\Run\Discovery\ProjectWalk;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Composer\ComposerManifestReader;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

/**
 * A written path excluded by the author remains a measured run with a named
 * exclusion, regardless of which configuration source supplied its paths.
 */
#[CoversClass(RunConfigurationResolver::class)]
final class WrittenRootExcludedMeasurementTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-written-root-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0o777, true);
        mkdir($this->root . '/legacy/old', 0o777, true);
        mkdir($this->root . '/lib/vendor', 0o777, true);
        file_put_contents($this->root . '/legacy/L.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        unlink($this->root . '/legacy/L.php');
        foreach (['lib/vendor', 'lib', 'legacy/old', 'legacy', 'src', ''] as $directory) {
            rmdir($this->root . '/' . $directory);
        }
    }

    #[Test]
    public function itMeasuresAWrittenRootTheAuthorsExcludeRemoves(): void
    {
        $this->assertExcluded(['legacy'], ['subtree' => 'legacy'], 'legacy');
    }

    #[Test]
    public function itMeasuresTheExcludedRootAmongLawfulOnesAndNamesOnlyIt(): void
    {
        $this->assertExcluded(['src', 'legacy'], ['subtree' => 'legacy'], 'legacy');
    }

    #[Test]
    public function itMeasuresAWrittenRootInsideASubtreeTheAuthorExcluded(): void
    {
        $this->assertExcluded(['legacy/old'], ['subtree' => 'legacy'], 'legacy/old');
    }

    #[Test]
    public function itMeasuresARootWrittenInTheDocumentToo(): void
    {
        $configuration = $this->resolve([
            ['source' => 'qmx.yaml', 'values' => [ConfigSchema::PATHS => ['legacy'], ConfigSchema::EXCLUDES => [['regex' => 'leg.*']]]],
        ]);
        $files = $this->discover($configuration);
        self::assertSame(['legacy'], array_map(static fn($path): string => $path->value(), $files->namedExcluded));
        self::assertSame(ExcludeSelectorOutcome::Removed, $files->selectorVerdicts[0]->outcome);
    }

    /** @return iterable<string, array{list<array{source: string, values: array<string, mixed>}>, string}> */
    public static function provideConflictingLayers(): iterable
    {
        yield 'CLI exclude over file paths' => [[
            ['source' => 'qmx.yaml', 'values' => [ConfigSchema::PATHS => ['src']]],
            ['source' => 'cli', 'values' => [ConfigSchema::EXCLUDES => [['subtree' => 'src']]]],
        ], 'src'];
        yield 'CLI paths over file exclude' => [[
            ['source' => 'qmx.yaml', 'values' => [ConfigSchema::EXCLUDES => [['subtree' => 'src']]]],
            ['source' => 'cli', 'values' => [ConfigSchema::PATHS => ['src']]],
        ], 'src'];
        yield 'later preset excludes earlier paths' => [[
            ['source' => 'preset-first', 'values' => [ConfigSchema::PATHS => ['src']]],
            ['source' => 'preset-second', 'values' => [ConfigSchema::EXCLUDES => [['subtree' => 'src']]]],
        ], 'src'];
        yield 'later preset paths over earlier exclusion' => [[
            ['source' => 'preset-first', 'values' => [ConfigSchema::EXCLUDES => [['subtree' => 'src']]]],
            ['source' => 'preset-second', 'values' => [ConfigSchema::PATHS => ['src']]],
        ], 'src'];
    }

    /**
     * @param list<array{source: string, values: array<string, mixed>}> $sources
     */
    #[Test]
    #[DataProvider('provideConflictingLayers')]
    public function itMeasuresExcludedPathsAcrossConfigurationOrigins(array $sources, string $excludedPath): void
    {
        $files = $this->discover($this->resolve($sources));
        self::assertSame([$excludedPath], array_map(static fn($path): string => $path->value(), $files->namedExcluded));
        self::assertSame(ExcludeSelectorOutcome::Removed, $files->selectorVerdicts[0]->outcome);
    }

    #[Test]
    public function itKeepsAComposerDefaultTheAuthorExcludedSilently(): void
    {
        $configuration = $this->resolve([
            ['source' => 'composer.json', 'values' => [ConfigSchema::DISCOVERED_AUTOLOAD_PATHS => ['src', 'legacy']]],
            ['source' => 'qmx.yaml', 'values' => [ConfigSchema::EXCLUDES => [['subtree' => 'legacy']]]],
        ]);

        self::assertSame(
            [$this->root . '/src', $this->root . '/legacy'],
            array_map(static fn(AbsolutePath $path): string => $path->value(), $configuration->paths),
        );
    }

    #[Test]
    public function itWalksAWrittenRootBelowADirectoryExcludedOnlyExactly(): void
    {
        $configuration = $this->resolveWritten(['legacy/old'], ['exact' => 'legacy']);

        self::assertSame([$this->root . '/legacy/old'], array_map(static fn(AbsolutePath $path): string => $path->value(), $configuration->paths));
    }

    #[Test]
    public function itAcceptsAWrittenFileInsideAnExcludedDirectory(): void
    {
        $configuration = $this->resolveWritten(['legacy/L.php'], ['subtree' => 'legacy']);

        self::assertSame([$this->root . '/legacy/L.php'], array_map(static fn(AbsolutePath $path): string => $path->value(), $configuration->paths));
    }

    #[Test]
    public function itLeavesABuiltInExclusionToDiscovery(): void
    {
        $configuration = $this->resolveWritten(['lib/vendor'], ['subtree' => 'build']);

        self::assertSame([$this->root . '/lib/vendor'], array_map(static fn(AbsolutePath $path): string => $path->value(), $configuration->paths));
    }

    /**
     * @param list<string> $paths
     * @param array<string, string> $exclude
     */
    private function assertExcluded(array $paths, array $exclude, string $expected): void
    {
        $files = $this->discover($this->resolveWritten($paths, $exclude));
        self::assertSame([$expected], array_map(static fn($path): string => $path->value(), $files->namedExcluded));
        self::assertSame(ExcludeSelectorOutcome::Removed, $files->selectorVerdicts[0]->outcome);
    }

    /**
     * @param list<string> $paths
     * @param array<string, string> $exclude
     */
    private function resolveWritten(array $paths, array $exclude): RunConfiguration
    {
        return $this->resolve([
            ['source' => 'qmx.yaml', 'values' => [ConfigSchema::EXCLUDES => [$exclude]]],
            ['source' => 'cli', 'values' => [ConfigSchema::PATHS => $paths]],
        ]);
    }

    /** @param list<array{source: string, values: array<string, mixed>}> $sources */
    private function resolve(array $sources): RunConfiguration
    {
        return (new RunConfigurationResolver(new ProjectScopeCoverage(new ComposerManifestReader())))
            ->resolve(LayeredDocument::of($sources, AbsolutePath::fromString($this->root)));
    }

    private function discover(RunConfiguration $run): \Qualimetrix\Analysis\Run\Contract\Discovery\DiscoveredProjectFiles
    {
        return (new ProjectFiles(new ProjectWalk(new EntryInspector()), new GeneratedFileFilter()))->discover($run);
    }
}
