<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel;

use Qualimetrix\Core\Symbol\SymbolPath;

/** The graph's logical class universe and namespace regions, in encounter order. */
final readonly class LogicalGraphInventory
{
    /**
     * @param array<SymbolPath> $classes
     * @param array<SymbolPath> $namespaces
     */
    public function __construct(private array $classes, private array $namespaces) {}

    /** @return array<SymbolPath> */
    public function classes(): array
    {
        return $this->classes;
    }

    /** @return array<SymbolPath> */
    public function namespaces(): array
    {
        return $this->namespaces;
    }
}
