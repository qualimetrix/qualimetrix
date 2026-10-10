<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Extraction;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\AttributeSite;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\TypeShape;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Records the dependency facts collected for one named declaration. */
final class DependencyRecorder
{
    /** @var list<Dependency> */
    private array $dependencies = [];

    private ?ClassLikeDeclaration $classLikeDeclaration = null;
    private bool $describesNestedAnonymousClass = false;

    public function __construct(
        private readonly RelativePath $file,
        private readonly DeclarationPath $currentClass,
    ) {}

    public function addDependency(string $target, DependencyType $type, int $line): void
    {
        $this->add($target, static fn(DeclarationPath $source, LogicalClassPath $logical, DependencyLocation $location): Dependency => Dependency::ofKind(
            $source,
            $logical,
            $type,
            $location,
        ), $line);
    }

    public function addTypeDependency(string $target, DependencyType $position, TypeShape $shape, int $line): void
    {
        $this->add($target, static fn(DeclarationPath $source, LogicalClassPath $logical, DependencyLocation $location): Dependency => Dependency::ofType(
            $source,
            $logical,
            $position,
            $location,
            $shape,
        ), $line);
    }

    public function addAttributeDependency(string $target, AttributeSite $site, int $line): void
    {
        $nested = $this->describesNestedAnonymousClass;
        $this->add($target, static fn(DeclarationPath $source, LogicalClassPath $logical, DependencyLocation $location): Dependency => Dependency::ofAttribute(
            $source,
            $logical,
            $location,
            $site,
            $nested,
        ), $line);
    }

    public function addClassLikeDependency(string $target, DependencyType $type, int $line): void
    {
        $nested = $this->describesNestedAnonymousClass;
        $this->add($target, static fn(DeclarationPath $source, LogicalClassPath $logical, DependencyLocation $location): Dependency => Dependency::ofClassLike(
            $source,
            $logical,
            $type,
            $location,
            $nested,
            false,
        ), $line);
    }

    public function addInterfaceParent(string $target, int $line): void
    {
        $this->add($target, static fn(DeclarationPath $source, LogicalClassPath $logical, DependencyLocation $location): Dependency => Dependency::ofClassLike(
            $source,
            $logical,
            DependencyType::Extends,
            $location,
            false,
            true,
        ), $line);
    }

    public function recordClassLike(ClassLikeDeclaration $declaration): void
    {
        $this->classLikeDeclaration = $declaration;
    }

    public function startDescribingNestedAnonymousClass(): void
    {
        $this->describesNestedAnonymousClass = true;
    }

    public function stopDescribingNestedAnonymousClass(): void
    {
        $this->describesNestedAnonymousClass = false;
    }

    /** @return list<Dependency> */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    public function classLikeDeclaration(): ?ClassLikeDeclaration
    {
        return $this->classLikeDeclaration;
    }

    public function declaration(): DeclarationPath
    {
        return $this->currentClass;
    }

    /** @param callable(DeclarationPath, LogicalClassPath, DependencyLocation): Dependency $factory */
    private function add(string $target, callable $factory, int $line): void
    {
        $dependency = $factory(
            $this->currentClass,
            new LogicalClassPath(SymbolPath::fromClassFqn($target)),
            new DependencyLocation($this->file, $line),
        );
        if ($target === $this->currentClass->logical->toString() && !$this->isNamedClassSelfExtends($dependency)) {
            return;
        }
        $this->dependencies[] = $dependency;
    }

    private function isNamedClassSelfExtends(Dependency $dependency): bool
    {
        return $dependency->type === DependencyType::Extends
            && !$dependency->describesNestedAnonymousClass && !$dependency->interfaceExtends
            && $this->classLikeDeclaration?->type === \Qualimetrix\Core\Symbol\ClassType::Class_;
    }
}
