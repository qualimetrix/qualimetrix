<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract;

/**
 * What the analysed project's sources say about one class's parent.
 *
 * Three answers, not two: a class this run cannot place at all is different
 * from one it placed and found to have no parent, and collapsing them is what
 * made an unmeasurable chain and a genuine root report the same depth.
 */
final readonly class ParentLookup
{
    private function __construct(public bool $placed, public ?string $parent) {}

    public static function extending(string $parent): self
    {
        return new self(true, $parent);
    }

    public static function root(): self
    {
        return new self(true, null);
    }

    public static function notPlaced(): self
    {
        return new self(false, null);
    }
}
