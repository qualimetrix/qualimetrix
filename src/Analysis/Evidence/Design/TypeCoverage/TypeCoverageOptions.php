<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\TypeCoverage;

use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\BandDirection;

use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ThresholdPair;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\InvertedOverrideValidator;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOptionQueries;

/**
 * Options for one type-coverage dimension.
 *
 * One class, three producers: `design.type-coverage.param`,
 * `design.type-coverage.return` and `design.type-coverage.property` measure
 * different declarations and are configured independently, but the shape of
 * the answer — one warning boundary, one error boundary, coverage in percent
 * where lower is worse — is the same for all three. Configuration is keyed by
 * producer rule name, never by Options class
 * ({@see \Qualimetrix\Infrastructure\DependencyInjection\RuleCatalog\RuleOptionsCompilerPass::optionsServiceId()}),
 * so sharing the implementation does not share the configured instance.
 *
 * Default thresholds:
 * - warning: below 80% coverage
 * - error: below 50% coverage
 */
final readonly class TypeCoverageOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use ThresholdOptionQueries;

    public function __construct(
        public bool $enabled = true,
        public float $warning = 80.0,
        public float $error = 50.0,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdPair::fromResolved($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 80.0, 50.0);
        return new self(
            enabled: $config->boolean('enabled', true),
            warning: $thresholds->warning,
            error: $thresholds->error,
        );
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $warning !== null ? (float) $warning : $this->warning,
            error: $error !== null ? (float) $error : $this->error,
        );
    }

    public static function getOverrideValidator(): OverrideValidatorInterface
    {
        return InvertedOverrideValidator::instance();
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'error' => RuleOptionShape::number()->orNull(),
            'threshold' => RuleOptionShape::number()->orNull(),
            'warning' => RuleOptionShape::number()->orNull(),
        ])->band('threshold', 'warning', 'error', BandDirection::Falling);
    }
    protected function thresholdPair(): ThresholdPair
    {
        return new ThresholdPair($this->warning, $this->error, BandDirection::Falling);
    }

}
