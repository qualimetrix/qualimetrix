<?php

declare(strict_types=1);

namespace AggregationExport\Leaf;

final class Example
{
    public function evaluate(bool $condition): int
    {
        if ($condition) {
            return 2;
        }

        return 0;
    }
}

if (\PHP_VERSION_ID < 0) {
    final class Example
    {
        public function conditional(): void {}

        public function second(): void {}
    }
}
