<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CircularDependency;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;

/**
 * Which dependency records may form a cycle.
 *
 * The graph keeps one record per use site, so two classes can be joined by
 * several records of different kinds. The filter judges records, not class
 * pairs: a pair stays a cycle edge while any one of its records is admitted.
 * A cycle that runs through an ignored kind therefore survives when the same
 * link is also an ordinary dependency.
 */
final readonly class CycleEdgeFilter
{
    /** @var array<string, true> */
    private array $ignored;

    /** @param list<DependencyType> $ignoredKinds */
    private function __construct(array $ignoredKinds)
    {
        $ignored = [];
        foreach ($ignoredKinds as $kind) {
            $ignored[$kind->value] = true;
        }
        $this->ignored = $ignored;
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** @param list<DependencyType> $kinds */
    public static function ignoring(array $kinds): self
    {
        return new self($kinds);
    }

    public function admits(Dependency $dependency): bool
    {
        return !isset($this->ignored[$dependency->type->value]);
    }
}
