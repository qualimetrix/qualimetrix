<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition;

use InvalidArgumentException;

/** The measured inputs that make one effective formula applicable. */
final readonly class ComputedMetricApplicability
{
    private const string ALWAYS = 'always';
    private const string ANY_PRESENT = 'any-present';
    private const string POSITIVE_SUM = 'positive-sum';

    /**
     * @param self::ALWAYS|self::ANY_PRESENT|self::POSITIVE_SUM $kind
     * @param list<non-empty-string> $keys
     */
    private function __construct(private string $kind, public array $keys) {}

    public static function always(): self
    {
        return new self(self::ALWAYS, []);
    }

    /** @param non-empty-list<non-empty-string> $keys */
    public static function anyPresent(array $keys): self
    {
        return self::over(self::ANY_PRESENT, $keys);
    }

    /** @param non-empty-list<non-empty-string> $keys */
    public static function positiveSum(array $keys): self
    {
        return self::over(self::POSITIVE_SUM, $keys);
    }

    /** @param array<string, int|float|string|bool|null> $values */
    public function appliesTo(array $values): bool
    {
        $present = [];
        foreach ($this->keys as $key) {
            $value = $values[$key] ?? null;
            if ($value !== null) {
                $present[] = $this->measuredOperand($key, $value);
            }
        }

        return match ($this->kind) {
            self::ALWAYS => true,
            self::ANY_PRESENT => $present !== [],
            self::POSITIVE_SUM => array_sum($present) > 0,
        };
    }

    private function measuredOperand(string $key, mixed $value): int|float
    {
        if ((!\is_int($value) && !\is_float($value)) || !is_finite((float) $value)) {
            throw new InvalidArgumentException(\sprintf('Applicability input "%s" must be a finite measured number.', $key));
        }
        if ($this->kind === self::POSITIVE_SUM && $value < 0) {
            throw new InvalidArgumentException(\sprintf('Applicability denominator "%s" must not be negative.', $key));
        }

        return $value;
    }

    /**
     * @param self::ANY_PRESENT|self::POSITIVE_SUM $kind
     * @param non-empty-list<non-empty-string> $keys
     */
    private static function over(string $kind, array $keys): self
    {
        if ($keys === [] || \in_array('', $keys, true) || \count(array_unique($keys)) !== \count($keys)) {
            throw new InvalidArgumentException('Applicability requires distinct nonempty metric keys.');
        }

        return new self($kind, $keys);
    }
}
