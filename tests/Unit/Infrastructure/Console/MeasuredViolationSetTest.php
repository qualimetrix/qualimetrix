<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\ViolationFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\MeasuredViolationSet;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeResult;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

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
            new FindingProjectionOptions(),
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

        $options = new FindingProjectionOptions(
            excludePaths: ['generated'],
            excludeNamespaces: ['App\\Vendor'],
        );
        $set = $this->createSet(
            [$excludedByPath, $excludedByNamespace, $reported],
            $options,
        );

        self::assertSame([$reported], $set->forPaths(
            [AbsolutePath::fromString(sys_get_temp_dir())],
            options: $options,
        ));
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

        $set = $this->createSet([$onlyExcludedByAFlag], new FindingProjectionOptions());

        self::assertSame(
            [$onlyExcludedByAFlag],
            $set->forPaths([AbsolutePath::fromString(sys_get_temp_dir())]),
        );

        // The same narrowing, supplied as a flag, does remove it from the
        // run — it just does not redefine what the baseline measures.
        self::assertSame([], $set->forPaths(
            [AbsolutePath::fromString(sys_get_temp_dir())],
            options: new FindingProjectionOptions(excludePaths: ['vendor']),
        ));
    }

    #[Test]
    public function itListsOnlyStagesThatDefineTheMeasuredSet(): void
    {
        $set = $this->createSet([], new FindingProjectionOptions(
            excludePaths: ['generated'],
            excludeNamespaces: ['App\\Vendor'],
        ));

        foreach ([ViolationFilterStage::Suppression, ViolationFilterStage::PathExclusion, ViolationFilterStage::NamespaceExclusion] as $stage) {
            self::assertTrue($stage->definesMeasuredSet());
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
            new FindingProjectionOptions(),
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
        FindingProjectionOptions $configuration,
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

        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $projector = new FindingProjector(
            new SuppressionFilter(),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            $declarations,
            new class implements GitScopeQueryInterface {
                public function resolve(GitScopeRequest $request): GitScopeResult
                {
                    return new GitScopeResult([], []);
                }
            },
        );

        return new MeasuredViolationSet($analyzer, $projector);
    }

    private static function violation(string $file, string $namespace, string $class): Violation
    {
        $path = RelativePath::fromString($file);
        $symbol = SymbolPath::forClass($namespace, $class);

        return new Violation(
            location: new Location($path, 10),
            subject: MetricSubject::declaration(new DeclarationPath($symbol, $path, 10)),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'CCN too high',
            severity: Severity::Warning,
            metricValue: 25,
        );
    }
}
