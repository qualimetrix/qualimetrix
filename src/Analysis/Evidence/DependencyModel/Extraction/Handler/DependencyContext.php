<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyLocation;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyResolver;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;

final class DependencyContext
{
    /** @var list<Dependency> */
    private array $dependencies = [];

    public function __construct(
        private readonly DependencyResolver $resolver,
        private readonly RelativePath $file,
        private readonly DeclarationPath $currentClass,
    ) {}

    /**
     * Adds a dependency with an already-resolved target class name.
     * Skips self-references automatically.
     */
    public function addDependency(string $resolvedTargetClass, DependencyType $type, int $line): void
    {
        if ($resolvedTargetClass === $this->currentClass->logical->toString()) {
            return;
        }

        $this->dependencies[] = new Dependency(
            $this->currentClass,
            new LogicalClassPath(\Qualimetrix\Core\Symbol\SymbolPath::fromClassFqn($resolvedTargetClass)),
            $type,
            new DependencyLocation($this->file, $line),
        );
    }

    /**
     * @return list<Dependency>
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function getResolver(): DependencyResolver
    {
        return $this->resolver;
    }

    public function getFile(): RelativePath
    {
        return $this->file;
    }

    public function getCurrentClass(): string
    {
        return $this->currentClass->logical->toString();
    }
}
