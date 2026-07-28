<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

enum RuleCategory: string
{
    case Complexity = 'complexity';
    case Size = 'size';
    case Design = 'design';
    case Maintainability = 'maintainability';
    case Coupling = 'coupling';
    case Architecture = 'architecture';
    case CodeSmell = 'code-smell';
    case Security = 'security';
    case Duplication = 'duplication';

    /**
     * Checks whether a rule's `NAME` slug (e.g. `architecture.layer-violation`)
     * belongs to this category, per the `group.rule-name` format every rule's
     * `NAME` constant follows.
     *
     * Single point of truth for "does this rule name belong to category X" —
     * consumers such as {@see \Qualimetrix\Core\Violation\Filter\NamespaceExclusionFilter}
     * and {@see \Qualimetrix\Core\Violation\Filter\PathExclusionFilter} both defer to
     * this instead of re-deriving the prefix check themselves.
     */
    public function matches(string $ruleName): bool
    {
        return RuleMatcher::matches($this->value, $ruleName);
    }
}
