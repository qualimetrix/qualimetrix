<?php

declare(strict_types=1);

namespace AggregationExport\Leaf;

final class Example
{
    public function evaluate(bool $condition): int
    {
        return $condition ? 1 : 0;
    }
}

interface Contract
{
    public function promised(): void;
}

trait Reusable
{
    public function reused(): void {}
}

enum Status
{
    case Ready;
}

final class PropertyOnly
{
    public int $value = 0;
}

final class ZeroMethod {}
