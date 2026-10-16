<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckReport;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\DrillDown\DrillDownBinding;
use Qualimetrix\Reporting\DrillDown\FindingFilter;
use Qualimetrix\Reporting\DrillDown\OutOfScopeFindings;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Qualimetrix\Reporting\FindingProjection\SuppressionCompositionBuilder;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Reporting\ReportProjectScope;

final readonly class ReportAssembler
{
    public function __construct(private FindingFilter $findingFilter, private SummaryEnricher $summaryEnricher, private RuleConfigurationInterface $ruleConfiguration) {}

    /** @param list<array{message: string, source: list<array<string, mixed>>}> $configurationDiagnostics */
    public function assemble(
        AnalysisResult $analysisResult,
        FindingProjectionResult $projection,
        FormatterContext $context,
        ?ReportProjectScope $projectScope,
        array $configurationDiagnostics = [],
        ?FindingProjectionOptions $suppressionOptions = null,
    ): Report {
        $findings = $projection->findings;
        $this->assertDrillDownBinds($context, $analysisResult);
        $fileNamespaces = FileNamespaceIndex::fromRepository($analysisResult->measured->repository);
        $filteredFindings = $this->findingFilter->filterFindings($findings, $context, $fileNamespaces);
        $coverage = ReportCoverageProjection::of($analysisResult->measured->coverage, AbsolutePath::fromString($context->basePath));
        $builder = ReportBuilder::create()
            ->fileNamespaces($fileNamespaces)
            ->addFindings($filteredFindings)
            ->filesAnalyzed($analysisResult->measured->coverage->analyzedFilesCount())
            ->filesSkipped($analysisResult->measured->coverage->skippedFilesCount())
            ->duration($analysisResult->measured->duration)
            ->metrics($analysisResult->measured->repository)
            ->namespaceTree($analysisResult->measured->namespaceTree)
            ->coverage($coverage)
            ->configurationDiagnostics($configurationDiagnostics)
            ->computedMetricEvaluation($analysisResult->computedMetricEvaluation)
            ->population($this->mergedPopulation($analysisResult, $projection));
        if ($projectScope !== null) {
            $builder->projectScope($projectScope);
        }
        if ($context->namespace !== null || $context->class !== null) {
            $builder->outOfScope(OutOfScopeFindings::between($findings, $filteredFindings));
        }
        if ($suppressionOptions !== null) {
            $execution = $analysisResult->ruleExecution ?? throw new LogicException('Suppression composition requires rule execution.');
            $builder->suppressionComposition((new SuppressionCompositionBuilder())->build($projection, $execution, $this->ruleConfiguration, $suppressionOptions));
        }
        return $this->summaryEnricher->enrich($builder->build());
    }

    private function mergedPopulation(AnalysisResult $analysisResult, ?FindingProjectionResult $filterResult): JudgedPopulation
    {
        return $analysisResult->population->merge($filterResult->population ?? JudgedPopulation::empty());
    }

    private function assertDrillDownBinds(FormatterContext $context, AnalysisResult $analysisResult): void
    {
        $binding = new DrillDownBinding();
        $metrics = $analysisResult->measured->repository;

        if ($context->namespace !== null
            && $binding->namespaceBindings($context->namespace, $metrics, $analysisResult->measured->namespaceTree) === 0
        ) {
            throw ConfigurationRefusal::aboutCommandLineInput('--namespace', \sprintf(
                'Namespace "%s" matched none of the %d analyzed namespaces and symbol names it is compared against. '
                . 'The report would be empty because nothing was selected, not because nothing was found.',
                $context->namespace->definition->display(),
                $binding->namespaceUniverseSize($metrics, $analysisResult->measured->namespaceTree),
            ));
        }

        if ($context->class !== null && $binding->classBindings($context->class, $metrics) === 0) {
            throw ConfigurationRefusal::aboutCommandLineInput('--class', \sprintf(
                'Class "%s" matched none of the %d classes in the analyzed code. '
                . 'The report would be empty because nothing was selected, not because nothing was found.',
                $context->class,
                $binding->classUniverseSize($metrics),
            ));
        }
    }
}
