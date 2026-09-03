<?php

declare(strict_types=1);

namespace Fixtures\NarrowControl;

/**
 * The three refusals that are not the masking one, authored on purpose.
 *
 * A directive carrying no rule filter names no producer to consult; a directive
 * naming a channel nothing owns was already refused on
 * `annotation.unresolved-directive`; a directive naming a rule this fixture's
 * configuration switched off has no producer that reported. Each is a branch of
 * the audit that a tree of live directives never executes.
 *
 * @qmx-ignore * -- addresses-every-channel: no rule filter, so no producer to ask.
 */
final class UnjudgeableDirectives
{
    /**
     * @qmx-threshold narrow-control.no-such-channel warning=1 error=2 -- already-refused: no
     *                producer owns this channel, and the annotation rule says so.
     * @qmx-threshold complexity.cognitive warning=50 error=80 -- producer-disabled: switched off
     *                in this fixture's own qmx.yaml.
     */
    public function unjudgeable(): void {}
}
