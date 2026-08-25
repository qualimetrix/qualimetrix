<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;

/**
 * Applies a producer's `exclude_namespaces`, `exclude_namespace_channels` and
 * `exclude_paths` to one finding, and remembers what that cost.
 *
 * Its own subject is the run's exclusion account: which findings were counted
 * out, under whose name, and — when the run asked for it — the findings
 * themselves, so `--show-suppressed` can print what the report does not. That
 * is four pieces of per-run state and the rules for reading them; keeping them
 * beside rule execution made one class answer both "what did the rules find"
 * and "what did configuration remove", and the two change for different
 * reasons.
 *
 * A ledger lives for one {@see RuleExecution::execute()} call: {@see begin()}
 * opens it, {@see stats()} reads it afterwards. It is deliberately mutable and
 * deliberately not shared.
 */
final class FindingExclusionLedger
{
    /** @var array<string, int> */
    private array $namespaceExclusionCounts = [];

    /** @var array<string, int> */
    private array $pathExclusionCounts = [];

    /** @var list<Finding> */
    private array $excludedFindings = [];

    private bool $capturesExcluded = false;

    public function __construct(
        private readonly RuleConfigurationInterface $ruleOptionsRegistry,
    ) {}

    public function begin(): void
    {
        $this->namespaceExclusionCounts = [];
        $this->pathExclusionCounts = [];
        $this->excludedFindings = [];
        $this->capturesExcluded = $this->ruleOptionsRegistry->capturesExcludedFindings();
    }

    /**
     * Whether the finding survives its producer's exclusions — and, when it
     * does not, the tally that says so.
     *
     * `$producerRuleName` is the producer of the **finding**, not of the
     * instance that ran: one class may publish under several producer names,
     * and each carries its own exclusions.
     */
    public function keeps(string $producerRuleName, Finding $finding): bool
    {
        if ($this->isNamespaceExcluded($producerRuleName, $finding)) {
            $this->namespaceExclusionCounts[$producerRuleName] = ($this->namespaceExclusionCounts[$producerRuleName] ?? 0) + 1;
            $this->record($finding);

            return false;
        }

        $file = $finding->location->file;

        if ($file !== null && $this->ruleOptionsRegistry->isPathExcluded($producerRuleName, $file)) {
            $this->pathExclusionCounts[$producerRuleName] = ($this->pathExclusionCounts[$producerRuleName] ?? 0) + 1;
            $this->record($finding);

            return false;
        }

        return true;
    }

    public function stats(): RuleExclusionStats
    {
        return new RuleExclusionStats(
            namespaceExclusionsByRule: $this->namespaceExclusionCounts,
            pathExclusionsByRule: $this->pathExclusionCounts,
            excludedFindings: $this->excludedFindings,
        );
    }

    private function record(Finding $finding): void
    {
        if ($this->capturesExcluded) {
            $this->excludedFindings[] = $finding;
        }
    }

    private function isNamespaceExcluded(string $producerRuleName, Finding $finding): bool
    {
        // Occurrence-style rules attach a file symbol path (namespace null) to
        // their findings; the declaring namespace lives on the subject, so
        // fall back to it the same way NamespaceExclusionFilter does.
        $namespace = $finding->symbolPath->namespace
            ?? $finding->subject->toSymbolPath()->namespace
            ?? null;

        if ($namespace === null || $namespace === '') {
            return false;
        }

        if ($this->ruleOptionsRegistry->isNamespaceExcluded($producerRuleName, $namespace)) {
            return true;
        }

        return $finding->symbolPath->getType()->value === 'namespace'
            && $this->ruleOptionsRegistry->isNamespaceChannelExcluded($producerRuleName, $finding->channel(), $namespace);
    }
}
