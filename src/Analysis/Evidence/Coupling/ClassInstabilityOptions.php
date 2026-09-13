<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for class-level instability checks.
 *
 * Instability range: [0, 1]
 * - 0: maximally stable (only incoming dependencies)
 * - 1: maximally unstable (only outgoing dependencies)
 *
 * @qmx-threshold coupling.instability warning=0.81 -- The eighth efferent edge is
 * `RuleOptionShape`, required so an options class can declare the value form of each key it
 * accepts. Ca=2, Ce=8 puts this at exactly 0.800 against an inclusive 0.800 ceiling, so
 * it is reported for reaching the limit rather than passing it. A rule options class is efferent
 * by construction: it names the option vocabulary it accepts and almost nothing names it back. The
 * sibling options classes that carry the same shape with a single afferent edge compute higher
 * still -- Ca=1 with this Ce is 0.889 -- and are not judged at all, because `min_afferent: 2`
 * filters them out; this class is judged only because one extra consumer names it, which makes the
 * ranking the wrong way round and is the metric mis-modelling the shape rather than a defect to
 * refactor. 0.81 silences today's 0.800 and still reports the next efferent edge, which takes Ce
 * to 9 and instability to 0.818.
 */
final readonly class ClassInstabilityOptions implements LevelOptionsInterface, ThresholdAwareOptionsInterface
{
    use StandardOverrideValidatorTrait;

    public function __construct(
        public bool $enabled = true,
        public float $maxWarning = 0.8,
        public float $maxError = 0.95,
        public int $minAfferent = 1,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // If config is empty, use defaults (all enabled)
        if ($config === []) {
            return new self();
        }

        $thresholds = ThresholdParser::parse($config, 'max_warning', 'max_error', 0.8, 0.95, legacyKeys: ['warning' => ['maxWarning'], 'error' => ['maxError']]);

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            maxWarning: (float) $thresholds['warning'],
            maxError: (float) $thresholds['error'],
            minAfferent: (int) ($config['min_afferent'] ?? $config['minAfferent'] ?? 1),
        );
    }

    /**
     * Keep `threshold` here even though no constructor parameter names it:
     * `ThresholdParser::parse()` reads that key through its default
     * `$thresholdKey`.
     */
    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'max-error' => RuleOptionShape::number()->orNull(),
            'max-warning' => RuleOptionShape::number()->orNull(),
            'min-afferent' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::number()->orNull(),
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        $instability = (float) $value;

        if ($instability >= $this->maxError) {
            return Severity::Error;
        }

        if ($instability >= $this->maxWarning) {
            return Severity::Warning;
        }

        return null;
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            maxWarning: $warning !== null ? (float) $warning : $this->maxWarning,
            maxError: $error !== null ? (float) $error : $this->maxError,
            minAfferent: $this->minAfferent,
        );
    }

    public function warningBoundary(): float
    {
        return $this->maxWarning;
    }
}
