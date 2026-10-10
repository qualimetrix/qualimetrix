<?php

namespace Corpus\DirectivePlacement;

final class Placed
{
    /** @qmx-ignore complexity.ccn */
    #[\Deprecated]
    public function beforeAttribute(int $value): int
    {
        if ($value > 40) { return 40; }
        if ($value > 30) { return 30; }
        if ($value > 10) { return 10; }
        if ($value < 0) { return 0; }
        return $value;
    }

    #[\Deprecated]
    /** @qmx-ignore complexity.ccn */
    public function afterAttribute(int $value): int
    {
        if ($value > 50) { return 50; }
        if ($value > 40) { return 40; }
        if ($value > 20) { return 20; }
        if ($value < 1) { return 1; }
        return $value;
    }

    public function apply(array $values): array
    {
        return array_map(
            /** @qmx-threshold complexity.ccn warning=2 error=10 */
            function (int $value): int {
                if ($value > 50) { return 50; }
                if ($value < 2) { return 2; }
                return $value;
            },
            $values,
        );
    }

    // @qmx-threshold complexity.ccn warning=100
    public function outsideDocblock(int $value): int
    {
        if ($value > 60) { return 60; }
        if ($value > 50) { return 50; }
        if ($value > 40) { return 40; }
        if ($value > 30) { return 30; }
        return $value;
    }
}
