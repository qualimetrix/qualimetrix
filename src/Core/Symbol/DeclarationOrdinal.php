<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use InvalidArgumentException;

/**
 * Rank assigned to one declaration among the same-identity declarations that
 * precede it in the same file.
 *
 * The value is assigned, never read out of the source text: a byte offset that
 * reaches an identity makes the identity move whenever text above it changes.
 * The two named entry points are the only two sources a number can have — a
 * rank computed by {@see FileDeclarationIndex} and an integer that arrived on
 * the wire — and they are named apart so their call sites stay countable.
 */
final readonly class DeclarationOrdinal
{
    private function __construct(public int $value)
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Declaration ordinal must not be negative');
        }
    }

    public static function fromRank(int $rank): self
    {
        return new self($rank);
    }

    public static function fromWire(int $value): self
    {
        return new self($value);
    }

    public function isFirst(): bool
    {
        return $this->value === 0;
    }
}
