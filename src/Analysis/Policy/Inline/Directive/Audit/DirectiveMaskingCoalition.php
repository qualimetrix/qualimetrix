<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive\Audit;

use Closure;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;

/**
 * Which directives of one leave-one-out sweep hide one another, answered by
 * difference of outcome rather than by overlap alone.
 *
 * Leave-one-out is blind to mutual masking by construction. A class directive
 * materialises on the class **and on every method in it**
 * (`DeclarationControlBindings`), a method directive materialises on the
 * method, and `AnalysisContext::getThresholdOverride()` picks one by
 * specificity: when they would give the same answer, removing any one changes
 * nothing, and each would be called inert although removing them all changes
 * the run.
 *
 * **The question asked is differential, and that is what keeps the answer
 * about this directive.** Not "does removing the whole coalition change the
 * run" — a live neighbour would move the outcome on its own account and drag
 * every dead annotation beside it into a refusal. What is asked is whether
 * removing *this* directive changes anything **once its maskers are already
 * gone**: the two runs compared are the coalition without this directive and
 * the coalition with it, and everything the neighbours do cancels between
 * them.
 *
 * **The unit is every directive that could hide this one, which is one hop
 * and not a closure.** A directive can only mask what it covers, so a masker
 * shares a subject with this one by definition; a directive two hops away
 * touches subjects this one does not, and the differential comparison
 * cancels it either way. Specificity has four steps, so one subject can carry
 * a class docblock, a property docblock and a property hook's docblock at
 * once — with three, no single removal and no pair moves the outcome while
 * the triple does, which is what makes the unit a set rather than a pair.
 */
final readonly class DirectiveMaskingCoalition
{
    /**
     * `$without` produces every finding the rules give with the named authored
     * directives taken out of the context that owns this sweep. It arrives as
     * a closure because it is the one counterfactual operation this class is
     * not trusted to perform itself: the sweep owns the prepared run, and this
     * class never sees it.
     *
     * @param Closure(list<AuthoredDirectiveGroup>, ?string): list<Finding> $without
     */
    public function __construct(private Closure $without) {}

    /**
     * The directive that makes this one's removal invisible, or null.
     *
     * @param list<AuthoredDirectiveGroup> $measurable
     */
    public function maskedBy(array $measurable, int $index, ?string $restrictToProducer): ?AuthoredDirectiveGroup
    {
        $maskers = self::overlapping($measurable, $index);

        if ($maskers === []) {
            return null;
        }

        $withoutMaskers = ExecutionFingerprint::of(($this->without)($maskers, $restrictToProducer));
        $withoutAll = ExecutionFingerprint::of(
            ($this->without)([...$maskers, $measurable[$index]], $restrictToProducer),
        );

        if ($withoutMaskers->compareTo($withoutAll) === DirectiveEffect::Inert) {
            return null;
        }

        return $this->hiddenBy($measurable[$index], $maskers, $restrictToProducer);
    }

    /**
     * Which of the maskers is doing the hiding, asked one at a time.
     *
     * The neighbour is answered as itself rather than as its site: what the
     * report prints is one step away, and the group is what the caller can ask
     * anything else of.
     *
     * Naming the first neighbour by position would let a report call a
     * directive the masker of another on the same page where it calls that
     * neighbour dead. So each is put back on its own — everything else
     * removed — and the one that still makes this directive's removal
     * invisible is the one named.
     *
     * When no single neighbour does it alone, the hiding is joint and there is
     * no one directive to name; the report then names the first, and that is
     * the only case where the name is positional rather than measured.
     *
     * @param list<AuthoredDirectiveGroup> $maskers
     */
    private function hiddenBy(
        AuthoredDirectiveGroup $group,
        array $maskers,
        ?string $restrictToProducer,
    ): AuthoredDirectiveGroup {
        if (\count($maskers) === 1) {
            return $maskers[0];
        }

        foreach ($maskers as $candidate) {
            $others = array_values(array_filter(
                $maskers,
                static fn(AuthoredDirectiveGroup $masker): bool => $masker->site() !== $candidate->site(),
            ));

            $withOnlyCandidate = ExecutionFingerprint::of(($this->without)($others, $restrictToProducer));
            $andWithoutTheDirective = ExecutionFingerprint::of(
                ($this->without)([...$others, $group], $restrictToProducer),
            );

            if ($withOnlyCandidate->compareTo($andWithoutTheDirective) === DirectiveEffect::Inert) {
                return $candidate;
            }
        }

        return $maskers[0];
    }

    /**
     * Every other directive of the same rule bound to a subject this one also
     * covers, in the order an author reads them.
     *
     * @param list<AuthoredDirectiveGroup> $measurable
     *
     * @return list<AuthoredDirectiveGroup>
     */
    private static function overlapping(array $measurable, int $index): array
    {
        $group = $measurable[$index];
        $maskers = [];

        foreach ($measurable as $position => $candidate) {
            if ($position === $index || !$group->overlaps($candidate)) {
                continue;
            }

            $maskers[] = $candidate;
        }

        return $maskers;
    }
}
