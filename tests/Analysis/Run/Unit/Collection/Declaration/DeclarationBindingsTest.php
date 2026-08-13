<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Collection\Declaration;

use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Run\Contract\Collection\Declaration\DeclarationBindings;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Suppression\ControlScope;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(DeclarationBindings::class)]
final class DeclarationBindingsTest extends TestCase
{
    #[Test]
    public function itBindsNamedAndAnonymousCallablesToTheirDeclarationsAndLexicalOwners(): void
    {
        $ast = $this->parse(<<<'PHP'
            <?php
            class Named
            {
                public int $value {
                    get => 1;
                    set (int $value) {}
                }

                public function method(int $parameter): void
                {
                    $closure = function (int $nested): void {};
                    $arrow = fn (int $arrowParameter): int => $arrowParameter;
                }
            }

            $anonymous = new class { public function ignored(): void {} };
            function globalFunction(int $argument): void {}
            PHP);

        $nodes = new NodeFinder();
        $namedClass = $nodes->findFirst($ast, static fn(Node $node): bool => $node instanceof Node\Stmt\Class_ && $node->name?->toString() === 'Named');
        $classes = $nodes->findInstanceOf($ast, Node\Stmt\Class_::class);
        $anonymousClass = $classes[1] ?? null;
        $method = $nodes->findFirstInstanceOf($ast, Node\Stmt\ClassMethod::class);
        $function = $nodes->findFirstInstanceOf($ast, Node\Stmt\Function_::class);
        $closure = $nodes->findFirstInstanceOf($ast, Node\Expr\Closure::class);
        $arrow = $nodes->findFirstInstanceOf($ast, Node\Expr\ArrowFunction::class);
        $property = $nodes->findFirstInstanceOf($ast, Node\Stmt\Property::class);
        $hooks = $nodes->findInstanceOf($ast, Node\PropertyHook::class);

        self::assertInstanceOf(Node\Stmt\Class_::class, $namedClass);
        self::assertInstanceOf(Node\Stmt\Class_::class, $anonymousClass);
        self::assertCount(2, $classes);
        self::assertNull($anonymousClass->name);
        self::assertInstanceOf(Node\Stmt\ClassMethod::class, $method);
        self::assertInstanceOf(Node\Stmt\Function_::class, $function);
        self::assertInstanceOf(Node\Expr\Closure::class, $closure);
        self::assertInstanceOf(Node\Expr\ArrowFunction::class, $arrow);
        self::assertInstanceOf(Node\Stmt\Property::class, $property);
        self::assertCount(2, $hooks);

        $file = RelativePath::fromString('src/Example.php');
        $class = new DeclarationPath(SymbolPath::forClass('App', 'Named'), $file, $namedClass->getStartFilePos());
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Named'));
        $metrics = [
            $this->callable($method, SymbolPath::forMethod('App', 'Named', 'method'), CallableKind::Method, null, $class, $owner),
            $this->callable($function, SymbolPath::forGlobalFunction('App', 'globalFunction'), CallableKind::Function),
            $this->callable($closure, SymbolPath::forGlobalFunction('App', '{closure#1}'), CallableKind::AnonymousCallable, 'closure', $class),
            $this->callable($arrow, SymbolPath::forGlobalFunction('App', '{closure#2}'), CallableKind::AnonymousCallable, 'arrow', $class),
            $this->callable($hooks[0], SymbolPath::forMethod('App', 'Named', 'value::get'), CallableKind::PropertyHook, null, $class, $owner),
            $this->callable($hooks[1], SymbolPath::forMethod('App', 'Named', 'value::set'), CallableKind::PropertyHook, null, $class, $owner),
        ];

        $classMetrics = new ClassWithMetrics($class, $namedClass->getStartLine(), new MetricBag());
        $bindings = DeclarationBindings::from($ast, $file, $metrics, [
            $classMetrics->subject->toCanonical() => ['subject' => $classMetrics->subject, 'metrics' => $classMetrics->metrics, 'line' => $classMetrics->line],
        ]);

        self::assertSame($class->toCanonical(), $bindings->bindingsFor($namedClass)[0]['subject']->toCanonical());
        self::assertCount(6, $bindings->bindingsFor($namedClass));
        self::assertSame([], $bindings->bindingsFor($anonymousClass));
        self::assertSame(ControlScope::Property, $bindings->bindingsFor($property)[0]['scope']);
        self::assertSame(ControlScope::Hook, $bindings->bindingsFor($hooks[0])[0]['scope']);
        self::assertSame(ControlScope::Callable, $bindings->bindingsFor($method->params[0])[0]['scope']);
        self::assertSame($metrics[0]->declarationPath->toCanonical(), $bindings->bindingsFor($method->params[0])[0]['subject']->toCanonical());
        self::assertSame($metrics[5]->declarationPath->toCanonical(), $bindings->bindingsFor($hooks[1]->params[0])[0]['subject']->toCanonical());
    }

    #[Test]
    public function itFallsBackToTheNearestNamedClassOrFileForUnboundProperties(): void
    {
        $ast = $this->parse('<?php class Named { /** @qmx-threshold complexity.cyclomatic broken */ public int $value; }');
        $nodes = new NodeFinder();
        $class = $nodes->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        $property = $nodes->findFirstInstanceOf($ast, Node\Stmt\Property::class);
        self::assertInstanceOf(Node\Stmt\Class_::class, $class);
        self::assertInstanceOf(Node\Stmt\Property::class, $property);

        $file = RelativePath::fromString('src/Example.php');
        $declaration = new DeclarationPath(SymbolPath::forClass('App', 'Named'), $file, $class->getStartFilePos());
        $classMetrics = new ClassWithMetrics($declaration, 1, new MetricBag());
        $bindings = DeclarationBindings::from($ast, $file, [], [
            $classMetrics->subject->toCanonical() => ['subject' => $classMetrics->subject, 'metrics' => $classMetrics->metrics, 'line' => $classMetrics->line],
        ]);

        self::assertSame($declaration->toCanonical(), $bindings->fallbackBindingsForProperty($property)[0]['subject']->toCanonical());
        self::assertSame(ControlScope::Class_, $bindings->fallbackBindingsForProperty($property)[0]['scope']);
    }

    #[Test]
    public function itRetainsEveryCallableCollisionForDirectAndParameterBindings(): void
    {
        $ast = $this->parse('<?php class Named { public function run(int $value): void {} }');
        $nodes = new NodeFinder();
        $class = $nodes->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        $method = $nodes->findFirstInstanceOf($ast, Node\Stmt\ClassMethod::class);
        self::assertInstanceOf(Node\Stmt\Class_::class, $class);
        self::assertInstanceOf(Node\Stmt\ClassMethod::class, $method);

        $file = RelativePath::fromString('src/Example.php');
        $classDeclaration = new DeclarationPath(SymbolPath::forClass('App', 'Named'), $file, $class->getStartFilePos());
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Named'));
        $first = new DeclarationPath(SymbolPath::forMethod('App', 'Named', 'run'), $file, $method->getStartFilePos(), 0);
        $second = new DeclarationPath(SymbolPath::forMethod('App', 'Named', 'run'), $file, $method->getStartFilePos(), 1);
        $bindings = DeclarationBindings::from($ast, $file, [
            new CallableWithMetrics($first, CallableKind::Method, null, $classDeclaration, $owner, new MetricBag()),
            new CallableWithMetrics($second, CallableKind::Method, null, $classDeclaration, $owner, new MetricBag()),
        ], $this->classMetrics($classDeclaration));

        self::assertSame([$first->toCanonical(), $second->toCanonical()], $this->subjects($bindings->bindingsFor($method)));
        self::assertSame([$first->toCanonical(), $second->toCanonical()], $this->subjects($bindings->bindingsFor($method->params[0])));
    }

    #[Test]
    public function itRetainsPropertyHookAndClassCollisions(): void
    {
        $ast = $this->parse('<?php class Named { public int $value { get => 1; } public function run(): void {} }');
        $nodes = new NodeFinder();
        $class = $nodes->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        $property = $nodes->findFirstInstanceOf($ast, Node\Stmt\Property::class);
        $hook = $nodes->findFirstInstanceOf($ast, Node\PropertyHook::class);
        $method = $nodes->findFirstInstanceOf($ast, Node\Stmt\ClassMethod::class);
        self::assertInstanceOf(Node\Stmt\Class_::class, $class);
        self::assertInstanceOf(Node\Stmt\Property::class, $property);
        self::assertInstanceOf(Node\PropertyHook::class, $hook);
        self::assertInstanceOf(Node\Stmt\ClassMethod::class, $method);

        $file = RelativePath::fromString('src/Example.php');
        $classFirst = new DeclarationPath(SymbolPath::forClass('App', 'Named'), $file, $class->getStartFilePos(), 0);
        $classSecond = new DeclarationPath(SymbolPath::forClass('App', 'Named'), $file, $class->getStartFilePos(), 1);
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Named'));
        $hookFirst = new DeclarationPath(SymbolPath::forMethod('App', 'Named', 'value::get'), $file, $hook->getStartFilePos(), 0);
        $hookSecond = new DeclarationPath(SymbolPath::forMethod('App', 'Named', 'value::get'), $file, $hook->getStartFilePos(), 1);
        $methodFirst = new DeclarationPath(SymbolPath::forMethod('App', 'Named', 'run'), $file, $method->getStartFilePos(), 0);
        $methodSecond = new DeclarationPath(SymbolPath::forMethod('App', 'Named', 'run'), $file, $method->getStartFilePos(), 1);
        $bindings = DeclarationBindings::from($ast, $file, [
            new CallableWithMetrics($hookFirst, CallableKind::PropertyHook, null, $classFirst, $owner, new MetricBag()),
            new CallableWithMetrics($hookSecond, CallableKind::PropertyHook, null, $classSecond, $owner, new MetricBag()),
            new CallableWithMetrics($methodFirst, CallableKind::Method, null, $classFirst, $owner, new MetricBag()),
            new CallableWithMetrics($methodSecond, CallableKind::Method, null, $classSecond, $owner, new MetricBag()),
        ], $this->classMetrics($classFirst, $classSecond));

        self::assertSame([$hookFirst->toCanonical(), $hookSecond->toCanonical()], $this->subjects($bindings->bindingsFor($hook)));
        self::assertSame([$hookFirst->toCanonical(), $hookSecond->toCanonical()], $this->subjects($bindings->bindingsFor($property)));
        self::assertSame([
            $classFirst->toCanonical(),
            $hookFirst->toCanonical(),
            $methodFirst->toCanonical(),
            $classSecond->toCanonical(),
            $hookSecond->toCanonical(),
            $methodSecond->toCanonical(),
        ], $this->subjects($bindings->bindingsFor($class)));
    }

    #[Test]
    public function itRejectsMethodAndFunctionMetadataAtOneMethodPosition(): void
    {
        $ast = $this->parse('<?php class Named { public function run(): void {} }');
        $nodes = new NodeFinder();
        $method = $nodes->findFirstInstanceOf($ast, Node\Stmt\ClassMethod::class);
        self::assertInstanceOf(Node\Stmt\ClassMethod::class, $method);

        $file = RelativePath::fromString('src/Example.php');
        $methodDeclaration = new DeclarationPath(SymbolPath::forMethod('App', 'Named', 'run'), $file, $method->getStartFilePos(), 0);
        $functionDeclaration = new DeclarationPath(SymbolPath::forGlobalFunction('App', 'run'), $file, $method->getStartFilePos(), 1);

        $this->expectException(LogicException::class);
        DeclarationBindings::from($ast, $file, [
            new CallableWithMetrics($methodDeclaration, CallableKind::Method, null, null, null, new MetricBag()),
            new CallableWithMetrics($functionDeclaration, CallableKind::Function, null, null, null, new MetricBag()),
        ], []);
    }

    #[Test]
    public function itRejectsPropertyHookAndMethodMetadataAtOneHookPosition(): void
    {
        $ast = $this->parse('<?php class Named { public int $value { get => 1; } }');
        $nodes = new NodeFinder();
        $hook = $nodes->findFirstInstanceOf($ast, Node\PropertyHook::class);
        self::assertInstanceOf(Node\PropertyHook::class, $hook);

        $file = RelativePath::fromString('src/Example.php');
        $hookDeclaration = new DeclarationPath(SymbolPath::forMethod('App', 'Named', 'value::get'), $file, $hook->getStartFilePos(), 0);
        $methodDeclaration = new DeclarationPath(SymbolPath::forMethod('App', 'Named', 'run'), $file, $hook->getStartFilePos(), 1);

        $this->expectException(LogicException::class);
        DeclarationBindings::from($ast, $file, [
            new CallableWithMetrics($hookDeclaration, CallableKind::PropertyHook, null, null, null, new MetricBag()),
            new CallableWithMetrics($methodDeclaration, CallableKind::Method, null, null, null, new MetricBag()),
        ], []);
    }

    #[Test]
    public function itRejectsClosureAndArrowMetadataAtOneClosurePosition(): void
    {
        $ast = $this->parse('<?php $value = function (): void {};');
        $nodes = new NodeFinder();
        $closure = $nodes->findFirstInstanceOf($ast, Node\Expr\Closure::class);
        self::assertInstanceOf(Node\Expr\Closure::class, $closure);

        $file = RelativePath::fromString('src/Example.php');
        $first = new DeclarationPath(SymbolPath::forGlobalFunction('App', '{closure}'), $file, $closure->getStartFilePos(), 0);
        $second = new DeclarationPath(SymbolPath::forGlobalFunction('App', '{closure}'), $file, $closure->getStartFilePos(), 1);

        $this->expectException(LogicException::class);
        DeclarationBindings::from($ast, $file, [
            new CallableWithMetrics($first, CallableKind::AnonymousCallable, 'closure', null, null, new MetricBag()),
            new CallableWithMetrics($second, CallableKind::AnonymousCallable, 'arrow', null, null, new MetricBag()),
        ], []);
    }

    #[Test]
    public function itRejectsDifferentClassMetadataAtOneClassPosition(): void
    {
        $ast = $this->parse('<?php class Named {}');
        $nodes = new NodeFinder();
        $class = $nodes->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        self::assertInstanceOf(Node\Stmt\Class_::class, $class);

        $file = RelativePath::fromString('src/Example.php');
        $first = new DeclarationPath(SymbolPath::forClass('App', 'Named'), $file, $class->getStartFilePos(), 0);
        $second = new DeclarationPath(SymbolPath::forClass('App', 'Other'), $file, $class->getStartFilePos(), 1);

        $this->expectException(LogicException::class);
        DeclarationBindings::from($ast, $file, [], $this->classMetrics($first, $second));
    }

    /** @return list<Node> */
    private function parse(string $source): array
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        self::assertIsArray($ast);

        return array_values($ast);
    }

    private function callable(
        Node $node,
        SymbolPath $symbol,
        CallableKind $kind,
        ?string $anonymousSyntax = null,
        ?DeclarationPath $lexicalClassContext = null,
        ?LogicalClassPath $owner = null,
    ): CallableWithMetrics {
        return new CallableWithMetrics(
            new DeclarationPath($symbol, RelativePath::fromString('src/Example.php'), $node->getStartFilePos()),
            $kind,
            $anonymousSyntax,
            $lexicalClassContext,
            $owner,
            new MetricBag(),
            $node->getStartLine(),
        );
    }

    /**
     * @return array<string, array{subject: \Qualimetrix\Core\Symbol\MetricSubject, metrics: MetricBag, line: int}>
     */
    private function classMetrics(DeclarationPath ...$declarations): array
    {
        $metrics = [];
        foreach ($declarations as $declaration) {
            $classMetrics = new ClassWithMetrics($declaration, 1, new MetricBag());
            $metrics[$classMetrics->subject->toCanonical()] = [
                'subject' => $classMetrics->subject,
                'metrics' => $classMetrics->metrics,
                'line' => $classMetrics->line,
            ];
        }

        return $metrics;
    }

    /**
     * @param list<array{subject: \Qualimetrix\Core\Symbol\MetricSubject, scope: ControlScope}> $bindings
     *
     * @return list<string>
     */
    private function subjects(array $bindings): array
    {
        return array_map(static fn(array $binding): string => $binding['subject']->toCanonical(), $bindings);
    }
}
