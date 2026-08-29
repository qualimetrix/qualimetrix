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
 * **Why this recomputes rather than reads a per-finding attribution the
 * pipeline already carries.** It does not carry one. {@see FindingFilterStageResult::$removed}
 * is a plain list of {@see Finding} — no stage records *which* configured
 * pattern or directive removed a given finding, because nothing before this
 * class ever needed to say. Recomputing here (calling {@see PathMatcher::matches()},
 * {@see NamespaceMatcher::matches()} and reading `Suppression`'s and
 * Baseline's identity-forming fields directly off `Finding` rather than
 * through their owning capabilities' internal types) is a deliberate, narrow
 * duplication of the decision each mechanism already made, accepted because
 * the alternative — teaching every filter and the ledger itself to carry a
 * "why" alongside "whether" — is out of this package's file set. Every
 * predicate consulted here is the same public one the deciding code called;
 * this class never re-derives the yes/no answer, only which of the already-true
 * candidates fired.
 */
final readonly class SuppressionCompositionBuilder
{
    private RuleExclusionLedgerAttributor $ledgerAttributor;

    public function __construct()
    {
        $this->ledgerAttributor = new RuleExclusionLedgerAttributor();
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
            SuppressionMechanism::Suppression => $this->directiveSuppressor($finding, $suppressions),
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

        return (new PathMatcher($options->excludePaths))->matches($finding->location->file)->pattern ?? '(unresolved pattern)';
    }

    private function namespaceExclusionSuppressor(Finding $finding, FindingProjectionOptions $options): string
    {
        return (new NamespaceMatcher($options->excludeNamespaces))
            ->matches($this->namespaceOf($finding))->pattern ?? '(unresolved pattern)';
    }

    /**
     * `BaselineIdentity` itself is Baseline-internal (no cross-owner consumer
     * is declared for it), so the identity is described here from the same
     * public `Finding` fields {@see \Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity::forFinding()}
     * builds from, rather than by constructing that type directly.
     */
    private function baselineSuppressor(Finding $finding): string
    {
        return $finding->subject->toCanonical() . ' ' . $finding->code;
    }

    private function gitScopeSuppressor(FindingProjectionOptions $options): string
    {
        return $options->gitScope === null ? '(unknown ref)' : $options->gitScope->reference;
    }

    /**
     * Replicates the placement rules of the directive filter that owns this
     * decision (`Analysis\Policy\Inline`'s suppression filter — internal to
     * that capability, so named here rather than `@see`-linked) using only
     * `Suppression`'s public fields, to name the `file:line` that actually
     * silenced this finding. See the class docblock for why this is a
     * recomputation rather than a read.
     *
     * @param array<string, list<mixed>> $suppressions Per-file `Suppression` VOs, read only through public fields (see class docblock)
     */
    private function directiveSuppressor(Finding $finding, array $suppressions): string
    {
        return $this->symbolDirectiveSuppressor($finding, $suppressions)
            ?? $this->physicalDirectiveSuppressor($finding, $suppressions)
            ?? '(unresolved directive)';
    }

    /**
     * @param array<string, list<mixed>> $suppressions
     */
    private function symbolDirectiveSuppressor(Finding $finding, array $suppressions): ?string
    {
        foreach ($suppressions as $declaredFile => $fileSuppressions) {
            foreach ($fileSuppressions as $suppression) {
                if ($this->isMatchingSymbolDirective($suppression, $finding)) {
                    return $declaredFile . ':' . $suppression->line;
                }
            }
        }

        return null;
    }

    private function isMatchingSymbolDirective(mixed $suppression, Finding $finding): bool
    {
        return $suppression->type->value === 'symbol'
            && $suppression->subject !== null
            && $suppression->subject->toCanonical() === $finding->subject->toCanonical()
            && $suppression->matches($finding->code, $finding->level());
    }

    /**
     * @param array<string, list<mixed>> $suppressions
     */
    private function physicalDirectiveSuppressor(Finding $finding, array $suppressions): ?string
    {
        $file = $finding->location->pathString();

        foreach ($suppressions[$file] ?? [] as $suppression) {
            if ($suppression->type->value === 'symbol' || !$suppression->matches($finding->code, $finding->level())) {
                continue;
            }

            if ($suppression->type->value === 'file' || $finding->location->line === $suppression->line + 1) {
                return $file . ':' . $suppression->line;
            }
        }

        return null;
    }

    /**
     * @return list<InertSuppressor>
     */
    private function globalInertPatterns(FindingProjectionResult $filterResult, FindingProjectionOptions $options): array
    {
        $pathHits = $this->patternsThatFired(
            $filterResult->removedBy(FindingFilterStage::PathExclusion),
            static fn(Finding $f): ?string => $f->location->file === null
                ? null
                : (new PathMatcher($options->excludePaths))->matches($f->location->file)?->pattern,
        );
        $namespaceHits = $this->patternsThatFired(
            $filterResult->removedBy(FindingFilterStage::NamespaceExclusion),
            fn(Finding $f): ?string => (new NamespaceMatcher($options->excludeNamespaces))->matches($this->namespaceOf($f))?->pattern,
        );

        return [
            ...$this->inertPatterns(SuppressionMechanism::PathExclusion, $options->excludePaths, $pathHits),
            ...$this->inertPatterns(SuppressionMechanism::NamespaceExclusion, $options->excludeNamespaces, $namespaceHits),
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
     * @param callable(Finding): ?string $patternOf
     *
     * @return array<string, true>
     */
    private function patternsThatFired(array $removed, callable $patternOf): array
    {
        $hits = [];
        foreach ($removed as $finding) {
            $pattern = $patternOf($finding);
            if ($pattern !== null) {
                $hits[$pattern] = true;
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
