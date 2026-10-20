<?php

declare(strict_types=1);

namespace Other;

final class Suppressed
{
    /**
     * @qmx-ignore code-smell.eval — intentionally suppressed outside the requested subtree
     */
    public function run(): void
    {
        eval('3;');
    }
}
