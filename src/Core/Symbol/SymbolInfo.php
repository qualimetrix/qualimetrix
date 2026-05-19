<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use Qualimetrix\Core\Path\RelativePath;

final readonly class SymbolInfo
{
    public function __construct(
        public SymbolPath $symbolPath,
        public ?RelativePath $file,
        public ?int $line,
    ) {}
}
