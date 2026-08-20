<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Collection;

use LogicException;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyResolver;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceMetricProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Extraction\SourceControlExtractor;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingFailureKind;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Exception\ParseException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

#[CoversClass(FileProcessor::class)]
final class FileProcessorTest extends TestCase
{
    private FileParserInterface&Stub $parser;

    protected function setUp(): void
    {
        $this->parser = self::createStub(FileParserInterface::class);
    }

    private function makeProcessor(CompositeCollector $collector): FileProcessor
    {
        $processor = new FileProcessor($this->parser, $collector, new SourceControlExtractor());
        $processor->setProjectRoot(AbsolutePath::fromString('/tmp'));

        return $processor;
    }

    #[Test]
    public function itThrowsLogicExceptionWhenProcessCalledBeforeSetProjectRoot(): void
    {
        // Phase 6 review MEDIUM: assert() was no-op under zend.assertions=-1
        // and let process() fall through to a TypeError. Explicit throw now
        // guarantees a clean LogicException whether assertions are enabled
        // or not.
        $processor = new FileProcessor($this->parser, new CompositeCollector([], new DeclarationRegistrarFactory()), new SourceControlExtractor());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('projectRoot must be set');

        $processor->process(new SplFileInfo('/tmp/test.php'));
    }

    #[Test]
    public function itProcessesFileSuccessfully(): void
    {
        $file = new SplFileInfo('/tmp/test.php');
        $ast = [];
        $fileBag = MetricBag::fromArray(['loc' => 50]);

        $this->parser->method('parse')->willReturn($ast);

        $collector = $this->createMock(MetricCollectorInterface::class);
        $collector->method('provides')->willReturn(['loc']);
        $collector->method('getVisitor')->willReturn(new class extends NodeVisitorAbstract {});
        $collector->method('collect')->willReturn($fileBag);
        $collector->expects(self::once())->method('reset');

        $compositeCollector = new CompositeCollector([$collector], new DeclarationRegistrarFactory());

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        self::assertTrue($result->isSuccessful());
        self::assertSame('test.php', $result->filePath->value());
        self::assertSame(50, $result->fileBag()->get('loc'));
    }

    #[Test]
    public function itReturnsFailureOnParseException(): void
    {
        $file = new SplFileInfo('/tmp/invalid.php');

        $this->parser->method('parse')->willThrowException(
            new ParseException(AbsolutePath::fromString('/tmp/invalid.php'), 'Syntax error'),
        );

        $compositeCollector = new CompositeCollector([], new DeclarationRegistrarFactory());

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        self::assertFalse($result->isSuccessful());
        self::assertSame(FileProcessingFailureKind::Parse, $result->failureKind());
        self::assertSame('invalid.php', $result->filePath->value());
        self::assertStringContainsString('Syntax error', $result->error());
    }

    #[Test]
    public function itExtractsMethodMetricsFromCollectors(): void
    {
        $file = new SplFileInfo('/tmp/test.php');
        $ast = $this->parseLiteral('<?php class Service { public function calculate(): void {} }');
        $method = $this->singleNode($ast, Node\Stmt\ClassMethod::class);

        $this->parser->method('parse')->willReturn($ast);

        $symbolPath = SymbolPath::forMethod('App', 'Service', 'calculate');
        $methodBag = MetricBag::fromArray(['ccn' => 5]);

        $methodWithMetrics = new CallableWithMetrics(
            DeclarationPath::of($symbolPath, RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0)),
            $method->getStartFilePos(),
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath(SymbolPath::forClass('App', 'Service')),
            $methodBag,
        );

        // Create a mock that implements both interfaces
        $collector = $this->createMockCollectorWithMethodMetrics([$methodWithMetrics]);

        $compositeCollector = new CompositeCollector([$collector], new DeclarationRegistrarFactory());

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->callableMetrics());
        self::assertSame($methodWithMetrics, $result->callableMetrics()[0]);
    }

    #[Test]
    public function itExtractsClassMetricsFromCollectors(): void
    {
        $file = new SplFileInfo('/tmp/test.php');
        $ast = $this->parseLiteral('<?php class Service {}');
        $class = $this->singleNode($ast, Node\Stmt\Class_::class);

        $this->parser->method('parse')->willReturn($ast);

        $symbolPath = SymbolPath::forClass('App', 'Service');
        $classBag = MetricBag::fromArray(['wmc' => 25]);

        $classWithMetrics = new ClassWithMetrics(
            declarationPath: DeclarationPath::of($symbolPath, RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(1)),
            startFilePos: $class->getStartFilePos(),
            line: $class->getStartLine(),
            metrics: $classBag,
        );

        $collector = $this->createMockCollectorWithClassMetrics([$classWithMetrics]);

        $compositeCollector = new CompositeCollector([$collector], new DeclarationRegistrarFactory());

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->classMetrics());
        self::assertArrayHasKey($classWithMetrics->subject->toCanonical(), $result->classMetrics());
        self::assertSame(25, $result->classMetrics()[$classWithMetrics->subject->toCanonical()]['metrics']->get('wmc'));
    }

    #[Test]
    public function itExtractsNamespaceMetricsFromCollectors(): void
    {
        $file = new SplFileInfo('/tmp/test.php');
        $this->parser->method('parse')->willReturn([]);
        $namespace = new NamespaceWithMetrics('App', 3, MetricBag::fromArray(['loc' => 8]));
        $collector = $this->createMockCollectorWithNamespaceMetrics([$namespace]);

        $result = $this->makeProcessor(new CompositeCollector([$collector], new DeclarationRegistrarFactory()))->process($file);

        self::assertTrue($result->isSuccessful());
        self::assertSame(8, $result->namespaceMetrics()['ns:App']['metrics']->get('loc'));
        self::assertSame(3, $result->namespaceMetrics()['ns:App']['line']);
    }

    #[Test]
    public function itCollectsDependenciesWithDependencyVisitor(): void
    {
        $file = new SplFileInfo('/tmp/test.php');
        $ast = [];

        $this->parser->method('parse')->willReturn($ast);

        // Use real DependencyVisitor with DependencyResolver
        $dependencyResolver = new DependencyResolver();
        $dependencyVisitor = new DependencyVisitor($dependencyResolver);

        $compositeCollector = new CompositeCollector([], new DeclarationRegistrarFactory(), [], $dependencyVisitor);

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        // With empty AST, no dependencies should be collected
        self::assertTrue($result->isSuccessful());
        self::assertCount(0, $result->dependencies());
    }

    #[Test]
    public function itPreservesClosuresWithDeclarationIdentity(): void
    {
        $file = new SplFileInfo('/tmp/test.php');
        $ast = $this->parseLiteral('<?php $value = function (): void {};');
        $closure = $this->singleNode($ast, Node\Expr\Closure::class);

        $this->parser->method('parse')->willReturn($ast);

        $closurePath = SymbolPath::forGlobalFunction('', '{closure:0}');
        $methodWithMetrics = new CallableWithMetrics(
            DeclarationPath::of($closurePath, RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0)),
            $closure->getStartFilePos(),
            CallableKind::AnonymousCallable,
            'closure',
            null,
            null,
            MetricBag::fromArray(['ccn' => 3]),
        );

        $collector = $this->createMockCollectorWithMethodMetrics([$methodWithMetrics]);

        $compositeCollector = new CompositeCollector([$collector], new DeclarationRegistrarFactory());

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        self::assertTrue($result->isSuccessful());
        self::assertSame([$methodWithMetrics], $result->callableMetrics());
    }

    #[Test]
    public function itPreservesCallableSourceLineWhenCollectorPayloadsMerge(): void
    {
        $file = new SplFileInfo('/tmp/test.php');
        $ast = $this->parseLiteral('<?php class Service { public function run(): void {} }');
        $method = $this->singleNode($ast, Node\Stmt\ClassMethod::class);
        $this->parser->method('parse')->willReturn($ast);

        $symbol = SymbolPath::forMethod('App', 'Service', 'run');
        $declaration = DeclarationPath::of($symbol, RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Service'));
        $first = new CallableWithMetrics(
            $declaration,
            $method->getStartFilePos(),
            CallableKind::Method,
            null,
            null,
            $owner,
            MetricBag::fromArray(['ccn' => 3]),
            $method->getStartLine(),
        );
        $second = new CallableWithMetrics(
            $declaration,
            $method->getStartFilePos(),
            CallableKind::Method,
            null,
            null,
            $owner,
            MetricBag::fromArray(['npath' => 5]),
            $method->getStartLine(),
        );

        $result = $this->makeProcessor(new CompositeCollector([
            $this->createMockCollectorWithMethodMetrics([$first]),
            $this->createMockCollectorWithMethodMetrics([$second]),
        ], new DeclarationRegistrarFactory()))->process($file);

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->callableMetrics());
        $callable = $result->callableMetrics()[0];
        self::assertSame($method->getStartLine(), $callable->sourceLine);
        self::assertNotSame($callable->startFilePos, $callable->sourceLine);
        self::assertSame(3, $callable->metrics->get('ccn'));
        self::assertSame(5, $callable->metrics->get('npath'));
    }

    #[Test]
    public function itExtractsSuppressionsFromExpressionNodes(): void
    {
        $file = new SplFileInfo('/tmp/test.php');

        // Build AST: a class with a method containing an Expression with a docblock
        $docComment = new Doc(
            "/** @qmx-ignore-next-line code-smell.exit */",
            startLine: 10,
            endLine: 10,
        );

        // Create an Expression node (e.g., exit(0);) with docblock
        $exitCall = new Node\Expr\FuncCall(new Node\Name('exit'), [new Node\Arg(new Node\Scalar\Int_(0))]);
        $expression = new Node\Stmt\Expression($exitCall, ['startLine' => 11, 'endLine' => 11]);
        $expression->setDocComment($docComment);

        $method = new Node\Stmt\ClassMethod('run', ['stmts' => [$expression]], ['startLine' => 8, 'endLine' => 12]);
        $class = new Node\Stmt\Class_('MyClass', ['stmts' => [$method]], ['startLine' => 5, 'endLine' => 13]);
        $namespace = new Node\Stmt\Namespace_(new Node\Name('App'), [$class], ['startLine' => 1, 'endLine' => 14]);

        $this->parser->method('parse')->willReturn([$namespace]);

        $compositeCollector = new CompositeCollector([], new DeclarationRegistrarFactory());

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        self::assertTrue($result->isSuccessful());
        self::assertNotEmpty($result->suppressions());

        $nextLineSuppressions = array_filter(
            $result->suppressions(),
            static fn($s) => $s->type === SuppressionType::NextLine && $s->rule === 'code-smell.exit',
        );
        self::assertCount(1, $nextLineSuppressions);
    }

    #[Test]
    public function itCollectsClassAndNestedMethodThresholdOverrides(): void
    {
        $file = new SplFileInfo('/tmp/test.php');

        $method = new Node\Stmt\ClassMethod('run', attributes: [
            'startLine' => 10,
            'endLine' => 15,
            'startFilePos' => 100,
            'endFilePos' => 180,
        ]);
        $method->setDocComment(new Doc(
            '/** @qmx-threshold complexity.cyclomatic warning=40 error=50 */',
            startLine: 9,
            endLine: 9,
        ));

        $class = new Node\Stmt\Class_('MyClass', ['stmts' => [$method]], [
            'startLine' => 5,
            'endLine' => 20,
            'startFilePos' => 10,
            'endFilePos' => 200,
        ]);
        $class->setDocComment(new Doc(
            '/** @qmx-threshold complexity.cyclomatic warning=20 error=30 */',
            startLine: 4,
            endLine: 4,
        ));

        $this->parser->method('parse')->willReturn([$class]);

        $classPath = DeclarationPath::of(SymbolPath::forClass('', 'MyClass'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $class = new ClassWithMetrics(
            $classPath,
            10,
            5,
            new MetricBag(),
        );
        $methodMetric = new CallableWithMetrics(
            DeclarationPath::of(SymbolPath::forMethod('', 'MyClass', 'run'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0)),
            100,
            CallableKind::Method,
            null,
            $classPath,
            new LogicalClassPath(SymbolPath::forClass('', 'MyClass')),
            new MetricBag(),
            10,
        );

        $processor = $this->makeProcessor(new CompositeCollector([
            $this->createMockCollectorWithClassMetrics([$class]),
            $this->createMockCollectorWithMethodMetrics([$methodMetric]),
        ], new DeclarationRegistrarFactory()));
        $result = $processor->process($file);

        self::assertTrue($result->isSuccessful());
        self::assertSame([], $result->thresholdDiagnostics());
        self::assertCount(3, $result->thresholdOverrides());

        $classOverride = $result->thresholdOverrides()[0];
        self::assertSame('complexity.cyclomatic', $classOverride->rulePattern);
        self::assertSame(20, $classOverride->warning);
        self::assertSame(30, $classOverride->error);
        self::assertSame(4, $classOverride->line);
        self::assertSame(20, $classOverride->endLine);
        self::assertSame(ControlScope::Class_, $classOverride->controlScope);

        $inheritedClassOverride = $result->thresholdOverrides()[1];
        self::assertSame($methodMetric->declarationPath->toCanonical(), $inheritedClassOverride->subject->toCanonical());
        self::assertSame(ControlScope::Class_, $inheritedClassOverride->controlScope);

        $methodOverride = $result->thresholdOverrides()[2];
        self::assertSame('complexity.cyclomatic', $methodOverride->rulePattern);
        self::assertSame(40, $methodOverride->warning);
        self::assertSame(50, $methodOverride->error);
        self::assertSame(9, $methodOverride->line);
        self::assertSame(15, $methodOverride->endLine);
        self::assertSame(ControlScope::Callable, $methodOverride->controlScope);
    }

    #[Test]
    public function itDropsValidPropertyThresholdsWithoutHooksAndBindsMalformedOnesToTheClass(): void
    {
        $ast = $this->parseLiteral(<<<'PHP'
            <?php
            namespace App;

            class Record
            {
                /** @qmx-threshold complexity.cyclomatic 12 */
                public int $withoutHooks;

                /** @qmx-threshold complexity.cyclomatic broken */
                public int $invalidWithoutHooks;
            }
            PHP);
        $class = $this->singleNode($ast, Node\Stmt\Class_::class);
        $classDeclaration = DeclarationPath::of(SymbolPath::forClass('App', 'Record'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));

        $result = $this->processLiteralAst($ast, [new ClassWithMetrics($classDeclaration, $class->getStartFilePos(), $class->getStartLine(), new MetricBag())]);

        self::assertTrue($result->isSuccessful());
        self::assertSame([], $result->thresholdOverrides());
        self::assertCount(1, $result->thresholdDiagnostics());
        self::assertSame($classDeclaration->toCanonical(), $result->thresholdDiagnostics()[0]->subject->toCanonical());
    }

    #[Test]
    public function itBindsMalformedTopLevelPropertyThresholdDiagnosticsToTheFileSubject(): void
    {
        $property = new Node\Stmt\Property(
            0,
            [new Node\PropertyItem('unreachable')],
            ['startLine' => 2, 'endLine' => 2, 'startFilePos' => 10, 'endFilePos' => 30],
        );
        $property->setDocComment(new Doc('/** @qmx-threshold complexity.cyclomatic broken */', 1, 1));

        $result = $this->processLiteralAst([$property]);

        self::assertTrue($result->isSuccessful());
        self::assertSame([], $result->thresholdOverrides());
        self::assertCount(1, $result->thresholdDiagnostics());
        self::assertSame('file:test.php', $result->thresholdDiagnostics()[0]->subject->toCanonical());
    }

    #[Test]
    public function itBindsPromotedAndOrdinaryParameterSuppressionsToTheirInnermostConstructor(): void
    {
        $ast = $this->parseLiteral(<<<'PHP'
            <?php
            namespace App;

            /** @qmx-ignore complexity.cyclomatic class fallback */
            class Record
            {
                public function __construct(
                    /** @qmx-ignore complexity.cyclomatic promoted parameter */
                    public int $promoted,
                    /** @qmx-ignore complexity.cyclomatic ordinary parameter */
                    int $ordinary,
                ) {}
            }
            PHP);
        $class = $this->singleNode($ast, Node\Stmt\Class_::class);
        $constructor = $this->singleNode($ast, Node\Stmt\ClassMethod::class);
        $classDeclaration = DeclarationPath::of(SymbolPath::forClass('App', 'Record'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $constructorDeclaration = DeclarationPath::of(SymbolPath::forMethod('App', 'Record', '__construct'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $constructorMetric = new CallableWithMetrics(
            $constructorDeclaration,
            $constructor->getStartFilePos(),
            CallableKind::Method,
            null,
            $classDeclaration,
            new LogicalClassPath(SymbolPath::forClass('App', 'Record')),
            new MetricBag(),
            $constructor->getStartLine(),
        );

        $result = $this->processLiteralAst(
            $ast,
            [new ClassWithMetrics($classDeclaration, $class->getStartFilePos(), $class->getStartLine(), new MetricBag())],
            [$constructorMetric],
        );

        self::assertTrue($result->isSuccessful());
        $controls = array_values(array_filter(
            $result->suppressions(),
            static fn($suppression) => $suppression->type === SuppressionType::Symbol,
        ));
        self::assertCount(4, $controls);
        self::assertSame(
            [$classDeclaration->toCanonical(), $constructorDeclaration->toCanonical(), $constructorDeclaration->toCanonical(), $constructorDeclaration->toCanonical()],
            array_map(static fn($control) => $control->subject?->toCanonical(), $controls),
        );
        self::assertSame([ControlScope::Class_, ControlScope::Class_, ControlScope::Callable, ControlScope::Callable], array_map(
            static fn($control) => $control->controlScope,
            $controls,
        ));
    }

    #[Test]
    public function itBindsNestedClosureAndArrowControlsToTheirOwnDeclarations(): void
    {
        $ast = $this->parseLiteral(<<<'PHP'
            <?php
            namespace App;

            /** @qmx-ignore complexity.cyclomatic class fallback */
            class Record
            {
                public function run(): void
                {
                    $closure = function (): int { return 1; };
                    $arrow = fn(): int => 1;
                }
            }
            PHP);
        $class = $this->singleNode($ast, Node\Stmt\Class_::class);
        $method = $this->singleNode($ast, Node\Stmt\ClassMethod::class);
        $closure = $this->singleNode($ast, Node\Expr\Closure::class);
        $arrow = $this->singleNode($ast, Node\Expr\ArrowFunction::class);
        // php-parser attaches comments before an assignment to Expression rather than
        // the nested callable. Attach the supported callable doc-comment position
        // explicitly so this fixture exercises FileProcessor's innermost binding.
        $closure->setDocComment(new Doc('/** @qmx-ignore complexity.cyclomatic closure control */', 9, 9));
        $arrow->setDocComment(new Doc('/** @qmx-ignore complexity.cyclomatic arrow control */', 11, 11));
        $classDeclaration = DeclarationPath::of(SymbolPath::forClass('App', 'Record'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $methodDeclaration = DeclarationPath::of(SymbolPath::forMethod('App', 'Record', 'run'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $closureDeclaration = DeclarationPath::of(SymbolPath::forGlobalFunction('App', '{closure#1}'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $arrowDeclaration = DeclarationPath::of(SymbolPath::forGlobalFunction('App', '{closure#2}'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $metrics = [
            new CallableWithMetrics($methodDeclaration, $method->getStartFilePos(), CallableKind::Method, null, $classDeclaration, new LogicalClassPath(SymbolPath::forClass('App', 'Record')), new MetricBag(), $method->getStartLine()),
            new CallableWithMetrics($closureDeclaration, $closure->getStartFilePos(), CallableKind::AnonymousCallable, 'closure', $classDeclaration, null, new MetricBag(), $closure->getStartLine()),
            new CallableWithMetrics($arrowDeclaration, $arrow->getStartFilePos(), CallableKind::AnonymousCallable, 'arrow', $classDeclaration, null, new MetricBag(), $arrow->getStartLine()),
        ];

        $result = $this->processLiteralAst($ast, [new ClassWithMetrics($classDeclaration, $class->getStartFilePos(), $class->getStartLine(), new MetricBag())], $metrics);

        self::assertTrue($result->isSuccessful());
        $controls = array_values(array_filter(
            $result->suppressions(),
            static fn($suppression) => $suppression->type === SuppressionType::Symbol,
        ));
        self::assertCount(6, $controls);
        self::assertSame(
            [$classDeclaration->toCanonical(), $methodDeclaration->toCanonical(), $closureDeclaration->toCanonical(), $arrowDeclaration->toCanonical(), $closureDeclaration->toCanonical(), $arrowDeclaration->toCanonical()],
            array_map(static fn($control) => $control->subject?->toCanonical(), $controls),
        );
        self::assertSame(
            [ControlScope::Class_, ControlScope::Class_, ControlScope::Class_, ControlScope::Class_, ControlScope::Callable, ControlScope::Callable],
            array_map(static fn($control) => $control->controlScope, $controls),
        );
    }

    #[Test]
    public function itExpandsOuterClassControlsOnlyToCallablesWithItsExactLexicalClass(): void
    {
        $ast = $this->parseLiteral(<<<'PHP'
            <?php
            namespace App;

            /** @qmx-threshold complexity.cyclomatic 10 */
            class Outer
            {
                public function run(): void
                {
                    $outerClosure = function (): int { return 1; };
                    $anonymous = new class {
                        public function nested(): void {}
                    };
                }
            }
            PHP);
        $classes = (new NodeFinder())->findInstanceOf($ast, Node\Stmt\Class_::class);
        $outer = $classes[0] ?? throw new LogicException('Missing outer class');
        $anonymous = $classes[1] ?? throw new LogicException('Missing anonymous class');
        $method = $this->singleNode($ast, Node\Stmt\ClassMethod::class);
        $closure = $this->singleNode($ast, Node\Expr\Closure::class);
        $classMethods = (new NodeFinder())->findInstanceOf($ast, Node\Stmt\ClassMethod::class);
        $nestedMethod = $classMethods[1] ?? throw new LogicException('Missing nested method');
        $outerDeclaration = DeclarationPath::of(SymbolPath::forClass('App', 'Outer'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $anonymousDeclaration = DeclarationPath::of(SymbolPath::forClass('App', '{anonymous#0}'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $methodDeclaration = DeclarationPath::of(SymbolPath::forMethod('App', 'Outer', 'run'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $closureDeclaration = DeclarationPath::of(SymbolPath::forGlobalFunction('App', '{closure#1}'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $nestedDeclaration = DeclarationPath::of(SymbolPath::forMethod('App', '{anonymous#0}', 'nested'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $result = $this->processLiteralAst(
            $ast,
            [new ClassWithMetrics($outerDeclaration, $outer->getStartFilePos(), $outer->getStartLine(), new MetricBag())],
            [
                new CallableWithMetrics($methodDeclaration, $method->getStartFilePos(), CallableKind::Method, null, $outerDeclaration, new LogicalClassPath(SymbolPath::forClass('App', 'Outer')), new MetricBag(), $method->getStartLine()),
                new CallableWithMetrics($closureDeclaration, $closure->getStartFilePos(), CallableKind::AnonymousCallable, 'closure', $outerDeclaration, null, new MetricBag(), $closure->getStartLine()),
                new CallableWithMetrics($nestedDeclaration, $nestedMethod->getStartFilePos(), CallableKind::Method, null, $anonymousDeclaration, null, new MetricBag(), $nestedMethod->getStartLine()),
            ],
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame(
            [$outerDeclaration->toCanonical(), $methodDeclaration->toCanonical(), $closureDeclaration->toCanonical()],
            array_map(static fn($override) => $override->subject->toCanonical(), $result->thresholdOverrides()),
        );
    }

    #[Test]
    public function itBindsNestedNamedClassPropertyAndConstantControlsToTheInnermostClass(): void
    {
        $ast = $this->parseLiteral(<<<'PHP'
            <?php
            namespace App;

            class Outer
            {
                public function define(): void
                {
                    class Inner
                    {
                        /** @qmx-threshold complexity.cyclomatic broken */
                        public int $value;

                        /** @qmx-ignore complexity.cyclomatic inner constant */
                        public const FLAG = 1;
                    }
                }
            }
            PHP);
        $classes = (new NodeFinder())->findInstanceOf($ast, Node\Stmt\Class_::class);
        $outer = $classes[0] ?? throw new LogicException('Missing outer class');
        $inner = $classes[1] ?? throw new LogicException('Missing inner class');
        $outerDeclaration = DeclarationPath::of(SymbolPath::forClass('App', 'Outer'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $innerDeclaration = DeclarationPath::of(SymbolPath::forClass('App', 'Inner'), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
        $result = $this->processLiteralAst(
            $ast,
            [
                new ClassWithMetrics($outerDeclaration, $outer->getStartFilePos(), $outer->getStartLine(), new MetricBag()),
                new ClassWithMetrics($innerDeclaration, $inner->getStartFilePos(), $inner->getStartLine(), new MetricBag()),
            ],
        );

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->thresholdDiagnostics());
        self::assertSame($innerDeclaration->toCanonical(), $result->thresholdDiagnostics()[0]->subject->toCanonical());
        self::assertCount(1, $result->suppressions());
        self::assertSame($innerDeclaration->toCanonical(), $result->suppressions()[0]->subject?->toCanonical());
    }

    /** @return list<Node> */
    private function parseLiteral(string $source): array
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        self::assertIsArray($ast);

        return array_values($ast);
    }

    /**
     * @template T of Node
     *
     * @param list<Node> $ast
     * @param class-string<T> $class
     *
     * @return T
     */
    private function singleNode(array $ast, string $class): Node
    {
        $node = (new NodeFinder())->findFirstInstanceOf($ast, $class);
        self::assertInstanceOf($class, $node);

        return $node;
    }

    /**
     * @param list<Node> $ast
     * @param list<ClassWithMetrics> $classes
     * @param list<CallableWithMetrics> $callables
     */
    private function processLiteralAst(array $ast, array $classes = [], array $callables = []): \Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult
    {
        $this->parser->method('parse')->willReturn($ast);
        $collectors = [];
        if ($classes !== []) {
            $collectors[] = $this->createMockCollectorWithClassMetrics($classes);
        }
        if ($callables !== []) {
            $collectors[] = $this->createMockCollectorWithMethodMetrics($callables);
        }

        return $this->makeProcessor(new CompositeCollector($collectors, new DeclarationRegistrarFactory()))->process(new SplFileInfo('/tmp/test.php'));
    }

    /**
     * @param list<CallableWithMetrics> $methods
     */
    private function createMockCollectorWithMethodMetrics(array $methods): MetricCollectorInterface&CallableMetricsProviderInterface
    {
        $collector = new class ($methods) implements MetricCollectorInterface, CallableMetricsProviderInterface {
            /** @param list<CallableWithMetrics> $methods */
            public function __construct(private readonly array $methods) {}

            public function getName(): string
            {
                return 'test-method-collector';
            }

            public function provides(): array
            {
                return ['ccn'];
            }

            /** @return list<\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition> */
            public function getMetricDefinitions(): array
            {
                return [];
            }

            public function getVisitor(): \PhpParser\NodeVisitorAbstract
            {
                return new class extends \PhpParser\NodeVisitorAbstract {};
            }

            public function collect(SplFileInfo $file, array $ast): MetricBag
            {
                return new MetricBag();
            }

            public function reset(): void {}

            /** @return list<CallableWithMetrics> */
            public function getCallablesWithMetrics(RelativePath $file): array
            {
                return $this->methods;
            }
        };

        return $collector;
    }

    /**
     * @param list<ClassWithMetrics> $classes
     */
    private function createMockCollectorWithClassMetrics(array $classes): MetricCollectorInterface&ClassMetricsProviderInterface
    {
        $collector = new class ($classes) implements MetricCollectorInterface, ClassMetricsProviderInterface {
            /** @param list<ClassWithMetrics> $classes */
            public function __construct(private readonly array $classes) {}

            public function getName(): string
            {
                return 'test-class-collector';
            }

            public function provides(): array
            {
                return ['wmc'];
            }

            /** @return list<\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition> */
            public function getMetricDefinitions(): array
            {
                return [];
            }

            public function getVisitor(): \PhpParser\NodeVisitorAbstract
            {
                return new class extends \PhpParser\NodeVisitorAbstract {};
            }

            public function collect(SplFileInfo $file, array $ast): MetricBag
            {
                return new MetricBag();
            }

            public function reset(): void {}

            /** @return list<ClassWithMetrics> */
            public function getClassesWithMetrics(RelativePath $file): array
            {
                return $this->classes;
            }
        };

        return $collector;
    }

    /**
     * @param list<NamespaceWithMetrics> $namespaces
     */
    private function createMockCollectorWithNamespaceMetrics(
        array $namespaces,
    ): MetricCollectorInterface&NamespaceMetricProviderInterface {
        return new class ($namespaces) implements MetricCollectorInterface, NamespaceMetricProviderInterface {
            /** @param list<NamespaceWithMetrics> $namespaces */
            public function __construct(private readonly array $namespaces) {}

            public function getName(): string
            {
                return 'test-namespace-collector';
            }

            public function provides(): array
            {
                return ['loc'];
            }

            public function getMetricDefinitions(): array
            {
                return [];
            }

            public function getVisitor(): NodeVisitorAbstract
            {
                return new class extends NodeVisitorAbstract {};
            }

            public function collect(SplFileInfo $file, array $ast): MetricBag
            {
                return new MetricBag();
            }

            public function reset(): void {}

            public function getNamespacesWithMetrics(): array
            {
                return $this->namespaces;
            }
        };
    }
}
