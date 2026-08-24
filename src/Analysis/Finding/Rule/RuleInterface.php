<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Rule;

use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
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
     * Returns rule category for grouping.
     */
    public function getCategory(): RuleCategory;

    /**
     * Returns list of metric names this rule requires.
     *
     * @return list<string>
     */
    public function requires(): array;

    /**
     * Analyzes metrics and generates findings.
     *
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array;

}
