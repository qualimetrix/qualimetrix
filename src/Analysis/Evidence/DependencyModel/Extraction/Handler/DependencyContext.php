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

    /**
     * Ambient state set by {@see \Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor}
     * immediately before a call reaches {@see addDependency()}: true while the
     * dependency being recorded is a declaration fact of an anonymous class
     * nested in the current declaration (its extends/implements/attributes
     * header, or a `use T;` in its body), rather than a fact about the current
     * declaration itself. The visitor is the only party able to tell — it is
     * the one tracking anonymous-class nesting — so it brackets this flag per
     * call rather than per traversal span, via {@see startDescribingNestedAnonymousClass()}
     * and {@see stopDescribingNestedAnonymousClass()}: a usage edge dispatched
     * from inside the same anonymous body (new, static call, type hint) is a
     * different call left outside that bracket, with the flag false. See
     * {@see Dependency::$describesNestedAnonymousClass}.
     */
    private bool $describesNestedAnonymousClass = false;

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
            $this->describesNestedAnonymousClass,
        );
    }

    /**
     * Adds the `extends` edge an interface declares — see
     * {@see Dependency::$interfaceExtends}. An interface cannot be declared
     * inside an anonymous class, so the edge is never one of its facts.
     */
    public function addInterfaceParent(string $resolvedParentInterface, int $line): void
    {
        if ($resolvedParentInterface === $this->currentClass->logical->toString()) {
            return;
        }

        $this->dependencies[] = new Dependency(
            $this->currentClass,
            new LogicalClassPath(\Qualimetrix\Core\Symbol\SymbolPath::fromClassFqn($resolvedParentInterface)),
            DependencyType::Extends,
            new DependencyLocation($this->file, $line),
            interfaceExtends: true,
        );
    }

    /**
     * Called by the visitor immediately before a call it knows will produce a
     * declaration edge of a nested anonymous class. Never read back —
     * {@see addDependency()} consumes the flag directly. Pair with
     * {@see stopDescribingNestedAnonymousClass()} once that call returns.
     */
    public function startDescribingNestedAnonymousClass(): void
    {
        $this->describesNestedAnonymousClass = true;
    }

    /**
     * Ends the span opened by {@see startDescribingNestedAnonymousClass()},
     * or is a no-op if that span was never entered for this call.
     */
    public function stopDescribingNestedAnonymousClass(): void
    {
        $this->describesNestedAnonymousClass = false;
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
