<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\AttributeSite;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\TypeShape;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyRecorder;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyResolver;

final class DependencyContext
{
    public function __construct(
        private readonly DependencyResolver $resolver,
        private readonly DependencyRecorder $recorder,
        private readonly bool $nestedNamedClass,
    ) {}

    /**
     * Adds a dependency with an already-resolved target class name.
     * Skips self-references automatically.
     */
    public function addDependency(string $resolvedTargetClass, DependencyType $type, int $line): void
    {
        $this->recorder->addDependency($resolvedTargetClass, $type, $line);
    }

    public function addTypeDependency(
        string $resolvedTargetClass,
        DependencyType $position,
        TypeShape $shape,
        int $line,
    ): void {
        $this->recorder->addTypeDependency($resolvedTargetClass, $position, $shape, $line);
    }

    public function addAttributeDependency(string $resolvedTargetClass, AttributeSite $site, int $line): void
    {
        $this->recorder->addAttributeDependency($resolvedTargetClass, $site, $line);
    }

    public function addClassLikeDependency(string $resolvedTargetClass, DependencyType $type, int $line): void
    {
        $this->recorder->addClassLikeDependency($resolvedTargetClass, $type, $line);
    }

    /**
     * An interface cannot be declared inside an anonymous class, so its parent
     * edge never carries the nested-anonymous declaration fact.
     */
    public function addInterfaceParent(string $resolvedParentInterface, int $line): void
    {
        $this->recorder->addInterfaceParent($resolvedParentInterface, $line);
    }

    public function classHeaderAttributeSite(): AttributeSite
    {
        return $this->nestedNamedClass ? AttributeSite::NestedClass : AttributeSite::ClassHeader;
    }

    /**
     * Called by the visitor immediately before a call it knows will produce a
     * declaration edge of a nested anonymous class. Pair with
     * {@see stopDescribingNestedAnonymousClass()} once that call returns.
     */
    public function startDescribingNestedAnonymousClass(): void
    {
        $this->recorder->startDescribingNestedAnonymousClass();
    }

    /**
     * Ends the span opened by {@see startDescribingNestedAnonymousClass()},
     * or is a no-op if that span was never entered for this call.
     */
    public function stopDescribingNestedAnonymousClass(): void
    {
        $this->recorder->stopDescribingNestedAnonymousClass();
    }

    public function recorder(): DependencyRecorder
    {
        return $this->recorder;
    }

    public function getResolver(): DependencyResolver
    {
        return $this->resolver;
    }

}
