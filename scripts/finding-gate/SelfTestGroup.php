<?php

declare(strict_types=1);

namespace QmxFindingGate;

use ArrayObject;

/**
 * One subject's share of the self-test.
 *
 * Every group appends to the one list {@see SelfTest::run()} hands out, so the
 * failures read in the order the groups ran, whichever group raised them.
 */
abstract class SelfTestGroup
{
    /** @param ArrayObject<int, string> $failures */
    public function __construct(
        protected readonly string $candidateRoot,
        protected readonly ArrayObject $failures,
    ) {}

    protected static function throws(callable $callback): bool
    {
        try {
            $callback();

            return false;
        } catch (GateError) {
            return true;
        }
    }

    protected function assert(bool $condition, string $description): void
    {
        if (!$condition) {
            $this->failures[] = $description;
        }
    }

    protected function same(mixed $expected, mixed $actual, string $description): void
    {
        if ($expected !== $actual) {
            $this->failures[] = \sprintf(
                '%s (expected %s, got %s)',
                $description,
                json_encode($expected, \JSON_UNESCAPED_SLASHES),
                json_encode($actual, \JSON_UNESCAPED_SLASHES),
            );
        }
    }
}
