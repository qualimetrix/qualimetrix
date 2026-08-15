<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

interface RuleDefinitionInterface
{
    /**
     * @return class-string<RuleOptionsInterface>
     */
    public static function getOptionsClass(): string;
}
