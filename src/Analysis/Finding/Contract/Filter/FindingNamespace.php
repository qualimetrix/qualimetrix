<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\SymbolType;

/** The declaration namespace of a finding, without physical-file attribution. */
final readonly class FindingNamespace
{
    public static function declared(Finding $finding): ?string
    {
        if ($finding->symbolPath->getType() === SymbolType::Project) {
            return null;
        }

        return $finding->symbolPath->namespace
            ?? $finding->subject->toSymbolPath()->namespace;
    }
}
