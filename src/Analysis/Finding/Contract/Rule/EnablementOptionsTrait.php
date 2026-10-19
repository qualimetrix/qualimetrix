<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\RuleOptionKeySet;

/** The resolved enablement value of an option object with no metric-specific keys. */
trait EnablementOptionsTrait
{
    public static function fromResolved(ResolvedRuleOptionValues $config): static
    {
        return new static(enabled: $config->boolean('enabled', true));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([]);
    }
}
