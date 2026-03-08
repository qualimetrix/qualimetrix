<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

use AiMessDetector\Metrics\ResettableVisitorInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor for calculating RFC (Response for a Class) metrics.
 *
 * RFC = M + R
 * Where:
 * - M = number of methods in the class
 * - R = number of unique external methods called from the class methods
 *
 * Tracks:
 * - Own methods (M)
 * - External method calls ($this->dependency->method())
 * - Static calls (SomeClass::staticMethod())
 * - Global function calls (array_map(), strlen(), etc.)
 * - Constructor calls (new SomeClass())
 *
 * Ignores:
 * - Internal calls ($this->method())
 * - self::, static::, parent:: calls (internal)
 * - Anonymous classes
 */
final class RfcVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    /**
     * @var array<string, ClassRfcData> Class FQN => RFC data
     */
    private array $classes = [];

    private ?string $currentNamespace = null;

    /**
     * Stack of class contexts to handle nested classes.
     * null = anonymous class (ignored).
     *
     * @var list<string|null>
     */
    private array $classStack = [];

    /**
     * Track method nesting depth (to count external calls).
     * Using a counter instead of boolean to handle closures/anonymous classes inside methods.
     */
    private int $insideMethodDepth = 0;

    public function reset(): void
    {
        $this->classes = [];
        $this->currentNamespace = null;
        $this->classStack = [];
        $this->insideMethodDepth = 0;
    }

    /**
     * @return array<string, ClassRfcData>
     */
    public function getClassesData(): array
    {
        return $this->classes;
    }

    private function getCurrentClass(): ?string
    {
        if ($this->classStack === []) {
            return null;
        }

        return $this->classStack[array_key_last($this->classStack)];
    }

    public function enterNode(Node $node): ?int
    {
        // Track namespace
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';

            return null;
        }

        // Track class-like types (skip anonymous classes)
        if ($this->isClassLikeNode($node)) {
            $this->handleClassLikeNode($node);

            return null;
        }

        // Track method entry
        if ($node instanceof ClassMethod) {
            $this->insideMethodDepth++;

            return null;
        }

        // Track external calls (only inside methods of named classes)
        if ($this->insideMethodDepth > 0 && $this->getCurrentClass() !== null) {
            $this->handleExternalCall($node);
        }

        return null;
    }

    private function handleClassLikeNode(Node $node): void
    {
        $className = $this->extractClassLikeName($node);
        $this->classStack[] = $className;

        // Only create metrics for named classes
        if ($className !== null) {
            $fqn = $this->buildClassFqn($className);
            $this->classes[$fqn] = new ClassRfcData(
                namespace: $this->currentNamespace,
                className: $className,
                line: $node->getStartLine(),
            );

            // Collect own methods (non-abstract only)
            // Class_, Trait_, and Enum_ all extend ClassLike and can have concrete methods
            if ($node instanceof Class_ || $node instanceof Trait_ || $node instanceof Enum_) {
                $this->collectOwnMethods($node, $fqn);
            }
        }
    }

    private function collectOwnMethods(Class_|Trait_|Enum_ $class, string $fqn): void
    {
        foreach ($class->getMethods() as $method) {
            if (!$method->isAbstract()) {
                $this->classes[$fqn]->addOwnMethod($method->name->toString());
            }
        }
    }

    private function handleExternalCall(Node $node): void
    {
        $currentClass = $this->getCurrentClass();
        if ($currentClass === null) {
            return;
        }

        $fqn = $this->buildClassFqn($currentClass);

        match (true) {
            $node instanceof MethodCall => $this->handleMethodCall($node, $fqn),
            $node instanceof StaticCall => $this->handleStaticCall($node, $fqn),
            $node instanceof FuncCall => $this->handleFunctionCall($node, $fqn),
            $node instanceof New_ => $this->handleConstructorCall($node, $fqn),
            default => null,
        };
    }

    private function handleMethodCall(MethodCall $node, string $fqn): void
    {
        $methodName = $node->name instanceof Identifier ? $node->name->toString() : null;
        if ($methodName === null) {
            return;
        }

        // Check if it's internal call ($this->method())
        $isInternalCall = $node->var instanceof Node\Expr\Variable && $node->var->name === 'this';

        if (!$isInternalCall) {
            $this->classes[$fqn]->addExternalMethod($methodName);
        }
    }

    private function handleStaticCall(StaticCall $node, string $fqn): void
    {
        $methodName = $node->name instanceof Identifier ? $node->name->toString() : null;
        if ($methodName === null || !$node->class instanceof Name) {
            return;
        }

        $className = $node->class->toString();

        // Ignore internal calls (self::, static::, parent::)
        if (!\in_array($className, ['self', 'static', 'parent'], true)) {
            $this->classes[$fqn]->addExternalMethod($className . '::' . $methodName);
        }
    }

    private function handleFunctionCall(FuncCall $node, string $fqn): void
    {
        if (!$node->name instanceof Name) {
            return;
        }

        $funcName = $node->name->toString();
        $this->classes[$fqn]->addExternalMethod($funcName);
    }

    private function handleConstructorCall(New_ $node, string $fqn): void
    {
        if (!$node->class instanceof Name) {
            return;
        }

        $className = $node->class->toString();
        $this->classes[$fqn]->addExternalMethod($className . '::__construct');
    }

    public function leaveNode(Node $node): ?int
    {
        // Exit method
        if ($node instanceof ClassMethod) {
            $this->insideMethodDepth--;

            return null;
        }

        // Exit class-like scope
        if ($this->isClassLikeNode($node)) {
            array_pop($this->classStack);

            return null;
        }

        // Exit namespace scope
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null;

            return null;
        }

        return null;
    }

    private function extractClassLikeName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Class_ && $node->name !== null => $node->name->toString(),
            $node instanceof Interface_ && $node->name !== null => $node->name->toString(),
            $node instanceof Trait_ && $node->name !== null => $node->name->toString(),
            $node instanceof Enum_ && $node->name !== null => $node->name->toString(),
            default => null,
        };
    }

    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Class_
            || $node instanceof Interface_
            || $node instanceof Trait_
            || $node instanceof Enum_;
    }

    private function buildClassFqn(string $className): string
    {
        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $className;
        }

        return $className;
    }
}

/**
 * Value object holding RFC data for a single class.
 *
 * Mutable during collection phase, immutable after.
 */
final class ClassRfcData
{
    /**
     * @var list<string> Own methods
     */
    private array $ownMethods = [];

    /**
     * @var array<string, true> Unique external methods (map for deduplication)
     */
    private array $externalMethods = [];

    public function __construct(
        public readonly ?string $namespace = null,
        public readonly string $className = '',
        public readonly int $line = 0,
    ) {}

    public function addOwnMethod(string $name): void
    {
        $this->ownMethods[] = $name;
    }

    public function addExternalMethod(string $name): void
    {
        $this->externalMethods[$name] = true;
    }

    /**
     * RFC = Own methods + External methods.
     */
    public function getRfc(): int
    {
        return \count($this->ownMethods) + \count($this->externalMethods);
    }

    public function getOwnMethodsCount(): int
    {
        return \count($this->ownMethods);
    }

    public function getExternalMethodsCount(): int
    {
        return \count($this->externalMethods);
    }
}
