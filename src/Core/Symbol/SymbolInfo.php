<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Symbol;

final readonly class SymbolInfo
{
    public function __construct(
        public SymbolPath $symbolPath,
        public string $file,
        public ?int $line,
    ) {}
}
