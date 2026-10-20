<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckReport\Assembly;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\Contract\Formatting\FormatterContext;
use Qualimetrix\Reporting\Contract\Report\Report;
use Qualimetrix\Reporting\Contract\Report\ReportProjectScope;
use Qualimetrix\Reporting\DrillDown\DrillDownBinding;
use Qualimetrix\Reporting\DrillDown\FindingFilter;
use Qualimetrix\Reporting\DrillDown\OutOfScopeFindings;
use Qualimetrix\Reporting\FindingProjection\Contract\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\Contract\FindingProjectionResult;
use Qualimetrix\Reporting\FindingProjection\SuppressionCompositionBuilder;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\ReportBuilder;

/** @qmx-threshold coupling.instability 0.900001 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies. */
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

        if ($context->class !== null) {
            $declarations = $binding->classDeclarations($context->class, $metrics);
            if (\count($declarations) > 1) {
                $advice = $binding->classDeclarationFileCount($context->class, $metrics) === 1
                    ? 'Exact selection is unavailable when one file declares the class more than once; analyse the file without --class or rename one declaration.'
                    : 'Analyse one declaring file to select an exact declaration.';
                throw ConfigurationRefusal::aboutCommandLineInput('--class', \sprintf(
                    'Class "%s" selects several declarations: %s. %s',
                    $context->class,
                    implode(', ', $declarations),
                    $advice,
                ));
            }
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
