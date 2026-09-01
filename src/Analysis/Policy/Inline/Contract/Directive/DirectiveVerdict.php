<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

/**
 * One authored directive and what it did.
 *
 * A contract because Run names it: the pipeline sorts the two halves of an
 * audit into one list and says so in the comparator's signature. Its three
 * component types became contracts on the same rule and not a step earlier —
 * when the command that renders a verdict began naming them in code. A list
 * held in an `array` crosses the boundary in a shape neither PHP nor the
 * manifest checker can see, and it is the naming, not the holding, that makes
 * a public surface.
 */
final readonly class DirectiveVerdict
{
    /**
     * @param ?DirectiveUnmeasurableReason $reason non-null exactly when the effect is
     *                                             {@see DirectiveEffect::Unmeasured}
     * @param ?DirectiveSite $maskedBy the directive that covers this one, filled only alongside
     *                                 {@see DirectiveUnmeasurableReason::Masked}
     * @param bool $boundaryObservable whether an unfulfilled promise here could have been seen at
     *                                 all. False exactly when the addressed rule reported on a
     *                                 subject this directive covers and published no boundary with
     *                                 it: an {@see DirectiveEffect::Inert} verdict then cannot be
     *                                 told from a boundary the measured value had already passed.
     *                                 Vacuously true when the rule reported nothing there, because
     *                                 a promise nothing tested was not broken. Produced by the
     *                                 threshold half only; the suppression half leaves it true,
     *                                 having no boundary to speak of.
     */
    public function __construct(
        public DirectiveSite $site,
        public DirectiveEffect $effect,
        public ?DirectiveUnmeasurableReason $reason = null,
        public ?DirectiveSite $maskedBy = null,
        public bool $boundaryObservable = true,
    ) {}
}
