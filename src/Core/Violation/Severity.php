<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation;

enum Severity: string
{
    case Warning = 'warning';
    case Error = 'error';

    public function getExitCode(): int
    {
        return match ($this) {
            self::Warning => 1,
            self::Error => 2,
        };
    }

    /**
     * Returns human-readable display name.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::Warning => 'Warning',
            self::Error => 'Error',
        };
    }
}
