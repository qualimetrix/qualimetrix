<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Extraction;

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
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Extraction\DeclarationControlBindings;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(DeclarationControlBindings::class)]
final class DeclarationControlBindingsTest extends TestCase
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
        $class = DeclarationPath::of(SymbolPath::forClass('App', 'Named'), $file, DeclarationOrdinal::fromRank(0));
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Named'));
        $metrics = [
            $this->callable($method, SymbolPath::forMethod('App', 'Named', 'method'), CallableKind::Method, null, $class, $owner),
            $this->callable($function, SymbolPath::forGlobalFunction('App', 'globalFunction'), CallableKind::Function),
            $this->callable($closure, SymbolPath::forGlobalFunction('App', '{closure#1}'), CallableKind::AnonymousCallable, 'closure', $class),
            $this->callable($arrow, SymbolPath::forGlobalFunction('App', '{closure#2}'), CallableKind::AnonymousCallable, 'arrow', $class),
            $this->callable($hooks[0], SymbolPath::forMethod('App', 'Named', 'value::get'), CallableKind::PropertyHook, null, $class, $owner),
            $this->callable($hooks[1], SymbolPath::forMethod('App', 'Named', 'value::set'), CallableKind::PropertyHook, null, $class, $owner),
        ];

        $classMetrics = new ClassWithMetrics($class, $namedClass->getStartFilePos(), $namedClass->getStartLine(), new MetricBag());
        $bindings = DeclarationControlBindings::from($ast, $file, $metrics, [
            $classMetrics->subject->toCanonical() => [
                'subject' => $classMetrics->subject,
                'metrics' => $classMetrics->metrics,
                'line' => $classMetrics->line,
                'start' => $classMetrics->startFilePos,
            ],
        ]);

        self::assertSame($class->toCanonical(), $bindings->suppressionBindingsFor($namedClass)[0]->subject->toCanonical());
        self::assertCount(6, $bindings->suppressionBindingsFor($namedClass));
        self::assertSame([], $bindings->suppressionBindingsFor($anonymousClass));
        self::assertSame(ControlScope::Property, $bindings->suppressionBindingsFor($property)[0]->controlScope);
        self::assertSame(ControlScope::Hook, $bindings->suppressionBindingsFor($hooks[0])[0]->controlScope);
        self::assertSame(ControlScope::Callable, $bindings->suppressionBindingsFor($method->params[0])[0]->controlScope);
        self::assertSame($metrics[0]->declarationPath->toCanonical(), $bindings->suppressionBindingsFor($method->params[0])[0]->subject->toCanonical());
        self::assertSame($metrics[5]->declarationPath->toCanonical(), $bindings->suppressionBindingsFor($hooks[1]->params[0])[0]->subject->toCanonical());
    }

    #[Test]
    public function itFallsBackToTheNearestNamedClassOrFileForUnboundProperties(): void
    {
        $ast = $this->parse('<?php class Named { /** @qmx-threshold complexity.ccn broken */ public int $value; }');
        $nodes = new NodeFinder();
        $class = $nodes->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        $property = $nodes->findFirstInstanceOf($ast, Node\Stmt\Property::class);
        self::assertInstanceOf(Node\Stmt\Class_::class, $class);
        self::assertInstanceOf(Node\Stmt\Property::class, $property);

        $file = RelativePath::fromString('src/Example.php');
        $declaration = DeclarationPath::of(SymbolPath::forClass('App', 'Named'), $file, DeclarationOrdinal::fromRank(1));
        $classMetrics = new ClassWithMetrics($declaration, $class->getStartFilePos(), 1, new MetricBag());
        $bindings = DeclarationControlBindings::from($ast, $file, [], [
            $classMetrics->subject->toCanonical() => [
                'subject' => $classMetrics->subject,
                'metrics' => $classMetrics->metrics,
                'line' => $classMetrics->line,
                'start' => $classMetrics->startFilePos,
            ],
        ]);

        self::assertSame($declaration->toCanonical(), $bindings->fallbackBindingsForProperty($property)[0]['subject']->toCanonical());
        self::assertSame(ControlScope::Class_, $bindings->fallbackBindingsForProperty($property)[0]['scope']);
    }

    #[Test]
    public function itKeepsSuppressionAndThresholdBindingFamiliesSeparate(): void
    {
        $ast = $this->parse(<<<'PHP'
            <?php
            class Named
            {
                public const VALUE = 1;
                public int $plain;
                public int $hooked { get => 1; set (int $value) {} }
                public function run(int $parameter): void {}
                public function __construct(private int $promoted) {}
            }
            function globalFunction(): void {}
            enum State { case Ready; }
            PHP);
        $nodes = new NodeFinder();
        $class = $nodes->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        $methods = $nodes->findInstanceOf($ast, Node\Stmt\ClassMethod::class);
        $function = $nodes->findFirstInstanceOf($ast, Node\Stmt\Function_::class);
        $properties = $nodes->findInstanceOf($ast, Node\Stmt\Property::class);
        $hooks = $nodes->findInstanceOf($ast, Node\PropertyHook::class);
        $constant = $nodes->findFirstInstanceOf($ast, Node\Stmt\ClassConst::class);
        $enum = $nodes->findFirstInstanceOf($ast, Node\Stmt\Enum_::class);
        $enumCase = $nodes->findFirstInstanceOf($ast, Node\Stmt\EnumCase::class);
        self::assertInstanceOf(Node\Stmt\Class_::class, $class);
        self::assertCount(2, $methods);
        self::assertInstanceOf(Node\Stmt\Function_::class, $function);
        self::assertCount(2, $properties);
        self::assertCount(2, $hooks);
        self::assertInstanceOf(Node\Stmt\ClassConst::class, $constant);
        self::assertInstanceOf(Node\Stmt\Enum_::class, $enum);
        self::assertInstanceOf(Node\Stmt\EnumCase::class, $enumCase);

        $file = RelativePath::fromString('src/Example.php');
        $classDeclaration = DeclarationPath::of(SymbolPath::forClass('App', 'Named'), $file, DeclarationOrdinal::fromRank(0));
        $enumDeclaration = DeclarationPath::of(SymbolPath::forClass('App', 'State'), $file, DeclarationOrdinal::fromRank(0));
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Named'));
        $callables = [
            $this->callable($methods[0], SymbolPath::forMethod('App', 'Named', 'run'), CallableKind::Method, null, $classDeclaration, $owner),
            $this->callable($methods[1], SymbolPath::forMethod('App', 'Named', '__construct'), CallableKind::Method, null, $classDeclaration, $owner),
            $this->callable($function, SymbolPath::forGlobalFunction('App', 'globalFunction'), CallableKind::Function),
            $this->callable($hooks[0], SymbolPath::forMethod('App', 'Named', 'hooked::get'), CallableKind::PropertyHook, null, $classDeclaration, $owner),
            $this->callable($hooks[1], SymbolPath::forMethod('App', 'Named', 'hooked::set'), CallableKind::PropertyHook, null, $classDeclaration, $owner),
        ];
        $bindings = DeclarationControlBindings::from(
            $ast,
            $file,
            $callables,
            [
                ...$this->classMetricsAt($class->getStartFilePos(), $classDeclaration),
                ...$this->classMetricsAt($enum->getStartFilePos(), $enumDeclaration),
            ],
        );

        self::assertCount(5, $bindings->suppressionBindingsFor($class));
        self::assertCount(5, $bindings->thresholdBindingsFor($class));
        self::assertCount(2, $bindings->suppressionBindingsFor($methods[0]));
        self::assertSame([
            'whole:' . $methods[0]->getEndLine(),
            \sprintf('lines:%d:%d', $methods[0]->getStartLine(), $methods[0]->getEndLine()),
        ], array_map(static fn($binding): string => $binding->reach->key(), $bindings->suppressionBindingsFor($methods[0])));
        self::assertSame(
            ['method run'],
            array_values(array_unique(array_map(
                static fn($binding): string => $binding->reach->standsOn,
                $bindings->suppressionBindingsFor($methods[0]),
            ))),
        );
        self::assertCount(1, $bindings->thresholdBindingsFor($methods[0]));
        self::assertCount(1, $bindings->suppressionBindingsFor($function));
        self::assertSame('whole:' . $function->getEndLine(), $bindings->suppressionBindingsFor($function)[0]->reach->key());
        self::assertCount(1, $bindings->thresholdBindingsFor($function));
        self::assertCount(1, $bindings->suppressionBindingsFor($properties[0]));
        self::assertSame(
            \sprintf('lines:%d:%d', $properties[0]->getStartLine(), $properties[0]->getEndLine()),
            $bindings->suppressionBindingsFor($properties[0])[0]->reach->key(),
        );
        self::assertSame([], $bindings->thresholdBindingsFor($properties[0]));
        self::assertCount(3, $bindings->suppressionBindingsFor($properties[1]));
        self::assertSame([
            'whole:' . $hooks[0]->getEndLine(),
            'whole:' . $hooks[1]->getEndLine(),
            \sprintf('lines:%d:%d', $properties[1]->getStartLine(), $properties[1]->getEndLine()),
        ], array_map(static fn($binding): string => $binding->reach->key(), $bindings->suppressionBindingsFor($properties[1])));
        self::assertCount(2, $bindings->thresholdBindingsFor($properties[1]));
        self::assertCount(1, $bindings->suppressionBindingsFor($constant));
        self::assertSame(
            \sprintf('lines:%d:%d', $constant->getStartLine(), $constant->getEndLine()),
            $bindings->suppressionBindingsFor($constant)[0]->reach->key(),
        );
        self::assertSame([], $bindings->thresholdBindingsFor($constant));
        self::assertCount(1, $bindings->suppressionBindingsFor($enumCase));
        self::assertSame(
            \sprintf('lines:%d:%d', $enumCase->getStartLine(), $enumCase->getEndLine()),
            $bindings->suppressionBindingsFor($enumCase)[0]->reach->key(),
        );
        self::assertSame([], $bindings->thresholdBindingsFor($enumCase));
        self::assertCount(1, $bindings->suppressionBindingsFor($methods[0]->params[0]));
        self::assertSame(
            \sprintf('lines:%d:%d', $methods[0]->params[0]->getStartLine(), $methods[0]->params[0]->getEndLine()),
            $bindings->suppressionBindingsFor($methods[0]->params[0])[0]->reach->key(),
        );
        self::assertSame([], $bindings->thresholdBindingsFor($methods[0]->params[0]));
        self::assertCount(2, $bindings->suppressionBindingsFor($methods[1]->params[0]));
        self::assertSame([
            \sprintf('lines:%d:%d', $methods[1]->params[0]->getStartLine(), $methods[1]->params[0]->getEndLine()),
            \sprintf('lines:%d:%d', $methods[1]->params[0]->getStartLine(), $methods[1]->params[0]->getEndLine()),
        ], array_map(static fn($binding): string => $binding->reach->key(), $bindings->suppressionBindingsFor($methods[1]->params[0])));
        self::assertSame([], $bindings->thresholdBindingsFor($methods[1]->params[0]));
    }

    #[Test]
    public function itBindsAnAnonymousCallableUsedAsTheOwnersDirectValue(): void
    {
        $ast = $this->parse('<?php $callback = function (): void {};');
        $nodes = new NodeFinder();
        $owner = $nodes->findFirstInstanceOf($ast, Node\Stmt\Expression::class);
        $closure = $nodes->findFirstInstanceOf($ast, Node\Expr\Closure::class);
        self::assertInstanceOf(Node\Stmt\Expression::class, $owner);
        self::assertInstanceOf(Node\Expr\Closure::class, $closure);
        $file = RelativePath::fromString('src/Example.php');
        $callable = $this->callable(
            $closure,
            SymbolPath::forGlobalFunction('App', '{closure#1}'),
            CallableKind::AnonymousCallable,
            'closure',
        );
        $bindings = DeclarationControlBindings::from($ast, $file, [$callable], []);
        $suppressionBindings = $bindings->suppressionBindingsFor($owner);
        $thresholdBindings = $bindings->thresholdBindingsFor($owner);

        self::assertCount(1, $suppressionBindings);
        self::assertCount(1, $thresholdBindings);
        self::assertSame(
            $callable->declarationPath->toCanonical(),
            $suppressionBindings[0]->subject->toCanonical(),
        );
        self::assertSame(
            $callable->declarationPath->toCanonical(),
            $thresholdBindings[0]['subject']->toCanonical(),
        );
    }

    /**
     * Two producers disagreeing about which ordinal a declaration carries
     * must not be treated as one identity. The ordinal is part of identity,
     * so this disagreement — same file
     * position, same symbol, different ordinal — is now what the guard exists
     * to catch, and it must reject rather than merge the two into a binding
     * list.
     */
    #[Test]
    public function itRejectsCallableMetadataThatOnlyDisagreesByOrdinalAtOneMethodPosition(): void
    {
        $ast = $this->parse('<?php class Named { public function run(int $value): void {} }');
        $nodes = new NodeFinder();
        $class = $nodes->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        $method = $nodes->findFirstInstanceOf($ast, Node\Stmt\ClassMethod::class);
        self::assertInstanceOf(Node\Stmt\Class_::class, $class);
        self::assertInstanceOf(Node\Stmt\ClassMethod::class, $method);

        $file = RelativePath::fromString('src/Example.php');
        $classDeclaration = DeclarationPath::of(SymbolPath::forClass('App', 'Named'), $file, DeclarationOrdinal::fromRank(0));
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Named'));
        $first = DeclarationPath::of(SymbolPath::forMethod('App', 'Named', 'run'), $file, DeclarationOrdinal::fromRank(0));
        $second = DeclarationPath::of(SymbolPath::forMethod('App', 'Named', 'run'), $file, DeclarationOrdinal::fromRank(1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Incompatible declaration metadata at file position');
        DeclarationControlBindings::from($ast, $file, [
            new CallableWithMetrics($first, $method->getStartFilePos(), CallableKind::Method, null, $classDeclaration, $owner, new MetricBag()),
            new CallableWithMetrics($second, $method->getStartFilePos(), CallableKind::Method, null, $classDeclaration, $owner, new MetricBag()),
        ], $this->classMetricsAt($class->getStartFilePos(), $classDeclaration));
    }

    /**
     * The class-level counterpart of the method case above: a property hook
     * and its owning class disagreeing only by ordinal at one physical
     * position must be rejected the same way.
     */
    #[Test]
    public function itRejectsPropertyHookAndClassMetadataThatOnlyDisagreeByOrdinalAtOnePosition(): void
    {
        $ast = $this->parse('<?php class Named { public int $value { get => 1; } public function run(): void {} }');
        $nodes = new NodeFinder();
        $class = $nodes->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        $hook = $nodes->findFirstInstanceOf($ast, Node\PropertyHook::class);
        self::assertInstanceOf(Node\Stmt\Class_::class, $class);
        self::assertInstanceOf(Node\PropertyHook::class, $hook);

        $file = RelativePath::fromString('src/Example.php');
        $classFirst = DeclarationPath::of(SymbolPath::forClass('App', 'Named'), $file, DeclarationOrdinal::fromRank(0));
        $classSecond = DeclarationPath::of(SymbolPath::forClass('App', 'Named'), $file, DeclarationOrdinal::fromRank(1));
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Named'));
        $hookFirst = DeclarationPath::of(SymbolPath::forMethod('App', 'Named', 'value::get'), $file, DeclarationOrdinal::fromRank(0));
        $hookSecond = DeclarationPath::of(SymbolPath::forMethod('App', 'Named', 'value::get'), $file, DeclarationOrdinal::fromRank(1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Incompatible declaration metadata at file position');
        DeclarationControlBindings::from($ast, $file, [
            new CallableWithMetrics($hookFirst, $hook->getStartFilePos(), CallableKind::PropertyHook, null, $classFirst, $owner, new MetricBag()),
            new CallableWithMetrics($hookSecond, $hook->getStartFilePos(), CallableKind::PropertyHook, null, $classSecond, $owner, new MetricBag()),
        ], $this->classMetricsAt($class->getStartFilePos(), $classFirst, $classSecond));
    }

    #[Test]
    public function itRejectsMethodAndFunctionMetadataAtOneMethodPosition(): void
    {
        $ast = $this->parse('<?php class Named { public function run(): void {} }');
        $nodes = new NodeFinder();
        $method = $nodes->findFirstInstanceOf($ast, Node\Stmt\ClassMethod::class);
        self::assertInstanceOf(Node\Stmt\ClassMethod::class, $method);

        $file = RelativePath::fromString('src/Example.php');
        $methodDeclaration = DeclarationPath::of(SymbolPath::forMethod('App', 'Named', 'run'), $file, DeclarationOrdinal::fromRank(0));
        $functionDeclaration = DeclarationPath::of(SymbolPath::forGlobalFunction('App', 'run'), $file, DeclarationOrdinal::fromRank(1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Incompatible declaration metadata at file position');
        DeclarationControlBindings::from($ast, $file, [
            new CallableWithMetrics($methodDeclaration, $method->getStartFilePos(), CallableKind::Method, null, null, null, new MetricBag()),
            new CallableWithMetrics($functionDeclaration, $method->getStartFilePos(), CallableKind::Function, null, null, null, new MetricBag()),
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
        $hookDeclaration = DeclarationPath::of(SymbolPath::forMethod('App', 'Named', 'value::get'), $file, DeclarationOrdinal::fromRank(0));
        $methodDeclaration = DeclarationPath::of(SymbolPath::forMethod('App', 'Named', 'run'), $file, DeclarationOrdinal::fromRank(1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Incompatible declaration metadata at file position');
        DeclarationControlBindings::from($ast, $file, [
            new CallableWithMetrics($hookDeclaration, $hook->getStartFilePos(), CallableKind::PropertyHook, null, null, null, new MetricBag()),
            new CallableWithMetrics($methodDeclaration, $hook->getStartFilePos(), CallableKind::Method, null, null, null, new MetricBag()),
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
        $first = DeclarationPath::of(SymbolPath::forGlobalFunction('App', '{closure}'), $file, DeclarationOrdinal::fromRank(0));
        $second = DeclarationPath::of(SymbolPath::forGlobalFunction('App', '{closure}'), $file, DeclarationOrdinal::fromRank(1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Incompatible declaration metadata at file position');
        DeclarationControlBindings::from($ast, $file, [
            new CallableWithMetrics($first, $closure->getStartFilePos(), CallableKind::AnonymousCallable, 'closure', null, null, new MetricBag()),
            new CallableWithMetrics($second, $closure->getStartFilePos(), CallableKind::AnonymousCallable, 'arrow', null, null, new MetricBag()),
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
        $first = DeclarationPath::of(SymbolPath::forClass('App', 'Named'), $file, DeclarationOrdinal::fromRank(0));
        $second = DeclarationPath::of(SymbolPath::forClass('App', 'Other'), $file, DeclarationOrdinal::fromRank(1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Incompatible declaration metadata at file position');
        DeclarationControlBindings::from($ast, $file, [], $this->classMetricsAt($class->getStartFilePos(), $first, $second));
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
            DeclarationPath::of($symbol, RelativePath::fromString('src/Example.php'), DeclarationOrdinal::fromRank(0)),
            $node->getStartFilePos(),
            $kind,
            $anonymousSyntax,
            $lexicalClassContext,
            $owner,
            new MetricBag(),
            $node->getStartLine(),
        );
    }

    /**
     * @return array<string, array{subject: \Qualimetrix\Core\Symbol\MetricSubject, metrics: MetricBag, line: int, start: int}>
     */
    private function classMetricsAt(int $start, DeclarationPath ...$declarations): array
    {
        $metrics = [];
        foreach ($declarations as $declaration) {
            $classMetrics = new ClassWithMetrics($declaration, $start, 1, new MetricBag());
            $metrics[$classMetrics->subject->toCanonical()] = [
                'subject' => $classMetrics->subject,
                'metrics' => $classMetrics->metrics,
                'line' => $classMetrics->line,
                'start' => $classMetrics->startFilePos,
            ];
        }

        return $metrics;
    }
}
