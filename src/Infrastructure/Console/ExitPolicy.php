<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * The resolved `fail_on` threshold.
 *
 * {@see Severity::Info} is rejected here rather than ignored downstream: it
 * is report-only, so a run configured to fail on it would either fail on
 * everything or on nothing, and both readings have been written down as
 * intent by somebody.
 */
final readonly class ExitPolicy
{
    public function __construct(public Severity|false|null $failOn = null)
    {
        if ($failOn instanceof Severity && !$failOn->gatesRun()) {
            throw new InvalidArgumentException(self::rejection($failOn->value));
        }
    }

    /** @param iterable<mixed> $contributions */
    public static function fromContributions(iterable $contributions): self
    {
        $value = null;
        foreach ($contributions as $candidate) {
            $value = $candidate;
        }

        return self::fromValue($value);
    }

    private static function fromValue(mixed $value): self
    {
        if ($value === false || $value === 'none') {
            return new self(false);
        }
        if ($value === null) {
            return new self();
        }
        if ($value instanceof Severity) {
            return new self($value);
        }
        if (\is_string($value) && Severity::tryFrom($value)?->gatesRun() === true) {
            return new self(Severity::from($value));
        }

        throw new InvalidArgumentException(self::rejection(
            \is_scalar($value) ? (string) $value : get_debug_type($value),
        ));
    }

    private static function rejection(string $value): string
    {
        $accepted = array_map(
            static fn(Severity $severity): string => $severity->value,
            array_filter(Severity::cases(), static fn(Severity $severity): bool => $severity->gatesRun()),
        );

        return \sprintf(
            'Invalid value "%s" for "%s". Allowed values: none, %s.'
            . ' Severity "info" is report-only and can no longer be a "%s" threshold:'
            . ' raise the severity of the rule you want to gate on instead.',
            $value,
            ConfigSchema::FAIL_ON,
            implode(', ', $accepted),
            ConfigSchema::FAIL_ON,
        );
    }
}
