<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ThresholdPair;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;

/** The executable integer-band behaviour; each consuming metric declares its own defaults and keys. */
trait IntegerThresholdOptionsTrait
{
    use ThresholdOptionQueries;

    use StandardOverrideValidatorTrait;

    public static function fromResolved(ResolvedRuleOptionValues $config): static
    {
        $defaults = new static();
        $pair = ThresholdPair::fromResolved($config, RuleOptionSurface::bandFor(self::class, 'threshold'), $defaults->integerWarning(), $defaults->integerError());
        return new static($config->boolean('enabled', true), $pair->integerWarning(), $pair->integerError());
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static($this->enabled, $warning !== null ? (int) $warning : $this->integerWarning(), $error !== null ? (int) $error : $this->integerError());
    }

    protected function thresholdPair(): ThresholdPair
    {
        return new ThresholdPair($this->integerWarning(), $this->integerError());
    }

    protected function integerWarning(): int
    {
        return $this->warning;
    }

    protected function integerError(): int
    {
        return $this->error;
    }
}
