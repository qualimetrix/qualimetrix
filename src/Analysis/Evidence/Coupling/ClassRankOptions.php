<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Finding\Contract\Rule\BandDirection;

use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Configuration options for ClassRank rule.
 *
 * ClassRank uses PageRank algorithm on the dependency graph.
 * Higher rank means the class is more "important" (many dependents).
 *
 * Thresholds:
 * - Warning: 0.02 (class has notably high importance in the graph)
 * - Error: 0.05 (class is a critical hub, high change impact)
 */
final readonly class ClassRankOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use StandardOverrideValidatorTrait;

    public function __construct(
        public bool $enabled = true,
        public float $warning = 0.02,
        public float $error = 0.05,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdParser::parse($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 0.02, 0.05);
        return new self(
            enabled: $config->boolean('enabled', true),
            warning: $thresholds['warning'],
            error: $thresholds['error'],
        );
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'error' => RuleOptionShape::number()->orNull(),
            'threshold' => RuleOptionShape::number()->orNull(),
            'warning' => RuleOptionShape::number()->orNull(),
        ])->band('threshold', 'warning', 'error', BandDirection::Rising);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get severity for a given ClassRank value.
     *
     * Higher ClassRank = more important = higher change impact.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        $rank = (float) $value;

        if ($rank >= $this->error) {
            return Severity::Error;
        }

        if ($rank >= $this->warning) {
            return Severity::Warning;
        }

        return null;
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $warning !== null ? (float) $warning : $this->warning,
            error: $error !== null ? (float) $error : $this->error,
        );
    }

    public function warningBoundary(): float
    {
        return $this->warning;
    }
}
