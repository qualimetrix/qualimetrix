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

    /**
     * Under P1's `DeclarationPath`, two producers could disagree about which
     * ordinal a declaration carried and still be treated as one identity: the
     * old comparison used the bare logical symbol, ordinal excluded. P2 makes
     * the ordinal part of identity, so this exact disagreement — same file
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
