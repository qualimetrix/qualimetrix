<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;

final readonly class KeyThreshold implements GatePredicate
{
    /** @param non-empty-list<string> $keys */
    public function __construct(
        public string $source,
        public array $keys,
        public string $comparison,
        public int|float|string $boundary,
        public string $missing = 'exclude',
        public bool $nonnegative = false,
    ) {
        if ($source === '' || $keys === [] || \in_array('', $keys, true) || \count(array_unique($keys)) !== \count($keys)
            || !\in_array($comparison, ['<', '<=', '>', '>='], true)
            || !\in_array($missing, ['exclude', 'zero', 'refuse'], true)
            || (\is_string($boundary) ? $boundary !== $source : !is_finite((float) $boundary))) {
            throw new LogicException('Invalid declared metric threshold.');
        }
    }

    public function evaluate(GateInput $input): ?string
    {
        $input->requireVariant('metrics', $this->source);
        $bag = $input->bag ?? throw new LogicException('Metrics input has no bag.');
        $sum = 0;
        foreach ($this->keys as $key) {
            $value = $bag->get($key);
            if ($value === null) {
                if ($this->missing === 'exclude') {
                    return 'Missing metric "' . $key . '".';
                }
                $value = $this->missing === 'refuse' ? $bag->require($key) : 0;
            }
            if (!is_finite((float) $value) || ($this->nonnegative && $value < 0)) {
                throw new LogicException('Invalid measured population count.');
            }
            $sum += $value;
        }
        if (!is_finite((float) $sum)) {
            throw new LogicException('Population sum is not finite.');
        }
        return self::compare($sum, $this->comparison, $input->effectiveBoundary($this->boundary)) ? null : 'Metric population boundary was not met.';
    }

    public static function compare(int|float $value, string $comparison, int|float $boundary): bool
    {
        return match ($comparison) {
            '<' => $value < $boundary,
            '<=' => $value <= $boundary,
            '>' => $value > $boundary,
            '>=' => $value >= $boundary,
            default => throw new LogicException('Unknown population comparison.'),
        };
    }
}
