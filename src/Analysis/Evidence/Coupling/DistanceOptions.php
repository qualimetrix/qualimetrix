<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Throwable;

/**
 * Options for DistanceRule.
 *
 * Distance from main sequence: D = |A + I - 1|
 * Range: [0, 1]
 * - 0: on the main sequence (balanced)
 * - 1: far from the main sequence (problematic)
 *
 * Namespace filtering:
 * - By default, auto-detects project namespaces from composer.json (autoload.psr-4)
 * - Use `includeNamespaces` to override auto-detection with explicit list
 * - Use `suppress_namespaces` (universal per-rule option) to exclude specific namespaces
 * - External dependencies (not matching project namespaces) are always excluded
 */
final readonly class DistanceOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use StandardOverrideValidatorTrait;

    /**
     * @param bool $enabled Enable distance rule
     * @param float $maxDistanceWarning Warning threshold for distance
     * @param float $maxDistanceError Error threshold for distance
     * @param list<NamespacePattern>|null $includeNamespaces Override auto-detected project namespaces (null = auto-detect from composer.json)
     * @param int $minClassCount Minimum number of classes in namespace for analysis (0 = disabled)
     */
    public function __construct(
        public bool $enabled = true,
        public float $maxDistanceWarning = 0.3,
        public float $maxDistanceError = 0.5,
        public ?array $includeNamespaces = null,
        public int $minClassCount = 3,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $includeKey = $config['include_namespaces']
            ?? $config['includeNamespaces']
            ?? null;
        $includeNamespaces = self::includeNamespaces($includeKey);

        $thresholds = ThresholdParser::parse($config, 'max_distance_warning', 'max_distance_error', 0.3, 0.5, legacyKeys: ['warning' => ['maxDistanceWarning'], 'error' => ['maxDistanceError']]);

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            maxDistanceWarning: (float) $thresholds['warning'],
            maxDistanceError: (float) $thresholds['error'],
            includeNamespaces: $includeNamespaces,
            minClassCount: (int) ($config['min_class_count'] ?? $config['minClassCount'] ?? 3),
        );
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'max-distance-error' => RuleOptionShape::number()->orNull(),
            'max-distance-warning' => RuleOptionShape::number()->orNull(),
            'min-class-count' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::number()->orNull(),
        ])->alsoAcceptedAndValidatedByTheClass('include-namespaces');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        $distance = (float) $value;

        if ($distance >= $this->maxDistanceError) {
            return Severity::Error;
        }

        if ($distance >= $this->maxDistanceWarning) {
            return Severity::Warning;
        }

        return null;
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            maxDistanceWarning: $warning !== null ? (float) $warning : $this->maxDistanceWarning,
            maxDistanceError: $error !== null ? (float) $error : $this->maxDistanceError,
            includeNamespaces: $this->includeNamespaces,
            minClassCount: $this->minClassCount,
        );
    }

    public function warningBoundary(): float
    {
        return $this->maxDistanceWarning;
    }

    /** @return list<NamespacePattern>|null */
    private static function includeNamespaces(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof NamespacePattern) {
            return [$value];
        }

        if (!\is_array($value) || !array_is_list($value)) {
            throw self::selectorRefusal('must be a list of explicit selector mappings in YAML or one KIND:VALUE selector on the command line');
        }

        $patterns = [];
        foreach ($value as $index => $selector) {
            if (!\is_array($selector) || \count($selector) !== 1) {
                throw self::selectorRefusal(\sprintf('entry %d must be a one-entry mapping: {exact: value}, {subtree: value}, or {regex: value}; bare strings are not supported', $index));
            }

            $kind = array_key_first($selector);
            $pattern = \is_string($kind) ? $selector[$kind] : null;
            if (!\is_string($kind) || !\is_string($pattern) || $pattern === '') {
                throw self::selectorRefusal(\sprintf('entry %d must name exact, subtree, or regex with a non-empty string value', $index));
            }

            try {
                $patterns[] = new NamespacePattern(SelectorDefinition::fromKindAndValue($kind, $pattern));
            } catch (InvalidArgumentException $e) {
                throw self::selectorRefusal(\sprintf('entry %d is invalid: %s', $index, $e->getMessage()), $e);
            }
        }

        try {
            new NamespaceMatcher($patterns);
        } catch (InvalidArgumentException $e) {
            throw self::selectorRefusal($e->getMessage(), $e);
        }

        return $patterns;
    }

    private static function selectorRefusal(string $problem, ?Throwable $previous = null): ConfigurationRefusal
    {
        return ConfigurationRefusal::atResolvedKey(
            RefusedPosition::open([DistanceRule::NAME], 'include_namespaces'),
            \sprintf('Option "include_namespaces" for rule "%s" %s.', DistanceRule::NAME, $problem),
            'include_namespaces',
            $previous,
        );
    }
}
