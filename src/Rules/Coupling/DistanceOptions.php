<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Rules\Support\ThresholdParser;

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
 * - Use `exclude_namespaces` (universal per-rule option) to exclude specific namespaces
 * - External dependencies (not matching project namespaces) are always excluded
 */
final readonly class DistanceOptions implements RuleOptionsInterface
{
    /**
     * @param bool $enabled Enable distance rule
     * @param float $maxDistanceWarning Warning threshold for distance
     * @param float $maxDistanceError Error threshold for distance
     * @param list<string>|null $includeNamespaces Override auto-detected project namespaces (null = auto-detect from composer.json)
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
        $includeNamespaces = null;
        // Support both old (projectNamespaces) and new (includeNamespaces) config keys
        $includeKey = $config['include_namespaces']
            ?? $config['includeNamespaces']
            ?? $config['project_namespaces']
            ?? $config['projectNamespaces']
            ?? null;

        if (\is_string($includeKey)) {
            $includeNamespaces = [$includeKey];
        } elseif (\is_array($includeKey)) {
            $includeNamespaces = array_values($includeKey);
        }

        $thresholds = ThresholdParser::parse($config, 'max_distance_warning', 'max_distance_error', 0.3, 0.5, legacyWarningKeys: ['maxDistanceWarning'], legacyErrorKeys: ['maxDistanceError']);

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            maxDistanceWarning: (float) $thresholds['warning'],
            maxDistanceError: (float) $thresholds['error'],
            includeNamespaces: $includeNamespaces,
            minClassCount: (int) ($config['min_class_count'] ?? $config['minClassCount'] ?? 3),
        );
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
}
