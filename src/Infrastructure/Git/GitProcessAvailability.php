<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

final class GitProcessAvailability
{
    public static function isAvailable(): bool
    {
        $disabled = array_map(static fn(string $name): string => strtolower(trim($name)), explode(',', (string) \ini_get('disable_functions')));

        return \function_exists('proc_open') && !\in_array('proc_open', $disabled, true);
    }
}
