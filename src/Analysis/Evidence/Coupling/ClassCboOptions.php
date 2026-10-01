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
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionWordSet;
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
            'scope' => RuleOptionShape::words(RuleOptionWordSet::of('all', 'application'))->orNull(),
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
