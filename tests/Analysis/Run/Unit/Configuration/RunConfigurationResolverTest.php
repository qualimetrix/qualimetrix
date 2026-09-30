<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Infrastructure\Composer\ComposerManifestReader;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

final class RunConfigurationResolverTest extends TestCase
{
    #[Test]
    public function itResolvesOwnerDefaultsAndLastPathContributionAgainstTheInvocationRoot(): void
    {
        $configuration = (new RunConfigurationResolver(new ProjectScopeCoverage(new ComposerManifestReader())))->resolve(LayeredDocument::of([
            ['source' => 'composer', 'values' => ['paths' => ['lib'], 'excludes' => [['subtree' => 'build']]]],
            ['source' => 'cli', 'values' => ['paths' => ['src'], 'include_generated' => true]],
        ], AbsolutePath::fromString(sys_get_temp_dir())));

        self::assertSame([sys_get_temp_dir() . '/src'], array_map(static fn($path): string => $path->value(), $configuration->paths));
        self::assertSame(
            ['regex:(?:[^/]+/)*vendor', 'regex:(?:[^/]+/)*node_modules', 'regex:(?:[^/]+/)*\\.git', 'subtree:build'],
            array_map(static fn(PathPattern $pattern): string => $pattern->definition->display(), $configuration->pathExcludes),
        );
        self::assertSame(GeneratedFilePolicy::Include, $configuration->generatedFilePolicy);
    }

    #[Test]
    public function itKeepsRelativePathsRootedAtIngressWhenTheProcessDirectoryChanges(): void
    {
        $original = getcwd();
        self::assertNotFalse($original);
        $rootA = sys_get_temp_dir() . '/qmx-run-root-a-' . bin2hex(random_bytes(6));
        $rootB = sys_get_temp_dir() . '/qmx-run-root-b-' . bin2hex(random_bytes(6));
        mkdir($rootA);
        mkdir($rootB);

        try {
            $document = LayeredDocument::of([
                ['source' => 'cli', 'values' => ['paths' => ['src']]],
            ], AbsolutePath::fromString($rootA));
            chdir($rootB);

            $configuration = (new RunConfigurationResolver(new ProjectScopeCoverage(new ComposerManifestReader())))->resolve($document);

            self::assertSame($rootA, $configuration->projectRoot->value());
            self::assertSame([$rootA . '/src'], array_map(
                static fn(AbsolutePath $path): string => $path->value(),
                $configuration->paths,
            ));
        } finally {
            chdir($original);
            rmdir($rootA);
            rmdir($rootB);
        }
    }

    #[Test]
    public function itUsesTheMeasuredPathsAndRejectsAnUnrelatedProjectRoot(): void
    {
        $writtenRoot = AbsolutePath::fromString('/written-project');
        $canonicalRoot = AbsolutePath::fromString('/canonical-project');
        $paths = [AbsolutePath::fromString('/written-project/src')];
        $scope = new ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $canonicalRoot, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: false, pathResolutions: [['written' => $writtenRoot, 'path' => $canonicalRoot]]), paths: $paths, scopeState: ProjectScopeState::Unmeasured, uncoveredRoots: []);

        foreach ([$writtenRoot, $canonicalRoot] as $root) {
            $configuration = new RunConfiguration(
                pathExcludes: [],
                projectRoot: $root,
                generatedFilePolicy: GeneratedFilePolicy::Exclude,
                projectScope: $scope,
                authoredPathExcludes: [],
                autoloadDevPolicy: AutoloadDevPolicy::Exclude,
            );
            self::assertSame($paths, $configuration->paths);
            self::assertSame($scope, $configuration->projectScope);
            self::assertFalse($configuration->coversProjectScope);
        }

        $this->expectException(LogicException::class);
        new RunConfiguration(
            pathExcludes: [],
            projectRoot: AbsolutePath::fromString('/unrelated-project'),
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            projectScope: $scope,
            authoredPathExcludes: [],
            autoloadDevPolicy: AutoloadDevPolicy::Exclude,
        );
    }

    /** @return iterable<string, array{list<array{source: string, values: array<string, mixed>}>, list<string>, AutoloadDevPolicy}> */
    public static function provideDiscoveredPathDefaults(): iterable
    {
        $discovered = ['source' => 'composer.json', 'values' => [
            ConfigSchema::DISCOVERED_AUTOLOAD_PATHS => ['src'],
            ConfigSchema::DISCOVERED_AUTOLOAD_DEV_PATHS => ['tests'],
        ]];

        yield 'production only by default' => [[$discovered], ['src'], AutoloadDevPolicy::Exclude];
        yield 'autoload-dev added by the flag' => [
            [$discovered, ['source' => 'cli', 'values' => [ConfigSchema::INCLUDE_AUTOLOAD_DEV => true]]],
            ['src', 'tests'],
            AutoloadDevPolicy::Include,
        ];
        yield 'a later false wins over an earlier true' => [
            [
                $discovered,
                ['source' => 'qmx.yaml', 'values' => [ConfigSchema::INCLUDE_AUTOLOAD_DEV => true]],
                ['source' => 'cli', 'values' => [ConfigSchema::INCLUDE_AUTOLOAD_DEV => false]],
            ],
            ['src'],
            AutoloadDevPolicy::Exclude,
        ];
        yield 'written paths are not widened by the flag' => [
            [$discovered, ['source' => 'cli', 'values' => [ConfigSchema::PATHS => ['lib'], ConfigSchema::INCLUDE_AUTOLOAD_DEV => true]]],
            ['lib'],
            AutoloadDevPolicy::Include,
        ];
        yield 'nothing discovered and nothing written' => [[], ['.'], AutoloadDevPolicy::Exclude];
    }

    /**
     * @param list<array{source: string, values: array<string, mixed>}> $sources
     * @param list<string> $expectedPaths
     */
    #[Test]
    #[DataProvider('provideDiscoveredPathDefaults')]
    public function itTakesDefaultPathsAndThePolicyFromTheSameFlag(array $sources, array $expectedPaths, AutoloadDevPolicy $expectedPolicy): void
    {
        $root = sys_get_temp_dir();
        $configuration = (new RunConfigurationResolver(new ProjectScopeCoverage(new ComposerManifestReader())))
            ->resolve(LayeredDocument::of($sources, AbsolutePath::fromString($root)));

        self::assertSame(
            array_map(static fn(string $path): string => $path === '.' ? $root : $root . '/' . $path, $expectedPaths),
            array_map(static fn(AbsolutePath $path): string => $path->value(), $configuration->paths),
        );
        self::assertSame($expectedPolicy, $configuration->autoloadDevPolicy);
    }
}
