<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Symbol;

use AiMessDetector\Core\Violation\SymbolPath;

final readonly class SymbolInfo
{
    public function __construct(
        public SymbolPath $symbolPath,
        public string $file,
        public ?int $line,
    ) {}
}
