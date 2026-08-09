<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\SuppressionType;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStage;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStageInterface;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Console\CliOnlyNarrowing;
use Qualimetrix\Infrastructure\Console\MeasuredViolationSet;

/**
 * The seam of ADR 0017: paths in, the set a baseline measures out, with no
 * `InputInterface` anywhere — which is what lets a command that does not
 * declare `check`'s options measure exactly what `check` measures.
 */
#[CoversClass(MeasuredViolationSet::class)]
final class MeasuredViolationSetTest extends TestCase
{
    #[Test]
    public function itLeavesOutWhatTheSourcesOwnIgnoreTagsRemoved(): void
    {
        $ignored = self::violation('src/Legacy/Service.php', 'App\\Legacy', 'Service');
        $reported = self::violation('src/Service/UserService.php', 'App\\Service', 'UserService');

        $set = $this->createSet(
            [$ignored, $reported],
            new AnalysisConfiguration(),
            [
                'src/Legacy/Service.php' => [
                    new Suppression(rule: '*', reason: 'Reviewed', line: 1, type: SuppressionType::File),
                ],
            ],
        );

        self::assertSame([$reported], $set->forPaths([AbsolutePath::fromString(sys_get_temp_dir())]));
    }

    #[Test]
    public function itLeavesOutWhatTheConfiguredExclusionsRemoved(): void
    {
        $excludedByPath = self::violation('generated/Proxy.php', 'App\\Generated', 'Proxy');
        $excludedByNamespace = self::violation('src/Vendor/Thing.php', 'App\\Vendor', 'Thing');
        $reported = self::violation('src/Service/UserService.php', 'App\\Service', 'UserService');

        $set = $this->createSet(
            [$excludedByPath, $excludedByNamespace, $reported],
            new AnalysisConfiguration(
                excludePaths: ['generated'],
                excludeNamespaces: ['App\\Vendor'],
            ),
        );

        self::assertSame([$reported], $set->forPaths([AbsolutePath::fromString(sys_get_temp_dir())]));
    }

    /**
     * The set is what configuration and the source say, and nothing else.
     * A narrowing that exists only as a `check` flag is not part of it —
     * otherwise every baseline command would have to replicate `check`'s
     * option surface to agree with it, and the four ADR 0017 commands accept none
     * of those flags.
     */
    #[Test]
    public function itDefinesTheSetFromConfigurationAloneAndNotFromCheckFlags(): void
    {
        $onlyExcludedByAFlag = self::violation('vendor/library/SomeClass.php', 'App\\Vendor', 'SomeClass');

        $set = $this->createSet([$onlyExcludedByAFlag], new AnalysisConfiguration());

        self::assertSame(
            [$onlyExcludedByAFlag],
            $set->forPaths([AbsolutePath::fromString(sys_get_temp_dir())]),
        );

        // The same narrowing, supplied as a flag, does remove it from the
        // run — it just does not redefine what the baseline measures.
        $narrowed = $set->stages(new CliOnlyNarrowing(excludePaths: ['vendor']))[1]
            ->apply([$onlyExcludedByAFlag]);

        self::assertSame([], $narrowed->violations);
    }

    #[Test]
    public function itListsOnlyStagesThatDefineTheMeasuredSet(): void
    {
        $set = $this->createSet([], new AnalysisConfiguration(
            excludePaths: ['generated'],
            excludeNamespaces: ['App\\Vendor'],
        ));

        $stages = $set->stages();

        self::assertSame(
            [
                ViolationFilterStage::Suppression,
                ViolationFilterStage::PathExclusion,
                ViolationFilterStage::NamespaceExclusion,
            ],
            array_map(
                static fn(ViolationFilterStageInterface $stage): ViolationFilterStage => $stage->stage(),
                $stages,
            ),
        );

        foreach ($stages as $stage) {
            self::assertTrue($stage->stage()->definesMeasuredSet());
        }
    }

    /**
     * `runForPaths()` must return the same measured set `forPaths()` does —
     * the two are not two definitions, one is the other with the run kept —
     * and must expose the {@see AnalysisResult} the run itself produced,
     * not a rebuilt or partial one.
     */
    #[Test]
    public function itReturnsTheRunAlongsideTheSameMeasuredSetForPathsReturns(): void
    {
        $ignored = self::violation('src/Legacy/Service.php', 'App\\Legacy', 'Service');
        $reported = self::violation('src/Service/UserService.php', 'App\\Service', 'UserService');

        $set = $this->createSet(
            [$ignored, $reported],
            new AnalysisConfiguration(),
            [
                'src/Legacy/Service.php' => [
                    new Suppression(rule: '*', reason: 'Reviewed', line: 1, type: SuppressionType::File),
                ],
            ],
        );

        $run = $set->runForPaths([AbsolutePath::fromString(sys_get_temp_dir())]);

        self::assertSame([$reported], $run->violations);
        self::assertSame([$ignored, $reported], $run->result->violations);
    }

    /**
     * @param list<Violation> $violations
     * @param array<string, list<Suppression>> $suppressions
     */
    private function createSet(
        array $violations,
        AnalysisConfiguration $configuration,
        array $suppressions = [],
    ): MeasuredViolationSet {
        $analyzer = self::createStub(AnalysisPipelineInterface::class);
        $analyzer->method('analyze')->willReturn(new AnalysisResult(
            violations: $violations,
            duration: 0.1,
            metrics: self::createStub(MetricRepositoryInterface::class),
            coverage: new AnalysisCoverage([RelativePath::fromString('Fixture.php')], [], []),
            suppressions: $suppressions,
        ));

        $configurationProvider = self::createStub(ConfigurationProviderInterface::class);
        $configurationProvider->method('getConfiguration')->willReturn($configuration);

        return new MeasuredViolationSet($analyzer, new SuppressionFilter(), $configurationProvider);
    }

    private static function violation(string $file, string $namespace, string $class): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString($file), 10),
            symbolPath: SymbolPath::forClass($namespace, $class),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'CCN too high',
            severity: Severity::Warning,
            metricValue: 25,
        );
    }
}
