<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckReport;

use Closure;
use InvalidArgumentException;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusalInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Coverage\IncompleteAnalysisException;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Infrastructure\Console\CheckReport\FormatBinding\FormatterContextFactory;
use Qualimetrix\Infrastructure\Console\CheckReport\Projection\CheckFindingProjection;
use Qualimetrix\Infrastructure\Console\CheckScope\ResolvedCheckScope;
use Qualimetrix\Infrastructure\Console\Profile\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\ExitPolicy;
use Qualimetrix\Infrastructure\Console\Terminal\ReportPublication\ReportPublication;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\FindingProjection\Contract\FindingProjectionOptions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** One check response, from early option binding to profile-before-report publication. */
final class CheckResponse
{
    private bool $profileFinished = false;

    /**
     * @qmx-threshold code-smell.constructor-overinjection 10 -- This invocation-local response holds input, output, target ownership and completed delivery operations for one publication lifetime.
     */
    public function __construct(
        private readonly InputInterface $input,
        private readonly OutputInterface $output,
        private readonly RunTargetSession $session,
        private readonly FormatterContextFactory $contexts,
        private readonly CheckFindingProjection $projection,
        private readonly CheckReportRenderer $renderer,
        private readonly ReportPublication $publication,
        private readonly ProfilePresenter $profile,
        private readonly ?NamespacePattern $namespace,
    ) {}

    public function bindFormat(OutputFormat $format): void
    {
        $this->contexts->bindFormatBeforeAnalysis($this->input, $format->value);
    }

    /**
     * @param Closure(): AnalysisResult $analyze
     * @param list<array{message: string, source: list<array<string, mixed>>}> $diagnostics
     */
    public function run(Closure $analyze, ResolvedCheckScope $scope, FindingProjectionOptions $options, RunConfiguration $configuration, OutputFormat $format, ExitPolicy $exitPolicy, array $diagnostics): int
    {
        try {
            $result = $analyze();
            $projection = $this->projection->project($result, $this->input, $this->output, $scope, $options, $configuration);
            $this->session->targets()->settle();
            $report = $this->renderer->render($result, $this->input, $this->output, $scope, $projection, $options, $configuration, $format, $exitPolicy, $diagnostics, $this->namespace);
            $this->finishProfile($result->measured->coverage->isComplete() ? 'complete' : 'incomplete');
            $this->publication->publishReport($report, $this->input, $this->output, $this->session->targets());
            $this->session->markOutputPublished();
            return $report->exitCode;
        } catch (Throwable $failure) {
            $this->finishProfile(self::profileOutcome($failure));
            throw $failure;
        }
    }

    private function finishProfile(string $outcome): void
    {
        if ($this->profileFinished) {
            return;
        }
        $this->profileFinished = true;
        $this->profile->outcome($this->input, $this->output, $this->session->targets(), $outcome);
        $this->session->targets()->settle();
    }

    private static function profileOutcome(Throwable $failure): string
    {
        if ($failure instanceof IncompleteAnalysisException) {
            return 'incomplete';
        }
        return $failure instanceof RefusalInterface || $failure instanceof EnvironmentFailureInterface || $failure instanceof InvalidArgumentException ? 'refused' : 'internal';
    }
}
