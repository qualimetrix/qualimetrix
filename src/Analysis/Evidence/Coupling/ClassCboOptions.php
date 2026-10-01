<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

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
 * Options for class-level CBO (Coupling Between Objects) checks.
 *
 * CBO = |Ca ∪ Ce|
 * - Low CBO (<14): weakly coupled, easy to test
 * - Medium CBO (14-19): acceptable (warning)
 * - High CBO (>=20): tightly coupled, hard to isolate (error)
 *
 * The `scope` option controls which metric is checked:
 * - 'all' (default): uses CBO (original Chidamber & Kemerer, includes all dependencies)
 * - 'application': uses CBO_APP (excludes dependencies on configured framework namespaces)
 *
 * @qmx-threshold coupling.instability warning=0.84 -- The docblock this annotation replaced
 * already predicted this exact move: "0.81 silences today's 0.800 and still reports the next
 * efferent edge, which takes Ce to 9 and instability to 0.818." Two edges arrived instead of one --
 * `ConfigurationRefusal`/`RefusedPosition` -- because a review round decided the silent scope
 * fallback in `parseScope()` was the same silent-acceptance defect the project's closed word sets
 * exist to remove, and refusing it needed the same refusal framing
 * `LayerViolationOptions`/`UnassignedClassOptions` already use for their own `resolveSeverity()`/
 * `resolveMode()`. Ca=2, Ce=10 puts this at 0.833. The reasoning that made 0.800 and 0.81
 * mis-modelling rather than a defect is unchanged by which edge pushed the ratio: a rule options
 * class is efferent by construction, and the sibling classes carrying this shape with a single
 * afferent edge are not judged at all only because `min_afferent: 2` filters them out. 0.84
 * silences today's 0.833 and still reports the next efferent edge.
 */
final readonly class ClassCboOptions implements LevelOptionsInterface, ThresholdAwareOptionsInterface
{
    use StandardOverrideValidatorTrait;

    public function __construct(
        public bool $enabled = true,
        public int $warning = 14,
        public int $error = 20,
        public string $scope = 'all',
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdParser::parse($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 14, 20);
        if (!\is_int($thresholds['warning']) || !\is_int($thresholds['error'])) {
            throw new LogicException('An integer band resolved a non-integer value.');
        }
        return new self(
            enabled: $config->boolean('enabled', true),
            warning: $thresholds['warning'],
            error: $thresholds['error'],
            scope: $config->text('scope', 'all'),
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
            'scope' => RuleOptionShape::oneOf('all', 'application')->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
            'warning' => RuleOptionShape::integer()->orNull(),
        ])->band('threshold', 'warning', 'error', BandDirection::Rising);
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
            scope: $this->scope,
        );
    }

    public function warningBoundary(): int
    {
        return $this->warning;
    }
}
