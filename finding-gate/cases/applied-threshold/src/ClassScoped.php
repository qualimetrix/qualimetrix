<?php

namespace Corpus\AppliedThreshold;

/**
 * The override written on a class and applied to a declaration inside it --
 * a second binding path (`DeclarationControlBindings::classBindings`), and the
 * one a mutation can cut while every annotation of this case stays in place.
 * Cutting it takes this method's finding away, so the loss shows in the claim
 * rather than in a surface diff.
 *
 * @qmx-threshold complexity.cognitive warning=1
 */
class ClassScoped
{
    public function walk(array $items): int
    {
        $total = 0;

        foreach ($items as $item) {
            if ($item > 0) {
                $total += $item;
            }
        }

        return $total;
    }
}
