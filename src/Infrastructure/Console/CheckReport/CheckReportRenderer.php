<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckReport;

use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Pattern\NamespacePattern;

use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;

use Qualimetrix\Infrastructure\Console\CheckReport\Assembly\ReportAssembler;

use Qualimetrix\Infrastructure\Console\CheckReport\FormatBinding\ReportFormatter;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\ExitPolicy;
use Qualimetrix\Infrastructure\Console\Terminal\ReportPublication\RenderedCheckReport;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\FindingProjection\Contract\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\Contract\FindingProjectionResult;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @qmx-threshold coupling.instability 0.842107 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies. */
final readonly class CheckReportRenderer
{
    public function __construct(private ReportAssembler $assembler, private ReportFormatter $formatter, private ExitCodeResolver $exitCodeResolver, private ProfilerInterface $profiler, private \Qualimetrix\Infrastructure\Console\CheckScope\ProjectScopePresenter $scopePresenter) {}

    /**
     * @param list<array{message: string, source: list<array<string, mixed>>}> $configurationDiagnostics
     *
     * @qmx-threshold code-smell.long-parameter-list 12 -- Rendering consumes independently resolved analysis, scope, projection, configuration and output facts; a parameter bag would only hide their explicit boundary.
     */
    public function render(
        AnalysisResult $result,
        InputInterface $input,
        OutputInterface $output,
        \Qualimetrix\Infrastructure\Console\CheckScope\ResolvedCheckScope $scope,
        FindingProjectionResult $projection,
        FindingProjectionOptions $options,
        \Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration $configuration,
        OutputFormat $format,
        ExitPolicy $exitPolicy,
        array $configurationDiagnostics = [],
        ?NamespacePattern $namespace = null,
    ): RenderedCheckReport {
        $projectScope = $this->scopePresenter->project($result, $options);
        $suppressionOptions = ($format->value === 'suppressed' || $input->getOption('show-suppressed') === true)
            ? $options
            : null;
        $this->profiler->start('reporting', 'pipeline');
        try {
            $context = $this->formatter->context($input, $output, $configuration->projectRoot, $format, $scope->scope->reportScope !== null, $namespace);
            $report = $this->assembler->assemble($result, $projection, $context->context, $projectScope, $configurationDiagnostics, $suppressionOptions);
            $formatted = $this->formatter->format($report, $context, $output);
            return new RenderedCheckReport($formatted, $format->value, $this->exitCodeResolver->resolve($projection->findings, $report->coverage, $exitPolicy));
        } finally {
            $this->profiler->stop('reporting');
        }
    }
}
