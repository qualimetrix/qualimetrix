<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use LogicException;

use Qualimetrix\Analysis\Finding\Contract\Rule\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for class-level cognitive complexity checks.
 *
 * Checks maximum cognitive complexity among class methods.
 */
final readonly class ClassCognitiveComplexityOptions implements LevelOptionsInterface, ThresholdAwareOptionsInterface
{
    use StandardOverrideValidatorTrait;

    public function __construct(
        public bool $enabled = true,
        public int $maxWarning = 30,
        public int $maxError = 50,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdParser::parse($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 30, 50);
        if (!\is_int($thresholds['warning']) || !\is_int($thresholds['error'])) {
            throw new LogicException('An integer band resolved a non-integer value.');
        }
        return new self(
            enabled: $config->boolean('enabled', true),
            maxWarning: $thresholds['warning'],
            maxError: $thresholds['error'],
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        if ($value >= $this->maxError) {
            return Severity::Error;
        }

        if ($value >= $this->maxWarning) {
            return Severity::Warning;
        }

        return null;
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            maxWarning: $warning !== null ? (int) $warning : $this->maxWarning,
            maxError: $error !== null ? (int) $error : $this->maxError,
        );
    }

    public function warningBoundary(): int
    {
        return $this->maxWarning;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'max-error' => RuleOptionShape::integer()->orNull(),
            'max-warning' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
        ])->band('threshold', 'max-warning', 'max-error', BandDirection::Rising);
    }
}
