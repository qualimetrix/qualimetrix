<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for {@see LayerViolationRule}.
 *
 * The rule emits five channels from one options set, and only two of them
 * are configured here:
 * - {@see $enabled} — short-circuits analysis when false.
 * - {@see $severity} — the severity of every reported `architecture.layer-violation`.
 *
 * The other four — `architecture.coverage`, `architecture.unreachable-layer`,
 * `architecture.potential-shadow` and `architecture.empty-template` — declare
 * {@see \Qualimetrix\Analysis\Finding\Contract\ChannelAcceptability::ConfigurationError}.
 * They fail the run without consulting `fail_on` and cannot be accepted by
 * the ratchet, so their severity controls nothing but the word printed beside
 * the finding. `unreachable_layer_severity`, `potential_shadow_severity` and
 * `empty_template_severity` are therefore removed rather than kept as knobs
 * that look behavioural and are not; {@see fromArray()} rejects them by name
 * instead of ignoring them, because silently accepting `info` for a channel
 * that gates unconditionally is exactly the lie the removal exists to end.
 * `architecture.coverage` never had such a key: it is governed by the
 * architecture section's own `coverage: ignore|warn|error`, and `ignore`
 * remains the supported way to decline the diagnostic outright.
 *
 * Layer definitions and the allow-list live in {@see \Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration}
 * (resolved per-run by {@see \Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy::getPreparedConfiguration()}),
 * not in this Options DTO, because the data is shared between the rule and
 * future architecture-aware metrics/reporters.
 */
final readonly class LayerViolationOptions implements RuleOptionsInterface
{
    /**
     * Duplicates {@see LayerViolationRule::NAME} as a literal rather than
     * referencing the class constant, so this Options DTO does not gain a
     * dependency edge onto the rule it configures (options → rule would
     * invert the natural rule → options relationship the rest of the
     * codebase follows).
     */
    private const string RULE_NAME = 'architecture.layer-violation';

    /**
     * The removed per-diagnostic severity keys, snake_case => camelCase: both
     * spellings were accepted (a `--rule-opt` override bypasses the config
     * key normalizer), so both must be refused.
     */
    private const array REMOVED_SEVERITY_KEYS = [
        'unreachable_layer_severity' => 'unreachableLayerSeverity',
        'potential_shadow_severity' => 'potentialShadowSeverity',
        'empty_template_severity' => 'emptyTemplateSeverity',
    ];

    /**
     * @param bool $enabled Whether the rule is enabled.
     * @param Severity $severity Severity assigned to every reported `architecture.layer-violation`.
     */
    public function __construct(
        public bool $enabled = true,
        public Severity $severity = Severity::Warning,
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException When a severity value does not match a known enum case,
     *                                  or when the config still sets one of the three removed
     *                                  diagnostic-severity keys.
     */
    public static function fromArray(array $config): self
    {
        self::assertNoRemovedSeverityKeys($config);

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            severity: self::resolveSeverity($config['severity'] ?? null, 'severity', Severity::Warning),
        );
    }

    /**
     * Refuses a config that still carries a removed per-diagnostic severity
     * key, in either the snake_case or the camelCase spelling both used to
     * accept.
     *
     * Refusing rather than ignoring is the point. The four diagnostics these
     * keys used to tune now gate the run unconditionally, so honouring
     * `unreachable_layer_severity: info` is impossible and quietly raising it
     * would leave the user's file saying one thing while the tool does
     * another — the same class of lie as a directive that matches nothing.
     * Naming the key and what replaced it is the only answer that lets a
     * config be fixed mechanically.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    private static function assertNoRemovedSeverityKeys(array $config): void
    {
        foreach (self::REMOVED_SEVERITY_KEYS as $snakeCase => $camelCase) {
            if (!\array_key_exists($snakeCase, $config) && !\array_key_exists($camelCase, $config)) {
                continue;
            }

            throw new InvalidArgumentException(\sprintf(
                'Option "%s" for rule "%s" no longer exists. The channel it configured reports a configuration'
                . ' error, which always fails the run regardless of "fail_on" and can never be accepted by a'
                . ' baseline, so its severity was not a behaviour setting. Remove the key; to decline the'
                . ' coverage diagnostic itself, set "coverage: ignore" in the architecture section.',
                $snakeCase,
                self::RULE_NAME,
            ));
        }
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

    /**
     * Parses a single severity option, falling back to $default when unset.
     *
     * $optionName anchors the error message to the specific option that
     * failed (`rules.architecture.layer-violation.<optionName>` in the
     * user's YAML).
     *
     * @throws InvalidArgumentException When $raw is set but not a recognized severity string.
     */
    private static function resolveSeverity(mixed $raw, string $optionName, Severity $default): Severity
    {
        if ($raw === null) {
            return $default;
        }

        if ($raw instanceof Severity) {
            return $raw;
        }

        if (!\is_string($raw)) {
            throw new InvalidArgumentException(\sprintf(
                'Option "%s" for rule "%s" must be a string, got %s.',
                $optionName,
                self::RULE_NAME,
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
            'Option "%s" for rule "%s" has unknown value "%s"; expected one of %s.',
            $optionName,
            self::RULE_NAME,
            $raw,
            $allowed,
        ));
    }
}
