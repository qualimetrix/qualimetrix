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
 * Options for namespace-level CBO (Coupling Between Objects) checks.
 *
 * CBO = |Ca ∪ Ce|
 * - Low CBO (<14): weakly coupled
 * - Medium CBO (14-19): acceptable (warning)
 * - High CBO (>=20): tightly coupled (error)
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
final readonly class NamespaceCboOptions implements LevelOptionsInterface, ThresholdAwareOptionsInterface
{
    use StandardOverrideValidatorTrait;

    public function __construct(
        public bool $enabled = true,
        public int $warning = 14,
        public int $error = 20,
        public int $minClassCount = 3,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self();
        }

        $thresholds = ThresholdParser::parse($config, RuleOptionKey::WARNING, RuleOptionKey::ERROR, 14, 20);

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            warning: (int) $thresholds['warning'],
            error: (int) $thresholds['error'],
            minClassCount: (int) ($config['min_class_count'] ?? $config['minClassCount'] ?? 3),
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
            'error' => RuleOptionShape::integer()->orNull(),
            'min-class-count' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
            'warning' => RuleOptionShape::integer()->orNull(),
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        $cbo = (int) $value;

        if ($cbo >= $this->error) {
            return Severity::Error;
        }

        if ($cbo >= $this->warning) {
            return Severity::Warning;
        }

        return null;
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $warning !== null ? (int) $warning : $this->warning,
            error: $error !== null ? (int) $error : $this->error,
            minClassCount: $this->minClassCount,
        );
    }

    public function warningBoundary(): int
    {
        return $this->warning;
    }
}
