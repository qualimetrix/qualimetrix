<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use AiMessDetector\Core\Dependency\CycleInterface;

/**
 * Represents a circular dependency in the dependency graph.
 *
 * A cycle is a path through the dependency graph where a class eventually
 * depends on itself through one or more intermediary dependencies.
 */
final readonly class Cycle implements CycleInterface
{
    /**
     * @param list<string> $classes All classes involved in the cycle
     * @param list<string> $path The actual path forming the cycle (includes start class at both ends)
     */
    public function __construct(
        private array $classes,
        private array $path,
    ) {}

    /**
     * @return list<string>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }

    /**
     * @return list<string>
     */
    public function getPath(): array
    {
        return $this->path;
    }

    public function getSize(): int
    {
        return \count($this->classes);
    }

    public function toString(): string
    {
        return implode(' → ', $this->path);
    }

    public function toShortString(): string
    {
        $short = array_map(
            function (string $class): string {
                $pos = strrpos($class, '\\');

                return $pos !== false ? substr($class, $pos + 1) : $class;
            },
            $this->path,
        );

        return implode(' → ', $short);
    }
}
