<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use LogicException;

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
 * Options for LcomRule.
 *
 * LCOM4 (Lack of Cohesion of Methods) thresholds:
 * - LCOM4 <= 2: cohesive class (no finding)
 * - LCOM4 3-4: warning (class may have multiple responsibilities)
 * - LCOM4 >= 5: error (class clearly does too much, should be split)
 *
 * Industry standard: LCOM4 >= 5 indicates serious cohesion problems.
 */
final readonly class LcomOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use StandardOverrideValidatorTrait;

    /**
     * @param list<string>|null $excludeMethods Method names to exclude from LCOM graph (e.g., lifecycle hooks)
     */
    public function __construct(
        public bool $enabled = true,
        public int $warning = 3,
        public int $error = 5,
        public bool $excludeReadonly = true,
        public int $minMethods = 3,
        public ?array $excludeMethods = null,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdParser::parse($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 3, 5);
        if (!\is_int($thresholds['warning']) || !\is_int($thresholds['error'])) {
            throw new LogicException('An integer band resolved a non-integer value.');
        }
        return new self(
            enabled: $config->boolean('enabled', true),
            warning: $thresholds['warning'],
            error: $thresholds['error'],
            excludeReadonly: $config->boolean('exclude-readonly', true),
            minMethods: $config->integer('min-methods', 3),
            excludeMethods: $config->list('exclude-methods') === null ? null : $config->strings('exclude-methods', []),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get severity for a given LCOM value.
     *
     * Higher LCOM = worse cohesion.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        if ($value >= $this->error) {
            return Severity::Error;
        }

        if ($value >= $this->warning) {
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
            excludeReadonly: $this->excludeReadonly,
            minMethods: $this->minMethods,
            excludeMethods: $this->excludeMethods,
        );
    }

    public function warningBoundary(): int
    {
        return $this->warning;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'error' => RuleOptionShape::integer()->orNull(),
            'exclude-methods' => RuleOptionShape::listOf(RuleOptionShape::text())->orNull(),
            'exclude-readonly' => RuleOptionShape::boolean()->orNull(),
            'min-methods' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
            'warning' => RuleOptionShape::integer()->orNull(),
        ])->band('threshold', 'warning', 'error', BandDirection::Rising);
    }
}
