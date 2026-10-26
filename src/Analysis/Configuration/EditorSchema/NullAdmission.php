<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\EditorSchema;

/**
 * Whether `~` may stand where a value is projected: under a key it leaves the
 * key unwritten, as a list item the engine refuses it.
 */
enum NullAdmission
{
    case Admitted;
    case Refused;

    /**
     * The JSON types `~` adds to a value's own.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return match ($this) {
            self::Admitted => ['null'],
            self::Refused => [],
        };
    }
}
