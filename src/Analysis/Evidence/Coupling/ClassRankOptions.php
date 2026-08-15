<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ShorthandOptionKeysInterface;
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
final readonly class ClassRankOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface, ShorthandOptionKeysInterface
{
    use StandardOverrideValidatorTrait;

    public function __construct(
        public bool $enabled = true,
        public float $warning = 0.02,
        public float $error = 0.05,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self(enabled: false);
        }

        $thresholds = ThresholdParser::parse($config, RuleOptionKey::WARNING, RuleOptionKey::ERROR, 0.02, 0.05);

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            warning: (float) $thresholds['warning'],
            error: (float) $thresholds['error'],
        );
    }

    /**
     * @return list<string>
     */
    public static function getShorthandOptionKeys(): array
    {
        return ['threshold'];
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
}
