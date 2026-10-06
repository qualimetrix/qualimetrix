<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Support;

use Closure;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\DirectiveObservations;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeQueryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeSnapshot;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Run\Contract\Pipeline\MeasuredRunResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Console\Command\BaselineRunContext;
use Qualimetrix\Infrastructure\Console\Command\BaselineRunInterface;
use Qualimetrix\Infrastructure\Console\MeasuredAnalysisRun;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A measured run with a known answer.
 *
 * What the baseline commands are tested for is what they do with a set of
 * findings — refuse, write, list, explain — not how the set is produced.
 * Reaching those behaviours through a real analysis would mean building a
 * source fixture for every boundary case, including ones no PHP file
 * produces on demand (a `lower`-direction channel whose group grew by one
 * member, say), and would test the pipeline on the way.
 *
 * `$onMeasure` is the one thing a stub of a *run* must still be able to do:
 * a real run resolves configuration and touches the world before it answers,
 * and two of the properties under test are about what happens in that window
 * — a `computed.*` declaration that only exists afterwards, and a baseline
 * file another process rewrites while it is open.
 */
final readonly class StubBaselineRun implements BaselineRunInterface
{
    /**
     * @param list<Finding> $findings the measured set this run reports
     * @param list<string> $scope the paths it claims to have analysed, already portable
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides per-file `@qmx-threshold`
     *                                                                   annotations the run found
     * @param ?Closure(): void $onMeasure side effect of running, performed before the context is
     *                                    returned — what the real run does to the world on its way
     *                                    to an answer
     * @param ?MetricRepositoryInterface $metrics the run's measured symbols, as
     *                                            {@see \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult::$metrics}
     *                                            would carry them; defaults to an empty repository
     *                                            when a test has no need to populate declaration sites
     */
    public function __construct(
        private array $findings,
        private array $scope,
        private AbsolutePath $projectRoot,
        private array $thresholdOverrides = [],
        private ?Closure $onMeasure = null,
        private ?MetricRepositoryInterface $metrics = null,
    ) {}

    public function measure(InputInterface $input, OutputInterface $output): BaselineRunContext
    {
        ($this->onMeasure ?? static fn(): null => null)();

        $files = [RelativePath::fromString('Fixture.php')];
        foreach ($this->findings as $finding) {
            if ($finding->location->file !== null) {
                $files[$finding->location->file->value()] = $finding->location->file;
            }
        }
        $files = array_values($files);

        $result = AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: $this->metrics ?? new InMemoryMetricRepository(),
                coverage: new AnalysisCoverage($files, [], []),
                namespaceTree: null,
                projectScope: null,
                duration: 0.0,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), $files, []),
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: $this->thresholdOverrides,
            ),
            ruleExecution: null,
            latePublished: $this->findings,
        );

        $paths = array_map(
            fn(string $path): AbsolutePath => str_starts_with($path, '/')
                ? AbsolutePath::fromString($path)
                : $this->projectRoot->joinRelative(RelativePath::fromString($path)),
            $this->scope,
        );
        $configuration = new RunConfiguration(
            pathExcludes: [],
            projectRoot: $this->projectRoot,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            projectScope: new ProjectScopeMeasurement(
                universe: new ProjectScopeUniverse(
                    projectRoot: $this->projectRoot,
                    pathsAuthored: true,
                    denominator: [],
                    prunedTargets: [],
                    reasons: [],
                    namespaceMapUsable: false,
                    pathResolutions: [],
                ),
                paths: $paths,
                scopeState: ProjectScopeState::Narrowed,
                uncoveredRoots: [],
            ),
            authoredPathExcludes: [],
            autoloadDevPolicy: AutoloadDevPolicy::Exclude,
        );

        $tree = new class ($files) implements ProjectTreeQueryInterface {
            /** @param list<RelativePath> $files */
            public function __construct(private array $files) {}

            public function snapshot(ProjectScopeUniverse $universe): ProjectTreeSnapshot
            {
                return new ProjectTreeSnapshot($this->files, [], true);
            }

            public function hasFile(AbsolutePath $root, RelativePath $file): ProjectEntryPresence
            {
                foreach ($this->files as $present) {
                    if ($present->equals($file)) {
                        return ProjectEntryPresence::Present;
                    }
                }

                return ProjectEntryPresence::Absent;
            }

            public function hasDirectory(AbsolutePath $directory): ProjectEntryPresence
            {
                return ProjectEntryPresence::Present;
            }
        };
        $coverage = new RunCoverage(
            RunScope::fromRecorded($this->scope),
            $result->measured->coverage,
            RecordedExclusions::fromRunConfiguration($configuration),
            $configuration->projectScope->universe,
            [],
            $tree,
            $result->measured->subjectCoverage,
        );

        return new BaselineRunContext(
            new MeasuredAnalysisRun($result, $this->findings),
            RunScope::fromRecorded($this->scope),
            $this->projectRoot,
            $configuration,
            $coverage,
        );
    }
}
