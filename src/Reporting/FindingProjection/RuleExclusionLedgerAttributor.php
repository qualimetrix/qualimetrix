<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Core\Util\NamespaceMatcher;
use Qualimetrix\Core\Util\PathMatcher;

/**
 * The two per-rule exclusion ledger halves — {@see SuppressionMechanism::RuleNamespaceExclusion}
 * and {@see SuppressionMechanism::RulePathExclusion} — split out of
 * {@see SuppressionCompositionBuilder} as its own subject: attributing a
 * ledger-excluded finding to the pattern that fired, and finding the
 * configured patterns that fired nothing, both read the same per-rule raw
 * configuration and neither has anything to do with the five
 * {@see \Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage}
 * mechanisms the sibling class attributes.
 *
 * Recomputes rather than reads a per-finding attribution for the same reason
 * given on {@see SuppressionCompositionBuilder}: re-asking
 * {@see RuleConfigurationInterface}'s own `is*Excluded()` predicates —
 * namespace before path, the same short-circuit order
 * {@see \Qualimetrix\Analysis\Finding\FindingExclusionLedger::keeps()} uses —
 * against a removal the ledger already decided.
 */
final readonly class RuleExclusionLedgerAttributor
{
    /**
     * @return array{0: list<SuppressedFinding>, 1: list<InertSuppressor>}
     */
    public function attribute(RuleExecutionResult $ruleExecution, RuleConfigurationInterface $ruleConfiguration): array
    {
        $rulesConfig = $ruleConfiguration->all();
        $namespaceHitsByRule = [];
        $pathHitsByRule = [];
        $suppressed = [];

        foreach ($ruleExecution->exclusions->excludedFindings as $finding) {
            $hit = $this->attributeOne($finding, $ruleConfiguration, $rulesConfig);

            if ($hit === null) {
                continue;
            }

            $suppressed[] = $hit->suppressedFinding;
            $this->recordHit($hit, $namespaceHitsByRule, $pathHitsByRule);
        }

        return [$suppressed, $this->inertSuppressors($rulesConfig, $pathHitsByRule, $namespaceHitsByRule)];
    }

    /**
     * @param array<string, mixed> $rulesConfig
     */
    private function attributeOne(Finding $finding, RuleConfigurationInterface $ruleConfiguration, array $rulesConfig): ?LedgerHit
    {
        $ruleName = $finding->ruleName;
        $ruleOptions = $this->ruleOptionsOf($rulesConfig, $ruleName);
        $namespace = $this->namespaceOf($finding);

        if ($namespace !== '' && $this->isNamespaceLedgerExcluded($ruleConfiguration, $finding, $ruleName, $namespace)) {
            $pattern = (new NamespaceMatcher($this->configuredNamespacePatterns($ruleOptions)))->matches($namespace)?->pattern;

            return new LedgerHit(
                new SuppressedFinding($finding, SuppressionMechanism::RuleNamespaceExclusion, $ruleName),
                SuppressionMechanism::RuleNamespaceExclusion,
                $ruleName,
                $pattern,
            );
        }

        if ($finding->location->file !== null && $ruleConfiguration->isPathExcluded($ruleName, $finding->location->file)) {
            $pattern = (new PathMatcher($this->configuredPathPatterns($ruleOptions)))->matches($finding->location->file)?->pattern;

            return new LedgerHit(
                new SuppressedFinding($finding, SuppressionMechanism::RulePathExclusion, $ruleName),
                SuppressionMechanism::RulePathExclusion,
                $ruleName,
                $pattern,
            );
        }

        return null;
    }

    private function isNamespaceLedgerExcluded(
        RuleConfigurationInterface $ruleConfiguration,
        Finding $finding,
        string $ruleName,
        string $namespace,
    ): bool {
        if ($ruleConfiguration->isNamespaceExcluded($ruleName, $namespace)) {
            return true;
        }

        return $finding->symbolPath->getType()->value === 'namespace'
            && $ruleConfiguration->isNamespaceChannelExcluded($ruleName, $finding->channel(), $namespace);
    }

    /**
     * @param array<string, array<string, true>> $namespaceHitsByRule
     * @param array<string, array<string, true>> $pathHitsByRule
     */
    private function recordHit(LedgerHit $hit, array &$namespaceHitsByRule, array &$pathHitsByRule): void
    {
        if ($hit->pattern === null) {
            return;
        }

        if ($hit->mechanism === SuppressionMechanism::RuleNamespaceExclusion) {
            $namespaceHitsByRule[$hit->ruleName][$hit->pattern] = true;
        } else {
            $pathHitsByRule[$hit->ruleName][$hit->pattern] = true;
        }
    }

    /**
     * @param array<string, mixed> $rulesConfig
     * @param array<string, array<string, true>> $pathHitsByRule
     * @param array<string, array<string, true>> $namespaceHitsByRule
     *
     * @return list<InertSuppressor>
     */
    private function inertSuppressors(array $rulesConfig, array $pathHitsByRule, array $namespaceHitsByRule): array
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
     * @param array<string, mixed> $rulesConfig
     *
     * @return array<string, mixed>
     */
    private function ruleOptionsOf(array $rulesConfig, string $ruleName): array
    {
        return \is_array($rulesConfig[$ruleName] ?? null) ? $rulesConfig[$ruleName] : [];
    }

    /**
     * Reads `exclude_paths` off one rule's raw options, accepting both the
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
        return $this->stringList($ruleOptions['excludePaths'] ?? $ruleOptions['exclude_paths'] ?? []);
    }

    /**
     * @param array<string, mixed> $ruleOptions
     *
     * @return list<string>
     */
    private function configuredNamespacePatterns(array $ruleOptions): array
    {
        return $this->stringList($ruleOptions['excludeNamespaces'] ?? $ruleOptions['exclude_namespaces'] ?? []);
    }

    private function namespaceOf(Finding $finding): string
    {
        return $finding->symbolPath->namespace
            ?? $finding->subject->toSymbolPath()->namespace
            ?? '';
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
