<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Pattern\PathMatcher;

/**
 * Assembles {@see SuppressionComposition} from what a run's pipeline already
 * computed — {@see FindingProjectionResult} for the five global stages,
 * {@see RuleExecutionResult}'s exclusion ledger for the two per-rule halves
 * (delegated to {@see RuleExclusionLedgerAttributor}) — without a second,
 * mutating pass over the run.
 *
 * Inline carries the first directive that actually matched each finding;
 * Reporting reads that attribution instead of repeating declaration placement.
 * The remaining global stages still recompute their configured suppressor.
 * Their removed-finding lists do not record *which* configured pattern removed a given
 * finding, because nothing before this class ever needed to say. Recomputing
 * here (calling {@see PathMatcher::matches()}, {@see NamespaceMatcher::matches()}
 * against every configured pattern in turn — not stopping at the first hit,
 * so two overlapping patterns are both credited — and reading `Suppression`'s
 * and Baseline's identity-forming fields directly off `Finding` rather than
 * through their owning capabilities' internal types) is a deliberate, narrow
 * duplication of the decision each mechanism already made, accepted because
 * teaching every filter to carry a "why" alongside "whether" is out of this
 * package's file set. Every predicate consulted here is the same public one
 * the deciding code called; this class never re-derives the yes/no answer,
 * only which of the already-true candidates fired.
 *
 * The two per-rule ledger halves are different: {@see \Qualimetrix\Analysis\Finding\FindingExclusionLedger}
 * *is* in this step's file set, so it now records a {@see \Qualimetrix\Analysis\Finding\Contract\RuleExclusionAttribution}
 * at the moment it excludes a finding, and {@see RuleExclusionLedgerAttributor}
 * reads that value instead of re-asking `RuleConfigurationInterface`'s
 * predicates under a producer name it would otherwise have to reconstruct.
 */
final readonly class SuppressionCompositionBuilder
{
    private RuleExclusionLedgerAttributor $ledgerAttributor;

    public function __construct()
    {
        $this->ledgerAttributor = new RuleExclusionLedgerAttributor();
    }

    public function build(
        FindingProjectionResult $filterResult,
        RuleExecutionResult $ruleExecution,
        RuleConfigurationInterface $ruleConfiguration,
        FindingProjectionOptions $options,
    ): SuppressionComposition {
        $all = $this->stageSuppressedFindings($filterResult, $options);
        $inert = $this->globalInertPatterns($filterResult, $options);

        [$ledgerFindings, $ledgerInert] = $this->ledgerAttributor->attribute($ruleExecution, $ruleConfiguration);

        $selection = array_map(
            static fn(array $entry): SuppressedFinding => new SuppressedFinding(
                $entry['finding'],
                SuppressionMechanism::Selection,
                $entry['suppressor'],
            ),
            $ruleExecution->selection->removed,
        );

        return new SuppressionComposition(
            [...$all, ...$ledgerFindings, ...$selection],
            [...$inert, ...$ledgerInert],
            $ruleExecution->selection->notRun,
        );
    }

    /**
     * @return list<SuppressedFinding>
     */
    private function stageSuppressedFindings(FindingProjectionResult $filterResult, FindingProjectionOptions $options): array
    {
        $all = [];

        foreach (FindingFilterStage::cases() as $stage) {
            $mechanism = SuppressionMechanism::fromStage($stage);

            foreach ($filterResult->removedBy($stage) as $finding) {
                $all[] = new SuppressedFinding($finding, $mechanism, $this->stageSuppressor($mechanism, $finding, $filterResult, $options));
            }
        }

        return $all;
    }

    private function stageSuppressor(
        SuppressionMechanism $mechanism,
        Finding $finding,
        FindingProjectionResult $filterResult,
        FindingProjectionOptions $options,
    ): string {
        return match ($mechanism) {
            SuppressionMechanism::Suppression => self::directiveSuppressor($filterResult, $finding),
            SuppressionMechanism::PathSuppression => $this->pathExclusionSuppressor($finding, $options),
            SuppressionMechanism::NamespaceSuppression => $this->namespaceExclusionSuppressor($finding, $options),
            SuppressionMechanism::Baseline => $this->baselineSuppressor($finding),
            SuppressionMechanism::GitScope => $this->gitScopeSuppressor($options),
            SuppressionMechanism::RuleNamespaceSuppression, SuppressionMechanism::RulePathSuppression => $finding->ruleName,
            SuppressionMechanism::Selection => throw new LogicException('Selection suppressors are recorded by execution.'),
        };
    }

    private static function directiveSuppressor(FindingProjectionResult $filterResult, Finding $finding): string
    {
        $site = $filterResult->annotationSuppression->suppressorOf($finding);

        return $site->file . ':' . $site->line;
    }

    private function pathExclusionSuppressor(Finding $finding, FindingProjectionOptions $options): string
    {
        if ($finding->location->file === null) {
            return '(no file)';
        }

        return (new PathMatcher($options->suppressPaths))->matches($finding->location->file)?->definition->display() ?? '(unresolved pattern)';
    }

    private function namespaceExclusionSuppressor(Finding $finding, FindingProjectionOptions $options): string
    {
        return (new NamespaceMatcher($options->suppressNamespaces))
            ->matches($this->namespaceOf($finding))?->definition->display() ?? '(unresolved pattern)';
    }

    /**
     * `BaselineIdentity` itself is Baseline-internal (no cross-owner consumer
     * is declared for it), so its `describe()` is replicated here from the
     * same public `Finding` fields {@see \Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity::forFinding()}
     * builds from, rather than by constructing that type directly.
     *
     * Must stay byte-for-byte what `describe()` produces: the two occurrence
     * and edge components are what actually distinguishes a baseline entry
     * from its neighbours (ADR 0026), so dropping them here does not shorten
     * the answer, it publishes the wrong entry as the suppressor.
     */
    private function baselineSuppressor(Finding $finding): string
    {
        $description = $finding->subject->toCanonical() . ' ' . $finding->channel()->code;

        if ($finding->occurrenceKey !== null) {
            $description .= ' [' . $finding->occurrenceKey->value . ']';
        }

        if ($finding->dependencyTarget !== null) {
            $description .= ' -> ' . $finding->dependencyTarget->toCanonical()
                . ($finding->dependencyType !== null ? ' (' . $finding->dependencyType->value . ')' : '');
        }

        return $description;
    }

    private function gitScopeSuppressor(FindingProjectionOptions $options): string
    {
        return $options->gitScope === null ? '(unknown ref)' : $options->gitScope->reference;
    }

    /**
     * Every configured pattern is tested against every removed finding
     * independently, rather than crediting only the pattern
     * {@see PathMatcher::matches()} / {@see NamespaceMatcher::matches()}
     * happened to reach first. Two overlapping `suppress_paths` entries can
     * both match the same file; a first-match-only credit would report the
     * second as inert even though it independently excludes findings of its
     * own — the reader would remove a live line believing it dead.
     *
     * @return list<InertSuppressor>
     */
    private function globalInertPatterns(FindingProjectionResult $filterResult, FindingProjectionOptions $options): array
    {
        $pathHits = $this->pathPatternsThatFired(
            $filterResult->removedBy(FindingFilterStage::PathExclusion),
            $options->suppressPaths,
            static fn(Finding $f, \Qualimetrix\Core\Pattern\PathPattern $pattern): bool => $f->location->file !== null
                && $pattern->matches($f->location->file),
        );
        $namespaceHits = $this->namespacePatternsThatFired(
            $filterResult->removedBy(FindingFilterStage::NamespaceExclusion),
            $options->suppressNamespaces,
            fn(Finding $f, \Qualimetrix\Core\Pattern\NamespacePattern $pattern): bool => $pattern->matches($this->namespaceOf($f)),
        );

        return [
            ...$this->inertPatterns(SuppressionMechanism::PathSuppression, $options->suppressPaths, $pathHits),
            ...$this->inertPatterns(SuppressionMechanism::NamespaceSuppression, $options->suppressNamespaces, $namespaceHits),
        ];
    }

    /**
     * @param list<\Qualimetrix\Core\Pattern\PathPattern|\Qualimetrix\Core\Pattern\NamespacePattern> $patterns
     * @param array<string, true> $hits
     *
     * @return list<InertSuppressor>
     */
    private function inertPatterns(SuppressionMechanism $mechanism, array $patterns, array $hits): array
    {
        $inert = [];

        foreach ($patterns as $pattern) {
            if (!isset($hits[$pattern->definition->display()])) {
                $inert[] = new InertSuppressor($mechanism, $pattern->definition->display());
            }
        }

        return $inert;
    }

    /**
     * @param list<Finding> $removed
     * @param list<\Qualimetrix\Core\Pattern\PathPattern> $patterns
     * @param callable(Finding, \Qualimetrix\Core\Pattern\PathPattern): bool $matches
     *
     * @return array<string, true>
     */
    private function pathPatternsThatFired(array $removed, array $patterns, callable $matches): array
    {
        $hits = [];
        foreach ($removed as $finding) {
            foreach ($patterns as $pattern) {
                if ($matches($finding, $pattern)) {
                    $hits[$pattern->definition->display()] = true;
                }
            }
        }

        return $hits;
    }

    /**
     * @param list<Finding> $removed
     * @param list<\Qualimetrix\Core\Pattern\NamespacePattern> $patterns
     * @param callable(Finding, \Qualimetrix\Core\Pattern\NamespacePattern): bool $matches
     *
     * @return array<string, true>
     */
    private function namespacePatternsThatFired(array $removed, array $patterns, callable $matches): array
    {
        $hits = [];
        foreach ($removed as $finding) {
            foreach ($patterns as $pattern) {
                if ($matches($finding, $pattern)) {
                    $hits[$pattern->definition->display()] = true;
                }
            }
        }

        return $hits;
    }

    private function namespaceOf(Finding $finding): string
    {
        return $finding->symbolPath->namespace
            ?? $finding->subject->toSymbolPath()->namespace
            ?? '';
    }
}
