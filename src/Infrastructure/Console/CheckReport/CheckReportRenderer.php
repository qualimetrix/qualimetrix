<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckReport;

use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;

use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\ExitPolicy;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
