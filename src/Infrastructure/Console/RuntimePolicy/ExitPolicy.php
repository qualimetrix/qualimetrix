<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RuntimePolicy;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * The resolved `fail_on` threshold.
 *
 * {@see Severity::Info} is rejected rather than ignored downstream: it is
 * report-only, so a run configured to fail on it would either fail on
 * everything or on nothing, and both readings have been written down as
 * intent by somebody. The configuration engine refuses it against the words
 * {@see ExitPolicySection} declares, with {@see self::INFO_ADVICE}.
 */
final readonly class ExitPolicy
{
    public const string CONFIGURATION_KEY = 'fail_on';

    public const string NEVER = 'none';

    public const string INFO_ADVICE = 'Severity "info" is report-only and can no longer be a "fail_on" threshold:'
        . ' raise the severity of the rule you want to gate on instead.';

    public function __construct(public Severity|false|null $failOn = null)
    {
        if ($failOn instanceof Severity && !$failOn->gatesRun()) {
            throw ConfigurationRefusal::aboutResolvedInput(
                \sprintf(
                    'Invalid value "%s" for "%s". Allowed values: %s. %s',
                    $failOn->value,
                    self::CONFIGURATION_KEY,
                    implode(', ', self::acceptedWords()),
                    self::INFO_ADVICE,
                ),
                self::CONFIGURATION_KEY,
            );
        }
    }

    /** @return non-empty-list<string> */
    public static function acceptedWords(): array
    {
        $severities = array_filter(Severity::cases(), static fn(Severity $severity): bool => $severity->gatesRun());

        return [self::NEVER, ...array_values(array_map(static fn(Severity $severity): string => $severity->value, $severities))];
    }

    public static function fromResolvedValue(?ResolvedValueInterface $value): self
    {
        if ($value === null) {
            return new self();
        }

        $configured = $value->plain();
        if ($configured === self::NEVER) {
            return new self(false);
        }

        $severity = \is_string($configured) ? Severity::tryFrom($configured) : null;
        if ($severity?->gatesRun() !== true) {
            throw new LogicException('The configuration engine admits only a declared word as fail_on.');
        }

        return new self($severity);
    }
}
