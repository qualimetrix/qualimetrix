<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

/**
 * Levels of code hierarchy at which rules can operate.
 */
enum RuleLevel: string
{
    case Method = 'method';
    case Class_ = 'class';
    case Namespace_ = 'namespace';

    /**
     * Returns human-readable display name.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::Method => 'Method',
            self::Class_ => 'Class',
            self::Namespace_ => 'Namespace',
        };
    }
}
