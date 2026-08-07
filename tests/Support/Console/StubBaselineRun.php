<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Support\Console;

use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\AbsolutePath;
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
 */
final readonly class StubBaselineRun implements BaselineRunInterface
{
    /**
     * @param list<Violation> $violations the measured set this run reports
     * @param list<string> $scope the paths it claims to have analysed
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides per-file `@qmx-threshold`
     *                                                                   annotations the run found
     */
    public function __construct(
        private array $violations,
        private array $scope,
        private AbsolutePath $projectRoot,
        private array $thresholdOverrides = [],
    ) {}

    public function measure(InputInterface $input, OutputInterface $output): BaselineRunContext
    {
        $result = new AnalysisResult(
            violations: $this->violations,
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.0,
            metrics: new InMemoryMetricRepository(),
            thresholdOverrides: $this->thresholdOverrides,
        );

        return new BaselineRunContext(
            new MeasuredAnalysisRun($result, $this->violations),
            $this->scope,
            $this->projectRoot,
        );
    }
}
