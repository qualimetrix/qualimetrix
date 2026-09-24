<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Delegates to a real graph and records the deepest call stack any per-class
 * dependency lookup was made from.
 */
final class DepthRecordingGraph implements DependencyGraphInterface
{
    public int $deepestStack = 0;

    public function __construct(private readonly DependencyGraphInterface $inner) {}

    public function getClassDependencies(SymbolPath $class): array
    {
        $this->deepestStack = max($this->deepestStack, \count(debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS)));

        return $this->inner->getClassDependencies($class);
    }

    public function getClassDependents(SymbolPath $class): array
    {
        return $this->inner->getClassDependents($class);
    }

    public function getClassCe(SymbolPath $class): int
    {
        return $this->inner->getClassCe($class);
    }

    public function getClassCa(SymbolPath $class): int
    {
        return $this->inner->getClassCa($class);
    }

    public function getNamespaceCe(SymbolPath $namespace): int
    {
        return $this->inner->getNamespaceCe($namespace);
    }

    public function getNamespaceCa(SymbolPath $namespace): int
    {
        return $this->inner->getNamespaceCa($namespace);
    }

    public function getNamespaceOwnCe(SymbolPath $namespace): int
    {
        return $this->inner->getNamespaceOwnCe($namespace);
    }

    public function getNamespaceOwnCa(SymbolPath $namespace): int
    {
        return $this->inner->getNamespaceOwnCa($namespace);
    }

    public function getAllClasses(): array
    {
        return $this->inner->getAllClasses();
    }

    public function getAllNamespaces(): array
    {
        return $this->inner->getAllNamespaces();
    }

    public function getAllDependencies(): array
    {
        return $this->inner->getAllDependencies();
    }

    public function getDeclarationDependencies(): array
    {
        return $this->inner->getDeclarationDependencies();
    }
}
