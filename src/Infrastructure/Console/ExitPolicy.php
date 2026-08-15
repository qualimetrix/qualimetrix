<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Finding\Contract\Severity;

final readonly class ExitPolicy
{
    public function __construct(public Severity|false|null $failOn = null) {}

    /** @param iterable<mixed> $contributions */
    public static function fromContributions(iterable $contributions): self
    {
        $value = null;
        foreach ($contributions as $candidate) {
            $value = $candidate;
        }

        if ($value === false || $value === 'none') {
            return new self(false);
        }
        if ($value === null) {
            return new self();
        }
        if ($value instanceof Severity) {
            return new self($value);
        }
        if (\is_string($value) && Severity::tryFrom($value) !== null) {
            return new self(Severity::from($value));
        }

        throw new InvalidArgumentException(\sprintf(
            'Invalid value "%s" for "%s". Allowed values: none, %s',
            \is_scalar($value) ? (string) $value : get_debug_type($value),
            ConfigSchema::FAIL_ON,
            implode(', ', array_map(static fn(Severity $severity): string => $severity->value, Severity::cases())),
        ));
    }

}
