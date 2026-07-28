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
 * The rule emits four diagnostic channels from one options set:
 * - {@see $enabled} — short-circuits analysis when false.
 * - {@see $severity} — the severity of every reported `architecture.layer-violation`.
 * - {@see $unreachableLayerSeverity} — severity of `architecture.unreachable-layer`
 *   (default {@see Severity::Info}).
 * - {@see $potentialShadowSeverity} — severity of `architecture.potential-shadow`
 *   (default {@see Severity::Info}).
 * - {@see $emptyTemplateSeverity} — severity of `architecture.empty-template`
 *   (default {@see Severity::Warning}).
 *
 * The three sub-diagnostic severities default to their historical hardcoded
 * values so existing configs keep their current behavior. Making them
 * configurable lets a project raise a diagnostic to `error` — e.g. a typo in
 * `patterns:` that silently swallows a layer is otherwise only visible via
 * `--disable-rule`'s absence, not via any severity CI can gate on.
 *
 * Layer definitions and the allow-list live in {@see \Qualimetrix\Architecture\Domain\ArchitectureConfiguration}
 * (resolved per-run by {@see \Qualimetrix\Architecture\Processing\ArchitectureProcessorInterface::getPreparedConfiguration()}),
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
     * @param bool $enabled Whether the rule is enabled.
     * @param Severity $severity Severity assigned to every reported `architecture.layer-violation`.
     * @param Severity $unreachableLayerSeverity Severity assigned to `architecture.unreachable-layer`.
     * @param Severity $potentialShadowSeverity Severity assigned to `architecture.potential-shadow`.
     * @param Severity $emptyTemplateSeverity Severity assigned to `architecture.empty-template`.
     */
    public function __construct(
        public bool $enabled = true,
        public Severity $severity = Severity::Warning,
        public Severity $unreachableLayerSeverity = Severity::Info,
        public Severity $potentialShadowSeverity = Severity::Info,
        public Severity $emptyTemplateSeverity = Severity::Warning,
    ) {}

    /**
     * Config keys accept both the canonical snake_case form (`unreachable_layer_severity`,
     * matching every other multi-word option in `rules:`, e.g. `max_cycle_size` in
     * {@see CircularDependencyOptions}) and a camelCase fallback (CLI overrides via
     * `--rule-opt` bypass the config-file key normalizer).
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException When a severity value does not match a known enum case.
     */
    public static function fromArray(array $config): self
    {
        $enabled = (bool) ($config[RuleOptionKey::ENABLED] ?? true);
        $severity = self::resolveSeverity($config['severity'] ?? null, 'severity', Severity::Warning);
        $unreachableLayerSeverity = self::resolveSeverity(
            $config['unreachable_layer_severity'] ?? $config['unreachableLayerSeverity'] ?? null,
            'unreachable_layer_severity',
            Severity::Info,
        );
        $potentialShadowSeverity = self::resolveSeverity(
            $config['potential_shadow_severity'] ?? $config['potentialShadowSeverity'] ?? null,
            'potential_shadow_severity',
            Severity::Info,
        );
        $emptyTemplateSeverity = self::resolveSeverity(
            $config['empty_template_severity'] ?? $config['emptyTemplateSeverity'] ?? null,
            'empty_template_severity',
            Severity::Warning,
        );

        return new self(
            enabled: $enabled,
            severity: $severity,
            unreachableLayerSeverity: $unreachableLayerSeverity,
            potentialShadowSeverity: $potentialShadowSeverity,
            emptyTemplateSeverity: $emptyTemplateSeverity,
        );
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
     * user's YAML), since this method now backs four independent knobs
     * sharing one Options class.
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
