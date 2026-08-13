<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Support\Console;

use Closure;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Baseline\RunScope;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Violation\Violation;
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
     * @param list<Violation> $violations the measured set this run reports
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
        private array $violations,
        private array $scope,
        private AbsolutePath $projectRoot,
        private array $thresholdOverrides = [],
        private ?Closure $onMeasure = null,
        private ?MetricRepositoryInterface $metrics = null,
    ) {}

    public function measure(InputInterface $input, OutputInterface $output): BaselineRunContext
    {
        ($this->onMeasure ?? static fn(): null => null)();

        $result = new AnalysisResult(
            violations: $this->violations,
            duration: 0.0,
            metrics: $this->metrics ?? new InMemoryMetricRepository(),
            coverage: new AnalysisCoverage([RelativePath::fromString('Fixture.php')], [], []),
            thresholdOverrides: $this->thresholdOverrides,
        );

        return new BaselineRunContext(
            new MeasuredAnalysisRun($result, $this->violations),
            RunScope::fromRecorded($this->scope),
            $this->projectRoot,
        );
    }
}
