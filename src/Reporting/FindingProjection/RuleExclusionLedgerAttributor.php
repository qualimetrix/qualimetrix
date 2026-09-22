<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionAttribution;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;

/**
 * The two per-rule exclusion ledger halves — {@see SuppressionMechanism::RuleNamespaceSuppression}
 * and {@see SuppressionMechanism::RulePathSuppression} — split out of
 * {@see SuppressionCompositionBuilder} as its own subject: publishing a
 * ledger-excluded finding under the mechanism and producer that removed it,
 * and finding the configured patterns that fired nothing, both read the same
 * {@see \Qualimetrix\Analysis\Finding\Contract\RuleExclusionAttribution} the ledger recorded, and neither has
 * anything to do with the five
 * {@see \Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage}
 * mechanisms the sibling class attributes.
 *
 * **Reads the decision instead of re-asking it.** Every `SuppressedFinding`
 * here is attributed from the `RuleExclusionAttribution`
 * {@see \Qualimetrix\Analysis\Finding\FindingExclusionLedger::keeps()} recorded
 * at the moment it excluded the finding — the same producer name, under the
 * same short-circuit order, that the decision itself used. This class used to
 * re-derive "who excluded this" from `Finding::$ruleName` and
 * `RuleConfigurationInterface`'s `is*Excluded()` predicates; that recomputation
 * could name the wrong producer wherever a rule instance publishes findings
 * under several producer names (the computed-metric family), silently
 * dropping the finding from the composition. Only the *inert*-pattern
 * enumeration below still reads `RuleConfigurationInterface::all()` — that is
 * not a decision predicate, it is the full universe of configured patterns a
 * "which pattern fired nothing" answer has to range over, and no attribution
 * exists for a pattern nothing ever tested.
 */
final readonly class RuleExclusionLedgerAttributor
{
    /**
     * @return array{0: list<SuppressedFinding>, 1: list<InertSuppressor>}
     */
    public function attribute(RuleExecutionResult $ruleExecution, RuleConfigurationInterface $ruleConfiguration): array
    {
        $suppressed = [];
        $pathHitsByRule = [];
        $namespaceHitsByRule = [];
        $channelHitsByRule = [];

        $stats = $ruleExecution->exclusions;

        foreach ($stats->excludedFindings as $index => $finding) {
            $attribution = $stats->attributions[$index] ?? null;

            if ($attribution === null) {
                // No attribution recorded for this capture: nothing to publish it as.
                // Reachable only if a caller hand-builds RuleExclusionStats with
                // excludedFindings but no matching attributions (see the class docs
                // on FindingExclusionLedger::stats() — the two are always parallel
                // when the ledger itself produced them).
                continue;
            }

            $suppressed[] = new SuppressedFinding($finding, $this->mechanismOf($attribution), $attribution->producerRuleName);
            $this->recordHits($attribution, $pathHitsByRule, $namespaceHitsByRule, $channelHitsByRule);
        }

        $inert = $this->inertSuppressors($ruleConfiguration, $pathHitsByRule, $namespaceHitsByRule, $channelHitsByRule);

        return [$suppressed, $inert];
    }

    private function mechanismOf(RuleExclusionAttribution $attribution): SuppressionMechanism
    {
        return $attribution->isPathExclusion
            ? SuppressionMechanism::RulePathSuppression
            : SuppressionMechanism::RuleNamespaceSuppression;
    }

    /**
     * @param array<string, array<string, true>> $pathHitsByRule
     * @param array<string, array<string, true>> $namespaceHitsByRule
     * @param array<string, array<string, array<string, true>>> $channelHitsByRule rule => selector => selector identity => true
     */
    private function recordHits(
        RuleExclusionAttribution $attribution,
        array &$pathHitsByRule,
        array &$namespaceHitsByRule,
        array &$channelHitsByRule,
    ): void {
        if ($attribution->isPathExclusion) {
            foreach ($attribution->matchedPatterns as $pattern) {
                $pathHitsByRule[$attribution->producerRuleName][$pattern->display()] = true;
            }

            return;
        }

        foreach ($attribution->matchedPatterns as $pattern) {
            $namespaceHitsByRule[$attribution->producerRuleName][$pattern->display()] = true;
        }

        foreach ($attribution->matchedChannelPatterns as $hit) {
            $channelHitsByRule[$attribution->producerRuleName][$hit['selector']][$hit['pattern']->display()] = true;
        }
    }

    /**
     * @param array<string, array<string, true>> $pathHitsByRule
     * @param array<string, array<string, true>> $namespaceHitsByRule
     * @param array<string, array<string, array<string, true>>> $channelHitsByRule rule => selector => pattern => true
     *
     * @return list<InertSuppressor>
     */
    private function inertSuppressors(RuleConfigurationInterface $ruleConfiguration, array $pathHitsByRule, array $namespaceHitsByRule, array $channelHitsByRule): array
    {
        $inert = [];

        foreach ($ruleConfiguration->all() as $ruleName => $ruleOptions) {
            if (!\is_array($ruleOptions)) {
                continue;
            }

            $inert = [
                ...$inert,
                ...$this->inertFor(SuppressionMechanism::RulePathSuppression, $ruleName, $ruleConfiguration->pathExclusions($ruleName), $pathHitsByRule),
                ...$this->inertFor(SuppressionMechanism::RuleNamespaceSuppression, $ruleName, $ruleConfiguration->namespaceExclusions($ruleName), $namespaceHitsByRule),
                ...$this->inertForChannels($ruleName, $ruleConfiguration->namespaceChannelExclusions($ruleName), $channelHitsByRule),
            ];
        }

        return $inert;
    }

    /**
     * @param list<PathPattern|NamespacePattern> $patterns
     * @param array<string, array<string, true>> $hitsByRule
     *
     * @return list<InertSuppressor>
     */
    private function inertFor(SuppressionMechanism $mechanism, string $ruleName, array $patterns, array $hitsByRule): array
    {
        $inert = [];

        foreach ($patterns as $pattern) {
            if (!isset($hitsByRule[$ruleName][$pattern->definition->display()])) {
                $inert[] = new InertSuppressor($mechanism, $ruleName . ': ' . $pattern->definition->display());
            }
        }

        return $inert;
    }

    /**
     * @param array<string, list<NamespacePattern>> $channelPatterns selector => patterns
     * @param array<string, array<string, array<string, true>>> $channelHitsByRule rule => selector => pattern => true
     *
     * @return list<InertSuppressor>
     */
    private function inertForChannels(string $ruleName, array $channelPatterns, array $channelHitsByRule): array
    {
        $inert = [];

        foreach ($channelPatterns as $selector => $patterns) {
            foreach ($patterns as $pattern) {
                if (!isset($channelHitsByRule[$ruleName][$selector][$pattern->definition->display()])) {
                    $inert[] = new InertSuppressor(
                        SuppressionMechanism::RuleNamespaceSuppression,
                        $ruleName . ': ' . $selector . ' ' . $pattern->definition->display(),
                    );
                }
            }
        }

        return $inert;
    }

}
