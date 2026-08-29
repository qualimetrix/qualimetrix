<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation;

use ArrayAccess;
use LogicException;

/**
 * The single variable a formula sees: the symbol's metrics, addressed by key.
 *
 * Absence answers rather than warns, so `m['x'] ?? 0` is the idiom a formula
 * uses for an optional metric and a missing key never becomes a PHP warning
 * inside an expression.
 *
 * @implements ArrayAccess<string, int|float|null>
 */
final readonly class MetricLookup implements ArrayAccess
{
    /**
     * @param array<string, int|float|string|bool|null> $values
     */
    public function __construct(private array $values) {}

    public function offsetExists(mixed $offset): bool
    {
        return $this->offsetGet($offset) !== null;
    }

    public function offsetGet(mixed $offset): int|float|null
    {
        if (!\is_string($offset)) {
            return null;
        }

        $value = $this->values[$offset] ?? null;

        return \is_int($value) || \is_float($value) ? $value : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Metrics are read-only inside a formula.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Metrics are read-only inside a formula.');
    }
}
