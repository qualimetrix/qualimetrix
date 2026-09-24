<?php

declare(strict_types=1);

namespace Fixtures\AncestryBorderSample\Library;

use Stringable;

final class Explicit implements Stringable
{
    public function __toString(): string
    {
        return 'explicit';
    }
}
