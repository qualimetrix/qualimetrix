<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;

/**
 * Evaluates the independent exclusion predicates for {@see DataClassRule}:
 * interfaces, abstract classes, zero-property classes, exceptions,
 * readonly classes, promoted-properties-only classes, classes under
 * minMethods, and explicit data-class markers.
 *
 * Extracted out of {@see DataClassRule::analyze()} so the guard-clause chain
 * is a single loop over independent predicates rather than a sequence of
 * inline branches, which is what multiplied that method's NPath complexity.
 * Reads only pre-computed metrics — no AST traversal (CLAUDE.md §2, §3).
 */
final class DataClassExclusionCheck
{
    public static function isExcluded(MetricBag $metrics, DataClassOptions $options): bool
    {
        foreach (self::predicates($options) as $predicate) {
            if ($predicate($metrics)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<callable(MetricBag): bool>
     */
    private static function predicates(DataClassOptions $options): array
    {
        return [
            // Interfaces are contracts, not data classes — 100% WOC by definition
            static fn(MetricBag $metrics): bool => $metrics->get(MetricName::STRUCTURE_IS_INTERFACE) === 1,
            // Abstract classes are contracts, not data classes
            static fn(MetricBag $metrics): bool => $metrics->get(MetricName::STRUCTURE_IS_ABSTRACT) === 1,
            // Classes with zero properties cannot be data classes by definition
            static fn(MetricBag $metrics): bool => (int) ($metrics->get(MetricName::STRUCTURE_PROPERTY_COUNT) ?? 0) === 0,
            // Exception classes are DTOs by design — they hold error context, not behavior
            static fn(MetricBag $metrics): bool => $options->excludeExceptions
                && $metrics->get(MetricName::STRUCTURE_IS_EXCEPTION) === 1,
            // Skip readonly classes if configured
            static fn(MetricBag $metrics): bool => $options->excludeReadonly
                && $metrics->get(MetricName::STRUCTURE_IS_READONLY) === 1,
            // Skip promoted-properties-only classes if configured
            static fn(MetricBag $metrics): bool => $options->excludePromotedOnly
                && $metrics->get(MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY) === 1,
            // Skip classes with too few methods
            static fn(MetricBag $metrics): bool => (int) ($metrics->get(MetricName::STRUCTURE_METHOD_COUNT) ?? 0) < $options->minMethods,
            // Skip intentional data classes (pure DTOs)
            static fn(MetricBag $metrics): bool => $metrics->get(MetricName::STRUCTURE_IS_DATA_CLASS) === 1,
        ];
    }
}
