<?php

declare(strict_types=1);

namespace Fixtures\NarrowControl;

/**
 * Two directives of one rule covering one subject, each hiding the other.
 *
 * The class docblock materialises on every callable in the class, so the method
 * below is covered twice by the same raised boundary. Removing either alone
 * changes nothing, which leave-one-out reads as inert; removing the coalition
 * reports the seven parameters again, which is what makes both `Masked` rather
 * than dead. This is the branch the equivalence control exists for, and no
 * verdict of its own would keep it in the population.
 *
 * @qmx-threshold code-smell.long-parameter-list warning=9 error=12 -- masked by the method's own
 */
final class MaskingPair
{
    /**
     * @qmx-threshold code-smell.long-parameter-list warning=9 error=12 -- masked by the class's own
     */
    public function configure(
        string $one,
        string $two,
        string $three,
        string $four,
        string $five,
        string $six,
        string $seven,
    ): string {
        return $one . $two . $three . $four . $five . $six . $seven;
    }
}
