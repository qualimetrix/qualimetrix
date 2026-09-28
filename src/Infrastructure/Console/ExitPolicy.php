<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
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
            throw self::refusal($failOn->value);
        }
    }

    public static function fromResolvedValue(?ResolvedValueInterface $value): self
    {
        if ($value === null) {
            return new self();
        }

        $configured = $value->plain();
        if ($configured === false || $configured === 'none') {
            return new self(false);
        }
        if ($configured instanceof Severity && $configured->gatesRun()) {
            return new self($configured);
        }
        if (\is_string($configured) && ($severity = Severity::tryFrom($configured))?->gatesRun() === true) {
            return new self($severity);
        }

        $value->refuse(self::rejection(\is_scalar($configured) ? (string) $configured : get_debug_type($configured)));
    }

    private static function refusal(string $value): ConfigurationRefusal
    {
        return ConfigurationRefusal::aboutResolvedInput(
            self::rejection($value),
            ConfigSchema::FAIL_ON,
        );
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
