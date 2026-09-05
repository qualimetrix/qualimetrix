<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Core\Util\NamespaceMatcher;
use Qualimetrix\Core\Util\PathMatcher;

/**
 * Assembles {@see SuppressionComposition} from what a run's pipeline already
 * computed — {@see FindingProjectionResult} for the five global stages,
 * {@see RuleExecutionResult}'s exclusion ledger for the two per-rule halves
 * (delegated to {@see RuleExclusionLedgerAttributor}) — without a second,
 * mutating pass over the run.
 *
 * **Why the five global stages recompute rather than read a per-finding
 * attribution the pipeline already carries.** It does not carry one.
 * {@see FindingFilterStageResult::$removed} is a plain list of {@see Finding}
 * — no stage records *which* configured pattern or directive removed a given
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

    private DirectiveSuppressorResolver $directiveSuppressorResolver;

    public function __construct()
    {
        $this->ledgerAttributor = new RuleExclusionLedgerAttributor();
        $this->directiveSuppressorResolver = new DirectiveSuppressorResolver();
    }

    /**
     * @param array<string, list<mixed>> $suppressions Per-file `Suppression` VOs (see class docblock)
     */
    public function build(
        FindingProjectionResult $filterResult,
        RuleExecutionResult $ruleExecution,
        RuleConfigurationInterface $ruleConfiguration,
        FindingProjectionOptions $options,
        array $suppressions,
    ): SuppressionComposition {
        $all = $this->stageSuppressedFindings($filterResult, $options, $suppressions);
        $inert = $this->globalInertPatterns($filterResult, $options);

        [$ledgerFindings, $ledgerInert] = $this->ledgerAttributor->attribute($ruleExecution, $ruleConfiguration);

        return new SuppressionComposition([...$all, ...$ledgerFindings], [...$inert, ...$ledgerInert]);
    }

    /**
     * @param array<string, list<mixed>> $suppressions
     *
     * @return list<SuppressedFinding>
     */
    private function stageSuppressedFindings(FindingProjectionResult $filterResult, FindingProjectionOptions $options, array $suppressions): array
    {
        $all = [];

        foreach (FindingFilterStage::cases() as $stage) {
            $mechanism = SuppressionMechanism::fromStage($stage);

            foreach ($filterResult->removedBy($stage) as $finding) {
                $all[] = new SuppressedFinding($finding, $mechanism, $this->stageSuppressor($mechanism, $finding, $options, $suppressions));
            }
        }

        return $all;
    }

    /**
     * @param array<string, list<mixed>> $suppressions Per-file `Suppression` VOs, read only through public fields (see class docblock)
     */
    private function stageSuppressor(
        SuppressionMechanism $mechanism,
        Finding $finding,
        FindingProjectionOptions $options,
        array $suppressions,
    ): string {
        return match ($mechanism) {
            SuppressionMechanism::Suppression => $this->directiveSuppressorResolver->resolve($finding, $suppressions),
            SuppressionMechanism::PathExclusion => $this->pathExclusionSuppressor($finding, $options),
            SuppressionMechanism::NamespaceExclusion => $this->namespaceExclusionSuppressor($finding, $options),
            SuppressionMechanism::Baseline => $this->baselineSuppressor($finding),
            SuppressionMechanism::GitScope => $this->gitScopeSuppressor($options),
            SuppressionMechanism::RuleNamespaceExclusion, SuppressionMechanism::RulePathExclusion => $finding->ruleName,
        };
    }

    private function pathExclusionSuppressor(Finding $finding, FindingProjectionOptions $options): string
    {
        if ($finding->location->file === null) {
            return '(no file)';
        }

        return (new PathMatcher($options->suppressPaths))->matches($finding->location->file)->pattern ?? '(unresolved pattern)';
    }

    private function namespaceExclusionSuppressor(Finding $finding, FindingProjectionOptions $options): string
    {
        return (new NamespaceMatcher($options->suppressNamespaces))
            ->matches($this->namespaceOf($finding))->pattern ?? '(unresolved pattern)';
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
        $pathHits = $this->patternsThatFired(
            $filterResult->removedBy(FindingFilterStage::PathExclusion),
            $options->suppressPaths,
            static fn(Finding $f, string $pattern): bool => $f->location->file !== null
                && (new PathMatcher([$pattern]))->matches($f->location->file) !== null,
        );
        $namespaceHits = $this->patternsThatFired(
            $filterResult->removedBy(FindingFilterStage::NamespaceExclusion),
            $options->suppressNamespaces,
            fn(Finding $f, string $pattern): bool => (new NamespaceMatcher([$pattern]))->matches($this->namespaceOf($f)) !== null,
        );

        return [
            ...$this->inertPatterns(SuppressionMechanism::PathExclusion, $options->suppressPaths, $pathHits),
            ...$this->inertPatterns(SuppressionMechanism::NamespaceExclusion, $options->suppressNamespaces, $namespaceHits),
        ];
    }

    /**
     * @param list<string> $patterns
     * @param array<string, true> $hits
     *
     * @return list<InertSuppressor>
     */
    private function inertPatterns(SuppressionMechanism $mechanism, array $patterns, array $hits): array
    {
        $inert = [];

        foreach ($patterns as $pattern) {
            if (!isset($hits[$pattern])) {
                $inert[] = new InertSuppressor($mechanism, $pattern);
            }
        }

        return $inert;
    }

    /**
     * @param list<Finding> $removed
     * @param list<string> $patterns
     * @param callable(Finding, string): bool $matches Whether one configured pattern, tested alone, matches the finding
     *
     * @return array<string, true>
     */
    private function patternsThatFired(array $removed, array $patterns, callable $matches): array
    {
        $hits = [];
        foreach ($removed as $finding) {
            foreach ($patterns as $pattern) {
                if ($matches($finding, $pattern)) {
                    $hits[$pattern] = true;
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
