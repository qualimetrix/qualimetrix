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
        $analyzedFiles = $result->measured->coverage->analyzedFiles;
        $failedFiles = $result->measured->coverage->failedPaths();
        $unjudged = $this->unboundSuppressionAudit->unjudgedValues(
            $options->suppressPaths,
            $options->suppressNamespaces,
            $analyzedFiles,
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
            $analyzedFiles,
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
            $result->measured->coverage->failedPaths(),
            $measurement->valueScopeJudgement(),
        );
        if ($withheld === []) {
            return null;
        }

        $excludes = self::removingExcludes($result);
        $causes = [...$excludes, ...($result->measured->coverage->generatedExcludedFiles !== [] ? ['the generated-file policy'] : [])];

        return \sprintf(
            'Code removed by %s %s %s. %s to judge %s.',
            $causes === [] ? 'an exclude entry' : implode(' and ', $causes),
            $measurement->judgement()->removedCodeIsKnown() ? 'may declare' : 'could not be read in full (too many files, an unreadable file or a link) and may declare',
            self::withheldValues($withheld),
            self::remedy($result, $excludes),
            \count($withheld) === 1 ? 'it' : 'them',
        );
    }

    /** @param non-empty-list<array{channel: string, option: string, pattern: string}> $withheld */
    private static function withheldValues(array $withheld): string
    {
        $channels = array_values(array_unique(array_column($withheld, 'channel')));
        sort($channels);
        $one = \count($withheld) === 1;

        return \sprintf(
            'the namespaces of %d suppression %s, so %s not judged (%s)',
            \count($withheld),
            $one ? 'value' : 'values',
            $one ? 'it was' : 'they were',
            implode(', ', $channels),
        );
    }

    /**
     * The exclusions that may have removed PHP. A selector with PHP evidence
     * is named. One that removed entries without it either removed no PHP or
     * removed code outside the measured universe, where no evidence is
     * recorded; the two cannot be told apart, so such removal is mentioned
     * without a name rather than dropped.
     *
     * @return list<string>
     */
    private static function removingExcludes(AnalysisResult $result): array
    {
        $named = [];
        $unattributed = false;
        foreach ($result->measured->projectScope?->judgement()->excludeSelectors() ?? [] as $selector) {
            if ($selector->phpEvidence !== null) {
                $named[] = 'exclude "' . $selector->display . '"';
            } elseif ($selector->removedEntries !== []) {
                $unattributed = true;
            }
        }
        if ($unattributed) {
            $named[] = $named === [] ? 'an exclude entry' : 'another exclude entry';
        }

        return $named;
    }

    /**
     * What would judge the withheld values. Removed namespaces are known for
     * the run as a whole, so with both causes neither remedy alone is
     * promised to suffice.
     *
     * @param list<string> $excludes
     */
    private static function remedy(AnalysisResult $result, array $excludes): string
    {
        $generated = $result->measured->coverage->generatedExcludedFiles !== [];
        $exclusion = ($result->measured->projectScope?->judgement()->removedCodeIsKnown() ?? false)
            ? 'Narrow the exclusion'
            : 'Exclude fewer files (and no unreadable file or link)';

        return match (true) {
            $generated && $excludes === [] => 'Pass --include-generated',
            $generated => $exclusion . ' and/or pass --include-generated',
            default => $exclusion,
        };
    }
}
