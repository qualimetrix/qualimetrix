<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Core\Symbol\SymbolLevel;

/** The named scope shown when a finding has no physical file. */
final readonly class FindingPlace
{
    public function __construct(public SymbolLevel $level, public string $name) {}
}
