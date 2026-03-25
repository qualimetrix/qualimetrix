<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Dependency\Handler;

use Qualimetrix\Analysis\Collection\Dependency\DependencyResolver;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;

final class DependencyContext
{
    /** @var list<Dependency> */
    private array $dependencies = [];

    public function __construct(
        private readonly DependencyResolver $resolver,
        private readonly string $file,
        private readonly string $currentClass,
    ) {}

    /**
     * Adds a dependency with an already-resolved target class name.
     * Skips self-references automatically.
     */
    public function addDependency(string $resolvedTargetClass, DependencyType $type, int $line): void
    {
        if ($resolvedTargetClass === $this->currentClass) {
            return;
        }

        $this->dependencies[] = new Dependency(
            SymbolPath::fromClassFqn($this->currentClass),
            SymbolPath::fromClassFqn($resolvedTargetClass),
            $type,
            new Location($this->file, $line),
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

    public function getFile(): string
    {
        return $this->file;
    }

    public function getCurrentClass(): string
    {
        return $this->currentClass;
    }
}
