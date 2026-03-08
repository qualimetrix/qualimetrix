<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Symbol;

final readonly class MethodInfo
{
    public function __construct(
        public string $fqn,
        public string $namespace,
        public string $class,
        public string $name,
        public string $file,
        public int $line,
    ) {}

    public function getSymbolPath(): SymbolPath
    {
        return SymbolPath::forMethod($this->namespace, $this->class, $this->name);
    }
}
