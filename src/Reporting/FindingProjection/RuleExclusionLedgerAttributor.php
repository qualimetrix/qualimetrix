<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionAttribution;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;

/**
 * The two per-rule exclusion ledger halves — {@see SuppressionMechanism::RuleNamespaceExclusion}
 * and {@see SuppressionMechanism::RulePathExclusion} — split out of
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

        $inert = $this->inertSuppressors($ruleConfiguration->all(), $pathHitsByRule, $namespaceHitsByRule, $channelHitsByRule);

        return [$suppressed, $inert];
    }

    private function mechanismOf(RuleExclusionAttribution $attribution): SuppressionMechanism
    {
        return $attribution->isPathExclusion
            ? SuppressionMechanism::RulePathExclusion
            : SuppressionMechanism::RuleNamespaceExclusion;
    }

    /**
     * @param array<string, array<string, true>> $pathHitsByRule
     * @param array<string, array<string, true>> $namespaceHitsByRule
     * @param array<string, array<string, array<string, true>>> $channelHitsByRule rule => selector => pattern => true
     */
    private function recordHits(
        RuleExclusionAttribution $attribution,
        array &$pathHitsByRule,
        array &$namespaceHitsByRule,
        array &$channelHitsByRule,
    ): void {
        if ($attribution->isPathExclusion) {
            foreach ($attribution->matchedPatterns as $pattern) {
                $pathHitsByRule[$attribution->producerRuleName][$pattern] = true;
            }

            return;
        }

        foreach ($attribution->matchedPatterns as $pattern) {
            $namespaceHitsByRule[$attribution->producerRuleName][$pattern] = true;
        }

        foreach ($attribution->matchedChannelPatterns as $hit) {
            $channelHitsByRule[$attribution->producerRuleName][$hit['selector']][$hit['pattern']] = true;
        }
    }

    /**
     * @param array<string, mixed> $rulesConfig
     * @param array<string, array<string, true>> $pathHitsByRule
     * @param array<string, array<string, true>> $namespaceHitsByRule
     * @param array<string, array<string, array<string, true>>> $channelHitsByRule rule => selector => pattern => true
     *
     * @return list<InertSuppressor>
     */
    private function inertSuppressors(array $rulesConfig, array $pathHitsByRule, array $namespaceHitsByRule, array $channelHitsByRule): array
    {
        $inert = [];

        foreach ($rulesConfig as $ruleName => $ruleOptions) {
            if (!\is_array($ruleOptions)) {
                continue;
            }

            $inert = [
                ...$inert,
                ...$this->inertFor(SuppressionMechanism::RulePathExclusion, $ruleName, $this->configuredPathPatterns($ruleOptions), $pathHitsByRule),
                ...$this->inertFor(SuppressionMechanism::RuleNamespaceExclusion, $ruleName, $this->configuredNamespacePatterns($ruleOptions), $namespaceHitsByRule),
                ...$this->inertForChannels($ruleName, $this->configuredChannelPatterns($ruleOptions), $channelHitsByRule),
            ];
        }

        return $inert;
    }

    /**
     * @param list<string> $patterns
     * @param array<string, array<string, true>> $hitsByRule
     *
     * @return list<InertSuppressor>
     */
    private function inertFor(SuppressionMechanism $mechanism, string $ruleName, array $patterns, array $hitsByRule): array
    {
        $inert = [];

        foreach ($patterns as $pattern) {
            if (!isset($hitsByRule[$ruleName][$pattern])) {
                $inert[] = new InertSuppressor($mechanism, $ruleName . ': ' . $pattern);
            }
        }

        return $inert;
    }

    /**
     * @param array<string, list<string>> $channelPatterns selector => patterns
     * @param array<string, array<string, array<string, true>>> $channelHitsByRule rule => selector => pattern => true
     *
     * @return list<InertSuppressor>
     */
    private function inertForChannels(string $ruleName, array $channelPatterns, array $channelHitsByRule): array
    {
        $inert = [];

        foreach ($channelPatterns as $selector => $patterns) {
            foreach ($patterns as $pattern) {
                if (!isset($channelHitsByRule[$ruleName][$selector][$pattern])) {
                    $inert[] = new InertSuppressor(
                        SuppressionMechanism::RuleNamespaceExclusion,
                        $ruleName . ': ' . $selector . ' ' . $pattern,
                    );
                }
            }
        }

        return $inert;
    }

    /**
     * Reads `suppress_paths` off one rule's raw options, accepting both the
     * key an author writes in `qmx.yaml` and the camelCase form
     * {@see RuleConfigurationInterface::all()} actually returns once the
     * configuration pipeline's section-normalization policy has run.
     *
     * @param array<string, mixed> $ruleOptions
     *
     * @return list<string>
     */
    private function configuredPathPatterns(array $ruleOptions): array
    {
        return $this->stringList($ruleOptions['suppressPaths'] ?? $ruleOptions['suppress_paths'] ?? []);
    }

    /**
     * @param array<string, mixed> $ruleOptions
     *
     * @return list<string>
     */
    private function configuredNamespacePatterns(array $ruleOptions): array
    {
        return $this->stringList($ruleOptions['suppressNamespaces'] ?? $ruleOptions['suppress_namespaces'] ?? []);
    }

    /**
     * @param array<string, mixed> $ruleOptions
     *
     * @return array<string, list<string>> selector => patterns
     */
    private function configuredChannelPatterns(array $ruleOptions): array
    {
        $channels = $ruleOptions['suppressNamespaceChannels'] ?? $ruleOptions['suppress_namespace_channels'] ?? [];

        if (!\is_array($channels)) {
            return [];
        }

        $result = [];
        foreach ($channels as $selector => $patterns) {
            if (\is_string($selector)) {
                $result[$selector] = $this->stringList($patterns);
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (\is_string($value)) {
            return [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (\is_string($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }
}
