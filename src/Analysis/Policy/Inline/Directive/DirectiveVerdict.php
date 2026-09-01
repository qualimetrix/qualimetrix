<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

/**
 * One authored directive and what it did.
 *
 * Internal, although Run already holds lists of these: the crossing happens
 * inside an `array`, which is a shape PHP cannot express and the manifest
 * checker therefore cannot see — it counts a consumer only where a type is
 * named in the code itself. Promotion to `Contract/` lands with the first
 * consumer that names the type rather than a docblock, which is the command.
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
