<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckScope;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeChannels;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Reporting\Contract\Report\ReportProjectScope;
use Qualimetrix\Reporting\FindingProjection\Contract\FindingProjectionOptions;

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

    /**
     * The warning for a run whose namespace suppressions stayed unjudged only
     * because removed code (an `exclude` of PHP files, generated files) may
     * declare their namespaces; null when nothing is in that state.
     *
     * Any other withholding door already has its own scope warning, and a
     * value unjudged for another reason is not this warning's business.
     */
    public function removedCodeWarning(ReportProjectScope $scope, AnalysisResult $result): ?string
    {
        $judgement = ($result->measured->projectScope ?? throw new LogicException('A pipeline result requires measured project scope'))->judgement();
        $doors = $judgement->withheldBy();
        if ($doors === [] || array_filter($doors, static fn(ProjectScopeDoor $door): bool => $door !== ProjectScopeDoor::Exclude && $door !== ProjectScopeDoor::Generated) !== []) {
            return null;
        }

        $channels = [];
        $count = 0;
        foreach ($scope->unjudgedValues as $value) {
            if (UnboundSuppressionAudit::isNamespaceValue($value)) {
                $channels[$value['channel']] = true;
                ++$count;
            }
        }
        if ($count === 0) {
            return null;
        }

        $causes = [];
        foreach ($judgement->excludeSelectors() as $selector) {
            if ($selector->phpEvidence !== null) {
                $causes[] = 'exclude "' . $selector->display . '"';
            }
        }
        if (\in_array(ProjectScopeDoor::Generated, $doors, true)) {
            $causes[] = 'generated-file filtering';
        }
        $channelNames = array_keys($channels);
        sort($channelNames);

        return \sprintf(
            'Code removed by %s may declare the namespaces of %d suppression %s, so %s not judged (%s). Narrow the exclusion to judge them.',
            implode(' and ', $causes),
            $count,
            $count === 1 ? 'value' : 'values',
            $count === 1 ? 'it was' : 'they were',
            implode(', ', $channelNames),
        );
    }
}
