<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/**
 * Levels of code hierarchy at which rules can operate.
 */
enum RuleLevel: string
{
    case Callable = 'callable';
    case Class_ = 'class';
    case Namespace_ = 'namespace';

    /**
     * Returns human-readable display name.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::Callable => 'Callable',
            self::Class_ => 'Class',
            self::Namespace_ => 'Namespace',
        };
    }
}
