<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\Population\PopulationSession;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;

final readonly class AnalysisContext
{
    /**
     * The default describes a complete hand-built test context. Production
     * passes the measured judgement explicitly, including counterfactual rule
     * executions that must answer the same scope question as their baseline.
     *
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides Per-file threshold overrides
     */
    public function __construct(
        public MetricRepositoryInterface $metrics,
        public ?DependencyGraphInterface $dependencyGraph = null,
        public ?NamespaceTree $namespaceTree = null,
        public array $thresholdOverrides = [],
        public ProjectScopeJudgement $projectScope = new ProjectScopeJudgement(),
        private ?PopulationSession $populationSession = null,
    ) {}

    /** @internal Finding execution binds a fresh accounting session. */
    public function withPopulationTrace(PopulationSession $session): self
    {
        return new self($this->metrics, $this->dependencyGraph, $this->namespaceTree, $this->thresholdOverrides, $this->projectScope, $session);
    }

    /** @param iterable<GateInput> $inputs */
    public function admit(string $producer, FindingChannel $channel, SymbolLevel $level, PopulationIdentity $identity, ChannelDeclaration $declaration, iterable $inputs): bool
    {
        $failed = $declaration->populationFailure($channel, $level, $identity, $inputs);
        $this->populationSession?->record($producer, $channel, $level, $identity, $declaration, $failed['gate'] ?? null, $failed['reason'] ?? null);
        return $failed === null;
    }

    /**
     * Finds the most specific threshold override bound to an exact subject.
     *
     * FileProcessor expands class and property controls to their applicable
     * declaration subjects before transport. Rules therefore never reconstruct
     * ownership from presentation file/line metadata.
     */
    public function getThresholdOverride(string $ruleName, MetricSubject $subject): ?ThresholdOverride
    {
        $bestMatch = null;
        $bestSpecificity = 0;
        $bestSpan = \PHP_INT_MAX;

        foreach ($this->thresholdOverrides as $overrides) {
            foreach ($overrides as $override) {
                if (!$override->matches($ruleName) || $override->subject->toCanonical() !== $subject->toCanonical()) {
                    continue;
                }

                $specificity = $override->controlScope->specificity();
                $span = self::span($override);

                if ($bestMatch === null
                    || $specificity > $bestSpecificity
                    || ($specificity === $bestSpecificity && $span < $bestSpan)
                ) {
                    $bestMatch = $override;
                    $bestSpecificity = $specificity;
                    $bestSpan = $span;
                }
            }
        }

        return $bestMatch;
    }

    /** Lines the override covers; one without an end covers everything after it. */
    private static function span(ThresholdOverride $override): int
    {
        return $override->endLine !== null ? $override->endLine - $override->line : \PHP_INT_MAX;
    }
}
