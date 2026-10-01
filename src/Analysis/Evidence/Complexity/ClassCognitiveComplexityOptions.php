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
