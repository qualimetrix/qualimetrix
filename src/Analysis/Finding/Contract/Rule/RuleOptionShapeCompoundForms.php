<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/**
 * The matching and describing logic {@see RuleOptionShape} needs only for
 * its two COMPOUND kinds — a container (`listOf()`/`mapOf()`, judged element
 * by element) and a union (`either()`, judged by any alternative matching) —
 * split out of the class body so its own method count stays a size the
 * `size.method-count` rule measures as one focused vocabulary rather than
 * one class carrying both the plain-shape API and the compound-shape
 * internals. A trait rather than a second class: these methods read
 * `$this->kind`/`$this->element`/`$this->alternatives` directly, and
 * `RuleOptionShape`'s constructor stays private — there is no public seam a
 * second collaborator class could be handed instead.
 *
 * @mixin RuleOptionShape
 */
trait RuleOptionShapeCompoundForms
{
    /**
     * A list or a map, and every one of its elements.
     *
     * One method for both containers because they differ in one predicate:
     * a list is `array_is_list()`, a map is its negation, and the elements are
     * judged the same way either side of that. Two arms spelling out the same
     * three conditions is the duplication this class measures elsewhere.
     */
    private function matchesContainer(mixed $value): bool
    {
        if (!\is_array($value) || array_is_list($value) !== ($this->kind === self::LIST_OF)) {
            return false;
        }

        $element = $this->element;

        if ($element === null) {
            return true;
        }

        foreach ($value as $item) {
            if (!$element->matches($item)) {
                return false;
            }
        }

        return true;
    }

    private function anyAlternativeMatches(mixed $value): bool
    {
        foreach ($this->alternatives as $alternative) {
            if ($alternative->matches($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The element of a container, named in the plural the container reads in.
     * A container of containers keeps the singular: "a list of a map of
     * numbers" has no plural spelling that stays readable.
     */
    private function describeElements(): string
    {
        $element = $this->element;

        if ($element === null) {
            return 'values';
        }

        return $element->kind instanceof RuleOptionValueForm && !$element->nullable
            ? $element->kind->describeMany()
            : $element->describe();
    }
}
