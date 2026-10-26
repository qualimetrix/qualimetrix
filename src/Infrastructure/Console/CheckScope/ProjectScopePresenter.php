<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckScope;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeChannels;
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
        $failedFiles = $result->measured->coverage->failedFilesCount();
        $unjudged = $this->unboundSuppressionAudit->unjudgedValues(
            $options->suppressPaths,
            $options->suppressNamespaces,
            $namespaces,
            $failedFiles,
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
            $failedFiles,
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
     * The warning for a run whose suppression values removed code alone kept
     * from being judged; null when there is none. Which values those are is
     * the audit's answer: this only words it.
     */
    public function removedCodeWarning(AnalysisResult $result, FindingProjectionOptions $options): ?string
    {
        $measurement = $result->measured->projectScope ?? throw new LogicException('A pipeline result requires measured project scope');
        $withheld = $this->unboundSuppressionAudit->withheldForRemovedCode(
            $options->suppressPaths,
            $options->suppressNamespaces,
            $result->measured->namespaceTree?->getAllNamespaces(),
            $result->measured->coverage->failedFilesCount(),
            $measurement->valueScopeJudgement(),
        );
        if ($withheld === []) {
            return null;
        }

        $channels = array_values(array_unique(array_column($withheld, 'channel')));
        sort($channels);
        $one = \count($withheld) === 1;

        return \sprintf(
            'Code removed by %s may declare the namespaces of %d suppression %s, so %s not judged (%s). Narrow the exclusion to judge %s.',
            implode(' and ', self::removalCauses($result)),
            \count($withheld),
            $one ? 'value' : 'values',
            $one ? 'it was' : 'they were',
            implode(', ', $channels),
            $one ? 'it' : 'them',
        );
    }

    /**
     * Every selector that removed an entry is named, because the removed
     * namespaces are known only for the run as a whole; a removal no selector
     * verdict records is still named, generically.
     *
     * @return list<string>
     */
    private static function removalCauses(AnalysisResult $result): array
    {
        $causes = [];
        foreach ($result->measured->projectScope?->judgement()->excludeSelectors() ?? [] as $selector) {
            if ($selector->removedEntries !== []) {
                $causes[] = 'exclude "' . $selector->display . '"';
            }
        }
        if ($result->measured->coverage->generatedExcludedFiles !== []) {
            $causes[] = 'generated-file filtering';
        }

        return $causes === [] ? ['an exclude entry'] : $causes;
    }
}
