<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Rules;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Suppression\ControlScope;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

final class PropertyHookControlPrecedenceTest extends TestCase
{
    #[Test]
    public function itUsesProductionExtractionForHookPropertyAndClassThresholdPrecedence(): void
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse(<<<'PHP'
            <?php
            namespace App;

            /** @qmx-threshold complexity.cyclomatic 10 */
            class Record
            {
                /** @qmx-threshold complexity.cyclomatic 30 */
                public int $value {
                    /** @qmx-threshold complexity.cyclomatic 50 */
                    get => 1;
                }
            }
            PHP);
        self::assertIsArray($ast);

        $finder = new NodeFinder();
        $class = $finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        $hook = $finder->findFirstInstanceOf($ast, Node\PropertyHook::class);
        self::assertInstanceOf(Node\Stmt\Class_::class, $class);
        self::assertInstanceOf(Node\PropertyHook::class, $hook);

        $classDeclaration = new DeclarationPath(SymbolPath::forClass('App', 'Record'), RelativePath::fromString('test.php'), $class->getStartFilePos());
        $hookDeclaration = new DeclarationPath(SymbolPath::forMethod('App', 'Record', 'value::get'), RelativePath::fromString('test.php'), $hook->getStartFilePos());
        $classMetric = new ClassWithMetrics($classDeclaration, $class->getStartLine(), new MetricBag());
        $hookMetric = new CallableWithMetrics(
            $hookDeclaration,
            CallableKind::PropertyHook,
            null,
            $classDeclaration,
            new LogicalClassPath(SymbolPath::forClass('App', 'Record')),
            new MetricBag(),
            $hook->getStartLine(),
        );
        $processor = new FileProcessor(
            new class (array_values($ast)) implements FileParserInterface {
                /** @param list<Node> $ast */
                public function __construct(private array $ast) {}

                public function parse(SplFileInfo $file): array
                {
                    return $this->ast;
                }

                public function parseContent(SplFileInfo $file, string $content): array
                {
                    return $this->ast;
                }
            },
            new CompositeCollector([
                new class ($classMetric, $hookMetric) implements MetricCollectorInterface, ClassMetricsProviderInterface, CallableMetricsProviderInterface {
                    public function __construct(
                        private ClassWithMetrics $classMetric,
                        private CallableWithMetrics $hookMetric,
                    ) {}

                    public function getName(): string
                    {
                        return 'literal-hook-metrics';
                    }

                    public function provides(): array
                    {
                        return [];
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

                    public function getClassesWithMetrics(RelativePath $file): array
                    {
                        return [$this->classMetric];
                    }

                    public function getCallablesWithMetrics(RelativePath $file): array
                    {
                        return [$this->hookMetric];
                    }
                },
            ]),
        );
        $processor->setProjectRoot(AbsolutePath::fromString('/tmp'));

        $result = $processor->process(new SplFileInfo('/tmp/test.php'));

        self::assertTrue($result->isSuccessful());
        self::assertSame([], $result->thresholdDiagnostics());
        self::assertCount(4, $result->thresholdOverrides());
        $hookControls = array_values(array_filter(
            $result->thresholdOverrides(),
            static fn($override) => $override->subject->toCanonical() === $hookDeclaration->toCanonical(),
        ));
        self::assertSame([ControlScope::Class_, ControlScope::Property, ControlScope::Hook], array_map(
            static fn($override) => $override->controlScope,
            $hookControls,
        ));

        $subject = MetricSubject::declaration($hookDeclaration);
        self::assertSame(50, (new AnalysisContext(
            self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: ['test.php' => $hookControls],
        ))->getThresholdOverride('complexity.cyclomatic', $subject)?->warning);
        self::assertSame(30, (new AnalysisContext(
            self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: ['test.php' => array_values(array_filter($hookControls, static fn($override) => $override->controlScope !== ControlScope::Hook))],
        ))->getThresholdOverride('complexity.cyclomatic', $subject)?->warning);
        self::assertSame(10, (new AnalysisContext(
            self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: ['test.php' => array_values(array_filter($hookControls, static fn($override) => $override->controlScope === ControlScope::Class_))],
        ))->getThresholdOverride('complexity.cyclomatic', $subject)?->warning);
    }
}
