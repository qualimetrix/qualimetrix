<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Rule;

use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;

interface RuleInterface extends RuleDefinitionInterface
{
    /**
     * Returns unique rule name (slug).
     */
    public function getName(): string;

    /**
     * What every channel this rule declares means for baseline purposes
     * (ADR 0031 / {@see ChannelShape}) — one answer for the whole rule, not
     * per channel.
     *
     * Read by {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass}
     * via a plain static call, the same way it already reads
     * {@see RuleDefinitionInterface::getOptionsClass()} — no instantiation, a
     * rule may take constructor dependencies beyond its Options object.
     * Assembly refuses a rule whose declared shape disagrees with whether its
     * own channels carry a {@see \Qualimetrix\Core\Observation\WorseDirection},
     * and refuses a validator sharing this rule's name that declares a
     * different shape.
     */
    public static function shape(): ChannelShape;

    /**
     * Returns human-readable description.
     */
    public function getDescription(): string;

    /**
     * Analyzes metrics and generates findings.
     *
     * One instance serves the whole process and is asked more than once per
     * run — once for the run itself, and once per authored threshold-override
     * group inside the directive audit — so it must carry nothing from one
     * call to the next: every property readonly, none static. Two calls with
     * the same context must answer the same. A write through an injected
     * collaborator is allowed only when repeating the call leaves the
     * collaborator as one call did: a memo keyed by the context, or a switch
     * set to the value the configuration already decides.
     * `governance/RuleDeclaration/RuleInstanceStatelessnessTest` refuses the
     * first half by class name; the collaborator half is enforced by nothing.
     *
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array;

    /**
     * Which of this rule's producer/level pairs this configuration lets run.
     *
     * The rule is the only honest answer here: a hierarchical rule decides per
     * level inside {@see \Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleInterface::analyzeLevel()},
     * and the computed-metric host decides per producer against its own
     * per-producer options. Anything outside the rule can only re-derive that
     * from configuration, and a re-derivation is a second copy of the
     * semantics that drifts — the defect this answer exists to remove.
     *
     * A pair is present when the producer **declares** that level, and its
     * value says whether the level ran. An absent pair is not a disabled one:
     * "this producer does not report at that level" is a different fact, and
     * the audit must not read it as a disablement.
     *
     * @return array<string, array<string, bool>> producer name => level value => ran
     */
    public function levelActivity(): array;
}
