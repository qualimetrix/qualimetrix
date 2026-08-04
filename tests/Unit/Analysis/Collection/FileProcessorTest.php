<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Collection;

use LogicException;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Dependency\DependencyResolver;
use Qualimetrix\Analysis\Collection\Dependency\DependencyVisitor;
use Qualimetrix\Analysis\Collection\FileProcessor;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Exception\ParseException;
use Qualimetrix\Core\Metric\ClassMetricsProviderInterface;
use Qualimetrix\Core\Metric\ClassWithMetrics;
use Qualimetrix\Core\Metric\MethodMetricsProviderInterface;
use Qualimetrix\Core\Metric\MethodWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricCollectorInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Suppression\SuppressionType;
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
        $processor = new FileProcessor($this->parser, $collector);
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
        $processor = new FileProcessor($this->parser, new CompositeCollector([]));

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

        $compositeCollector = new CompositeCollector([$collector]);

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        self::assertTrue($result->success);
        self::assertSame('test.php', $result->filePath->value());
        self::assertSame(50, $result->fileBag?->get('loc'));
        self::assertNull($result->error);
    }

    #[Test]
    public function itReturnsFailureOnParseException(): void
    {
        $file = new SplFileInfo('/tmp/invalid.php');

        $this->parser->method('parse')->willThrowException(
            new ParseException(AbsolutePath::fromString('/tmp/invalid.php'), 'Syntax error'),
        );

        $compositeCollector = new CompositeCollector([]);

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        self::assertFalse($result->success);
        self::assertSame('invalid.php', $result->filePath->value());
        self::assertNull($result->fileBag);
        self::assertStringContainsString('Syntax error', $result->error ?? '');
    }

    #[Test]
    public function itExtractsMethodMetricsFromCollectors(): void
    {
        $file = new SplFileInfo('/tmp/test.php');

        $this->parser->method('parse')->willReturn([]);

        $symbolPath = SymbolPath::forMethod('App', 'Service', 'calculate');
        $methodBag = MetricBag::fromArray(['ccn' => 5]);

        $methodWithMetrics = new MethodWithMetrics(
            namespace: 'App',
            class: 'Service',
            method: 'calculate',
            line: 15,
            metrics: $methodBag,
        );

        // Create a mock that implements both interfaces
        $collector = $this->createMockCollectorWithMethodMetrics([$methodWithMetrics]);

        $compositeCollector = new CompositeCollector([$collector]);

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        self::assertTrue($result->success);
        self::assertCount(1, $result->methodMetrics);
        self::assertArrayHasKey('App::Service::calculate', $result->methodMetrics);
        self::assertSame(5, $result->methodMetrics['App::Service::calculate']['metrics']->get('ccn'));
        self::assertSame(15, $result->methodMetrics['App::Service::calculate']['line']);
    }

    #[Test]
    public function itExtractsClassMetricsFromCollectors(): void
    {
        $file = new SplFileInfo('/tmp/test.php');

        $this->parser->method('parse')->willReturn([]);

        $symbolPath = SymbolPath::forClass('App', 'Service');
        $classBag = MetricBag::fromArray(['wmc' => 25]);

        $classWithMetrics = new ClassWithMetrics(
            namespace: 'App',
            class: 'Service',
            line: 5,
            metrics: $classBag,
        );

        $collector = $this->createMockCollectorWithClassMetrics([$classWithMetrics]);

        $compositeCollector = new CompositeCollector([$collector]);

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        self::assertTrue($result->success);
        self::assertCount(1, $result->classMetrics);
        self::assertArrayHasKey('App::Service', $result->classMetrics);
        self::assertSame(25, $result->classMetrics['App::Service']['metrics']->get('wmc'));
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

        $compositeCollector = new CompositeCollector([]);
        $compositeCollector->setDependencyVisitor($dependencyVisitor);

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        // With empty AST, no dependencies should be collected
        self::assertTrue($result->success);
        self::assertCount(0, $result->dependencies);
    }

    #[Test]
    public function itSkipsClosuresWithoutStableIdentity(): void
    {
        $file = new SplFileInfo('/tmp/test.php');

        $this->parser->method('parse')->willReturn([]);

        // Method without stable identity (closure)
        $methodWithMetrics = new MethodWithMetrics(
            namespace: null,
            class: null,
            method: '{closure:0}',
            line: 15,
            metrics: MetricBag::fromArray(['ccn' => 3]),
        );

        $collector = $this->createMockCollectorWithMethodMetrics([$methodWithMetrics]);

        $compositeCollector = new CompositeCollector([$collector]);

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        self::assertTrue($result->success);
        self::assertCount(0, $result->methodMetrics); // Closures skipped
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

        $compositeCollector = new CompositeCollector([]);

        $processor = $this->makeProcessor($compositeCollector);
        $result = $processor->process($file);

        self::assertTrue($result->success);
        self::assertNotEmpty($result->suppressions);

        $nextLineSuppressions = array_filter(
            $result->suppressions,
            static fn($s) => $s->type === SuppressionType::NextLine && $s->rule === 'code-smell.exit',
        );
        self::assertCount(1, $nextLineSuppressions);
    }

    #[Test]
    public function itCollectsClassAndNestedMethodThresholdOverrides(): void
    {
        $file = new SplFileInfo('/tmp/test.php');

        $method = new Node\Stmt\ClassMethod('run', attributes: ['startLine' => 10, 'endLine' => 15]);
        $method->setDocComment(new Doc(
            '/** @qmx-threshold complexity.cyclomatic warning=40 error=50 */',
            startLine: 9,
            endLine: 9,
        ));

        $class = new Node\Stmt\Class_('MyClass', ['stmts' => [$method]], ['startLine' => 5, 'endLine' => 20]);
        $class->setDocComment(new Doc(
            '/** @qmx-threshold complexity.cyclomatic warning=20 error=30 */',
            startLine: 4,
            endLine: 4,
        ));

        $this->parser->method('parse')->willReturn([$class]);

        $processor = $this->makeProcessor(new CompositeCollector([]));
        $result = $processor->process($file);

        self::assertTrue($result->success);
        self::assertSame([], $result->thresholdDiagnostics);
        self::assertCount(2, $result->thresholdOverrides);

        $classOverride = $result->thresholdOverrides[0];
        self::assertSame('complexity.cyclomatic', $classOverride->rulePattern);
        self::assertSame(20, $classOverride->warning);
        self::assertSame(30, $classOverride->error);
        self::assertSame(4, $classOverride->line);
        self::assertSame(20, $classOverride->endLine);

        $methodOverride = $result->thresholdOverrides[1];
        self::assertSame('complexity.cyclomatic', $methodOverride->rulePattern);
        self::assertSame(40, $methodOverride->warning);
        self::assertSame(50, $methodOverride->error);
        self::assertSame(9, $methodOverride->line);
        self::assertSame(15, $methodOverride->endLine);
    }

    /**
     * @param list<MethodWithMetrics> $methods
     */
    private function createMockCollectorWithMethodMetrics(array $methods): MetricCollectorInterface&MethodMetricsProviderInterface
    {
        $collector = new class ($methods) implements MetricCollectorInterface, MethodMetricsProviderInterface {
            /** @param list<MethodWithMetrics> $methods */
            public function __construct(private readonly array $methods) {}

            public function getName(): string
            {
                return 'test-method-collector';
            }

            public function provides(): array
            {
                return ['ccn'];
            }

            /** @return list<\Qualimetrix\Core\Metric\MetricDefinition> */
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

            /** @return list<MethodWithMetrics> */
            public function getMethodsWithMetrics(): array
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

            /** @return list<\Qualimetrix\Core\Metric\MetricDefinition> */
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
            public function getClassesWithMetrics(): array
            {
                return $this->classes;
            }
        };

        return $collector;
    }
}
