<?php

declare(strict_types=1);

namespace Fixtures\NarrowControl;

/**
 * A promise made and not kept: the boundary moved and the finding fired anyway.
 *
 * Both `warning` and `error` are spelled out and both sit below the seven
 * parameters the method declares. A short form naming one side leaves the other
 * at its default and the removal then moves the finding itself, which is
 * `Effective` — the verdict this file exists not to produce.
 */
final class OverrunBoundary
{
    /**
     * @qmx-threshold code-smell.long-parameter-list warning=3 error=4 -- overrun: seven parameters
     *                pass the raised boundary too, so the finding stands with a different boundary.
     */
    public function overrun(
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
