<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Util\NamespaceMatcher;

/**
 * Suppresses violations whose symbol namespace matches configured exclusion patterns.
 *
 * `architecture.*` rule violations are exempt: `exclude_namespaces` means "I don't
 * want metrics for this code", but an architecture boundary violation is not a
 * metric — silently dropping it would let a noisy-metric exclusion double as an
 * undocumented way to disable layer-policy enforcement. Users who need to suppress
 * a specific architecture finding still have `@qmx-ignore`, baseline, or the
 * architecture configuration's own `exclude:` block.
 */
final readonly class NamespaceExclusionFilter implements ViolationFilterInterface
{
    public function __construct(
        private NamespaceMatcher $namespaceMatcher,
    ) {}

    public function shouldInclude(Violation $violation): bool
    {
        if (RuleCategory::Architecture->matches($violation->ruleName)) {
            return true;
        }

        return !$this->namespaceMatcher->matches($violation->symbolPath->namespace ?? '');
    }
}
