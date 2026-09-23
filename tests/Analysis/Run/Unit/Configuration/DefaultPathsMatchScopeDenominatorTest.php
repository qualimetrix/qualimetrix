<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A run with no `paths` analyses the project, so it must be judged as
 * covering the project — whatever autoload form `composer.json` declares the
 * code through. The default paths and the scope denominator are the two
 * halves of one answer; a manifest shape one half reads and the other does
 * not makes a no-paths run warn about its own defaults and silences every
 * channel that speaks only on a whole-project run.
 *
 * Driven through the real discovery stage, reader and resolver, because the
 * defect lived in the seam between them rather than in any one of them.
 */
#[CoversClass(ComposerDiscoveryStage::class)]
#[CoversClass(RunConfigurationResolver::class)]
#[CoversClass(ProjectScopeCoverage::class)]
#[CoversClass(ComposerReader::class)]
final class DefaultPathsMatchScopeDenominatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-default-paths-' . bin2hex(random_bytes(8));
        foreach (['src', 'tests', 'helpers', 'modules/alpha/lib', 'modules/beta/lib'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o777, true);
        }
        foreach (['src/A.php', 'tests/SomeTest.php', 'tests/helpers.php', 'helpers/functions.php', 'modules/alpha/lib/Alpha.php', 'modules/beta/lib/Beta.php'] as $file) {
            file_put_contents($this->root . '/' . $file, "<?php\n");
        }
        $this->root = (string) realpath($this->root);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            /** @var SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{array<string, mixed>, bool, list<string>}> */
    public static function provideManifests(): iterable
    {
        $psr4 = ['psr-4' => ['App\\' => 'src/']];

        yield 'autoload-dev through classmap, flag given' => [
            ['autoload' => $psr4, 'autoload-dev' => ['classmap' => ['tests/']]],
            true,
            ['src', 'tests'],
        ];
        yield 'autoload-dev through psr-0, flag given' => [
            ['autoload' => $psr4, 'autoload-dev' => ['psr-0' => ['Some' => 'tests/']]],
            true,
            ['src', 'tests'],
        ];
        yield 'autoload-dev through files, flag given' => [
            ['autoload' => $psr4, 'autoload-dev' => ['files' => ['tests/helpers.php']]],
            true,
            ['src', 'tests/helpers.php'],
        ];
        yield 'autoload-dev through psr-4, flag given' => [
            ['autoload' => $psr4, 'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']]],
            true,
            ['src', 'tests'],
        ];
        yield 'autoload-dev through classmap, no flag' => [
            ['autoload' => $psr4, 'autoload-dev' => ['classmap' => ['tests/']]],
            false,
            ['src'],
        ];
        yield 'production files beside psr-4, no flag' => [
            ['autoload' => [...$psr4, 'files' => ['helpers/functions.php']]],
            false,
            ['src', 'helpers/functions.php'],
        ];
        yield 'production classmap beside psr-4, no flag' => [
            ['autoload' => [...$psr4, 'classmap' => ['helpers/']]],
            false,
            ['src', 'helpers'],
        ];
        yield 'production psr-0 only, no flag' => [
            ['autoload' => ['psr-0' => ['App_' => 'src/']]],
            false,
            ['src'],
        ];
        yield 'production classmap through a wildcard, no flag' => [
            ['autoload' => ['classmap' => ['modules/*/lib']]],
            false,
            ['modules/alpha/lib', 'modules/beta/lib'],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<string> $expectedPaths relative to the project root
     */
    #[Test]
    #[DataProvider('provideManifests')]
    public function itJudgesARunWithNoPathsAsCoveringTheProject(array $manifest, bool $includeAutoloadDev, array $expectedPaths): void
    {
        $configuration = $this->resolve($manifest, $includeAutoloadDev);

        self::assertSame($expectedPaths, $this->relativePaths($configuration));
        self::assertTrue(
            $configuration->coversProjectScope,
            'A run over the default paths must cover the scope it is judged against.',
        );
        self::assertSame(
            [],
            (new ProjectScopeCoverage(new ComposerReader()))->uncoveredAutoloadRoots(
                $configuration->projectRoot,
                $configuration->paths,
                $configuration->autoloadDevPolicy,
            ),
        );
    }

    /**
     * The legitimate neighbour of the cure: a run narrowed to `src` still
     * reads as not covering the targets the policy counts, whichever form
     * declared them.
     */
    #[Test]
    public function itStillReportsANarrowedRunAsNotCoveringNonPsr4Targets(): void
    {
        $configuration = $this->resolve(
            ['autoload' => ['psr-4' => ['App\\' => 'src/']], 'autoload-dev' => ['classmap' => ['tests/']]],
            true,
            ['src'],
        );

        self::assertFalse($configuration->coversProjectScope);
    }

    /**
     * A declared target that is not on disk stays in the default paths, so a
     * run over them is refused by the path check downstream — the same answer
     * a stale PSR-4 root has always had — rather than dropped in silence.
     */
    #[Test]
    public function itKeepsAMissingDeclaredTargetInTheDefaultPaths(): void
    {
        $configuration = $this->resolve(
            ['autoload' => ['psr-4' => ['App\\' => 'src/'], 'classmap' => ['gone/']]],
            false,
        );

        self::assertSame(['src', 'gone'], $this->relativePaths($configuration));
    }

    /**
     * @param array<string, mixed> $manifest
     * @param ?list<string> $writtenPaths
     */
    private function resolve(array $manifest, bool $includeAutoloadDev, ?array $writtenPaths = null): RunConfiguration
    {
        file_put_contents($this->root . '/composer.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
        $root = AbsolutePath::fromString($this->root);

        $layer = (new ComposerDiscoveryStage(new ComposerReader()))->apply(new ConfigurationResolutionRequest($root));
        self::assertNotNull($layer);

        $cli = [ConfigSchema::INCLUDE_AUTOLOAD_DEV => $includeAutoloadDev];
        if ($writtenPaths !== null) {
            $cli[ConfigSchema::PATHS] = $writtenPaths;
        }

        $configuration = (new RunConfigurationResolver(new ProjectScopeCoverage(new ComposerReader())))->resolve(new ConfigurationDocument([
            ['source' => $layer->source, 'values' => $layer->values],
            ['source' => 'cli', 'values' => $cli],
        ], $root));

        self::assertSame(
            $includeAutoloadDev ? AutoloadDevPolicy::Include : AutoloadDevPolicy::Exclude,
            $configuration->autoloadDevPolicy,
        );

        return $configuration;
    }

    /** @return list<string> */
    private function relativePaths(RunConfiguration $configuration): array
    {
        return array_map(
            fn(AbsolutePath $path): string => substr($path->value(), \strlen($this->root) + 1),
            $configuration->paths,
        );
    }
}
