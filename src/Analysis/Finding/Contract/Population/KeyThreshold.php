<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;

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
        $bag = $input->metricBag();
        $sum = 0;
        $firstMissing = null;
        foreach ($this->keys as $key) {
            $value = $bag->get($key);
            if ($value === null) {
                $firstMissing ??= $key;
            }
            $value = $this->readValue($bag, $key, $value);
            if ($value === null) {
                return 'Missing metric "' . $key . '".';
            }
            if (!is_finite((float) $value) || ($this->nonnegative && $value < 0)) {
                throw new LogicException('Invalid measured population count.');
            }
            $sum += $value;
        }
        if (!is_finite((float) $sum)) {
            throw new LogicException('Population sum is not finite.');
        }
        if (self::compare($sum, $this->comparison, $input->effectiveBoundary($this->boundary))) {
            return null;
        }
        return $firstMissing === null ? 'Metric population boundary was not met.' : 'Missing metric "' . $firstMissing . '".';
    }

    private function readValue(MetricBag $bag, string $key, int|float|null $value): int|float|null
    {
        if ($value !== null) {
            return $value;
        }
        return match ($this->missing) {
            'zero' => 0,
            'refuse' => $bag->require($key),
            default => null,
        };
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
