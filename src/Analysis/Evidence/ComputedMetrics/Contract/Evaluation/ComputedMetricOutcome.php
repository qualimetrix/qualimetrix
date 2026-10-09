<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation;

use InvalidArgumentException;

/** One subject's result, before its caller binds source and publication policy. */
final readonly class ComputedMetricOutcome
{
    public const string VALUE = 'value';
    public const string NOT_APPLICABLE = 'not-applicable';
    public const string MISSING_KEYS = 'missing-keys';
    public const string NO_VALUE = 'no-value';
    public const string FAILURE = 'failure';

    /** @param list<string> $missingKeys */
    private function __construct(
        public string $kind,
        public int|float|null $value = null,
        public array $missingKeys = [],
        public ?string $reason = null,
    ) {}

    public static function value(int|float $value): self
    {
        if (!is_finite((float) $value)) {
            throw new InvalidArgumentException('A computed measurement must be finite.');
        }

        return new self(self::VALUE, $value);
    }

    public static function notApplicable(): self
    {
        return new self(self::NOT_APPLICABLE);
    }

    /** @param non-empty-list<string> $keys */
    public static function missingKeys(array $keys): self
    {
        if ($keys === []) {
            throw new InvalidArgumentException('MissingKeys requires an absent metric key.');
        }

        return new self(self::MISSING_KEYS, missingKeys: array_values(array_unique($keys)));
    }

    public static function noValue(): self
    {
        return new self(self::NO_VALUE);
    }

    public static function failure(string $reason): self
    {
        return new self(self::FAILURE, reason: $reason);
    }
}
