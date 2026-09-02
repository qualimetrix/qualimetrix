<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolLevelProjection;

/**
 * Which levels one addressed channel can be silenced at by one directive.
 *
 * Three inputs, and the first that answers wins. An authored `:level` pair says
 * it outright — `@qmx-ignore coupling.cbo:namespace` addresses that level and
 * no other. A symbol directive without one is bound to its declaration by exact
 * subject equality ({@see \Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter}),
 * scope expansion having already happened upstream in collection, so its level
 * is its subject's. A physical (file) directive has no subject and can silence
 * the channel wherever the channel reports, which is what the channel declares.
 *
 * A class of its own rather than a helper on {@see DirectiveUsage}: the
 * accounting asks this question, it does not own it, and keeping the three
 * inputs together is what stops a later reader from remembering only the
 * widest of them — both earlier drafts of the directive audit lost the
 * authored pair exactly that way.
 */
final readonly class DirectiveLevels
{
    /**
     * The levels of a whole authored group, which is what a caller judging one
     * directive has: one authored site expands to a binding per applicable
     * declaration ({@see \Qualimetrix\Analysis\Run\Collection\FileProcessor}),
     * and a class docblock's bindings do not share a level with each other.
     *
     * @param non-empty-list<Suppression> $group
     *
     * @return list<SymbolLevel>
     */
    public static function ofGroup(array $group): array
    {
        $levels = [];

        foreach ($group as $suppression) {
            foreach (self::of($suppression) as $level) {
                $levels[$level->value] = $level;
            }
        }

        return array_values($levels);
    }

    /**
     * @return list<SymbolLevel>
     */
    public static function of(Suppression $suppression): array
    {
        $authored = $suppression->target()->selector()?->level();

        if ($authored !== null) {
            return [$authored];
        }

        if ($suppression->subject !== null) {
            return [SymbolLevelProjection::ofDeclaration($suppression->subject->toSymbolPath()->getType())];
        }

        // A physical directive — `@qmx-ignore-file`, `@qmx-ignore-next-line` —
        // carries no subject by the VO's own invariant, so no level can be
        // derived from it: what stands on the suppressed line is known during
        // collection and is not carried into the directive. Returning nothing
        // says exactly that, and {@see \Qualimetrix\Analysis\Finding\Contract\LevelActivity::ranAtAnyOf()}
        // then answers at producer granularity rather than inventing a level.
        // The cost is measured and recorded in
        // `docs/internal/plans/rule-vocabulary/FOLLOWUPS.md`.
        return [];
    }
}
