<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckScope;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeChannels;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\ReportProjectScope;

final readonly class ProjectScopePresenter
{
    public function __construct(private UnboundSuppressionAudit $unboundSuppressionAudit, private ObservedProjectScopeReasons $observedProjectScopeReasons) {}

    public function project(
        AnalysisResult $result,
        FindingProjectionOptions $options,
    ): ReportProjectScope {
        $measurement = $result->measured->projectScope ?? throw new LogicException('A pipeline result requires measured project scope');
        $valueScope = $measurement->valueScopeJudgement();
        $judgement = $measurement->judgement();
        $facts = $measurement->universe->manifestFacts;
        $reasons = $this->observedProjectScopeReasons->forMainSource($facts->source(), $facts->state, $measurement->universe->projectRoot);
        $namespaces = $result->measured->namespaceTree?->getAllNamespaces();
        $unjudged = $this->unboundSuppressionAudit->unjudgedValues(
            $options->suppressPaths,
            $options->suppressNamespaces,
            $namespaces,
            $valueScope,
        );
        foreach ($judgement->excludeSelectors() as $selector) {
            if (\in_array($selector->outcome, [ExcludeSelectorOutcome::NotJudged, ExcludeSelectorOutcome::CoveredByOtherSource], true)) {
                $unjudged[] = [
                    'channel' => ProjectScopeChannels::WALK_CHANNEL,
                    'option' => 'exclude',
                    'pattern' => $selector->display,
                ];
            }
        }
        $judgedChannels = $this->unboundSuppressionAudit->judgedChannels(
            $options->suppressPaths,
            $options->suppressNamespaces,
            $namespaces,
            $valueScope,
        );
        foreach ($judgement->excludeSelectors() as $selector) {
            if (\in_array($selector->outcome, [ExcludeSelectorOutcome::Removed, ExcludeSelectorOutcome::Unmatched, ExcludeSelectorOutcome::CoveredBySameSource], true)) {
                $judgedChannels[] = ProjectScopeChannels::WALK_CHANNEL;
                break;
            }
        }

        return ReportProjectScope::measured($measurement, $unjudged, $judgedChannels)->withReasons($reasons);
    }
}
