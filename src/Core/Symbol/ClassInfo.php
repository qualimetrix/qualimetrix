<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use Qualimetrix\Core\Path\RelativePath;

final readonly class ClassInfo
{
    public function __construct(
        public string $fqn,
        public string $namespace,
        public string $name,
        public RelativePath $file,
        public int $line,
        public ClassType $type,
    ) {}

    public function getSymbolPath(): SymbolPath
    {
        return SymbolPath::forClass($this->namespace, $this->name);
    }
}
