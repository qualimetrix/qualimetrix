<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Rule;

use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Violation;

interface RuleInterface extends RuleDefinitionInterface
{
    /**
     * Returns unique rule name (slug).
     */
    public function getName(): string;

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
     * Analyzes metrics and generates violations.
     *
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array;

}
