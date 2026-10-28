<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckReport\Projection;

use LogicException;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit;
use Qualimetrix\Analysis\Finding\SuppressionBinding\ValueScopeJudgement;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Infrastructure\Console\BaselineCommand\BaselineProjectionCoverage;
use Qualimetrix\Reporting\FindingProjection\Contract\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\Contract\FindingProjectionResult;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;

/** @qmx-threshold coupling.instability 0.800001 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies. */
final readonly class FindingProjection
{
    public function __construct(private FindingProjector $findingProjector, private UnboundSuppressionAudit $unboundSuppressionAudit, private BaselineProjectionCoverage $baselineCoverage) {}

    public function project(
        AnalysisResult $result,
        FindingProjectionOptions $options,
        RunConfiguration $configuration,
    ): FindingProjectionResult {
        $options = $this->baselineCoverage->withRunCoverage($options, $result, $configuration);
        $publication = $result->populationPublication ?? throw new LogicException('Late finding projection requires captured channel publication');
        $measurement = $result->measured->projectScope ?? throw new LogicException('A pipeline result requires measured project scope');
        $suppression = $this->unboundSuppressions($result, $options, $measurement->valueScopeJudgement());
        $filterResult = $this->findingProjector->project(
            [...$result->findings(), ...$suppression['findings']],
            $result->directives->suppressions,
            $options,
            $publication,
        );
        $filterResult = $filterResult->withPopulation($filterResult->population->merge($suppression['population']));

        return $filterResult;
    }

    /** @return array{findings: list<\Qualimetrix\Analysis\Finding\Contract\Finding>, population: \Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation} */
    private function unboundSuppressions(
        AnalysisResult $result,
        FindingProjectionOptions $options,
        ValueScopeJudgement $valueScope,
    ): array {
        return $this->unboundSuppressionAudit->auditResult(
            $options->suppressPaths,
            $options->suppressNamespaces,
            $result->measured->coverage->fileUniverse(),
            $result->measured->namespaceTree?->getAllNamespaces(),
            $valueScope,
            $result->populationPublication ?? throw new LogicException('Suppression accounting requires captured channel publication'),
        );
    }
}
