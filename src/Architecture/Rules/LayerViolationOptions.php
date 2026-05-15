<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Rules;

use InvalidArgumentException;
use Qualimetrix\Core\Rule\RuleOptionKey;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\Severity;

/**
 * Options for {@see LayerViolationRule}.
 *
 * The rule has only two knobs at the MVP stage:
 * - {@see $enabled} — short-circuits analysis when false.
 * - {@see $severity} — the severity of every reported violation.
 *
 * Layer definitions and the allow-list live in {@see \Qualimetrix\Architecture\Domain\ArchitectureConfiguration}
 * (passed through {@see \Qualimetrix\Core\Rule\AnalysisContext::$architecture}), not in this Options DTO,
 * because the data is shared between the rule and future architecture-aware metrics/reporters.
 */
final readonly class LayerViolationOptions implements RuleOptionsInterface
{
    /**
     * @param bool $enabled Whether the rule is enabled.
     * @param Severity $severity Severity assigned to every reported violation.
     */
    public function __construct(
        public bool $enabled = true,
        public Severity $severity = Severity::Warning,
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException When the `severity` value does not match a known enum case.
     */
    public static function fromArray(array $config): self
    {
        $enabled = (bool) ($config[RuleOptionKey::ENABLED] ?? true);
        $severity = self::resolveSeverity($config['severity'] ?? null);

        return new self(enabled: $enabled, severity: $severity);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Returns the configured severity for the rule.
     *
     * The rule has no numeric threshold — every forbidden edge is reported
     * with the same severity. When the rule is disabled, returns null so the
     * caller can treat "disabled" and "value within tolerance" uniformly.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->severity;
    }

    private static function resolveSeverity(mixed $raw): Severity
    {
        if ($raw === null) {
            return Severity::Warning;
        }

        if ($raw instanceof Severity) {
            return $raw;
        }

        if (!\is_string($raw)) {
            throw new InvalidArgumentException(\sprintf(
                'Option "severity" must be a string, got %s.',
                get_debug_type($raw),
            ));
        }

        $normalized = strtolower($raw);
        foreach (Severity::cases() as $case) {
            if ($case->value === $normalized) {
                return $case;
            }
        }

        $allowed = implode(', ', array_map(static fn(Severity $c): string => "'{$c->value}'", Severity::cases()));
        throw new InvalidArgumentException(\sprintf(
            'Option "severity" has unknown value "%s"; expected one of %s.',
            $raw,
            $allowed,
        ));
    }
}
