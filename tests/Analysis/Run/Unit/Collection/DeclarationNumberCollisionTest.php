<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Collection;

use LogicException;
use PhpParser\NodeVisitorAbstract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Analysis\Policy\Inline\Extraction\SourceControlExtractor;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/**
 * One canonical key must mean one declaration.
 *
 * A producer that put its own number on a declaration instead of asking the
 * index shows up here and nowhere earlier: the merge that keys these records
 * is where two different declarations first meet under one key, and the file
 * positions they were collected at are what tells them apart.
 */
#[CoversClass(FileProcessor::class)]
final class DeclarationNumberCollisionTest extends TestCase
{
    private FileParserInterface&Stub $parser;

    protected function setUp(): void
    {
        $this->parser = self::createStub(FileParserInterface::class);
        $this->parser->method('parse')->willReturn([]);
    }

    #[Test]
    public function itRejectsTwoCallableDeclarationsSharingOneKey(): void
    {
        $path = DeclarationPath::of(
            SymbolPath::forMethod('App', 'Greeter', 'greet'),
            RelativePath::fromString('src/Dup.php'),
            DeclarationOrdinal::fromRank(0),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('was collected at file positions 40 and 900');

        $this->process(self::callableCollector([
            new CallableWithMetrics($path, 40, CallableKind::Method, null, null, null, MetricBag::fromArray(['ccn' => 1]), 3),
            new CallableWithMetrics($path, 900, CallableKind::Method, null, null, null, MetricBag::fromArray(['ccn' => 2]), 3),
        ]));
    }

    #[Test]
    public function itRejectsTwoClassDeclarationsSharingOneKey(): void
    {
        $path = DeclarationPath::of(
            SymbolPath::forClass('App', 'Greeter'),
            RelativePath::fromString('src/Dup.php'),
            DeclarationOrdinal::fromRank(0),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('was collected at file positions 40 and 900');

        $this->process(self::classCollector([
            new ClassWithMetrics($path, 40, 3, MetricBag::fromArray(['wmc' => 1])),
            new ClassWithMetrics($path, 900, 3, MetricBag::fromArray(['wmc' => 2])),
        ]));
    }

    private function process(MetricCollectorInterface $collector): \Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult
    {
        $processor = new FileProcessor(
            $this->parser,
            new CompositeCollector([$collector], new DeclarationRegistrarFactory()),
            new SourceControlExtractor(),
        );
        $processor->setProjectRoot(AbsolutePath::fromString('/tmp'));

        return $processor->process(new SplFileInfo('/tmp/Dup.php'));
    }

    /** @param list<CallableWithMetrics> $callables */
    private static function callableCollector(array $callables): MetricCollectorInterface&CallableMetricsProviderInterface
    {
        return new class ($callables) implements MetricCollectorInterface, CallableMetricsProviderInterface {
            /** @param list<CallableWithMetrics> $callables */
            public function __construct(private readonly array $callables) {}

            public function getName(): string
            {
                return 'collision-callable-collector';
            }

            /** @return list<string> */
            public function provides(): array
            {
                return ['ccn'];
            }

            /** @return list<\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition> */
            public function getMetricDefinitions(): array
            {
                return [];
            }

            public function getVisitor(): NodeVisitorAbstract
            {
                return new class extends NodeVisitorAbstract {};
            }

            /** @param array<\PhpParser\Node> $ast */
            public function collect(SplFileInfo $file, array $ast): MetricBag
            {
                return new MetricBag();
            }

            public function reset(): void {}

            /** @return list<CallableWithMetrics> */
            public function getCallablesWithMetrics(RelativePath $file): array
            {
                return $this->callables;
            }
        };
    }

    /** @param list<ClassWithMetrics> $classes */
    private static function classCollector(array $classes): MetricCollectorInterface&ClassMetricsProviderInterface
    {
        return new class ($classes) implements MetricCollectorInterface, ClassMetricsProviderInterface {
            /** @param list<ClassWithMetrics> $classes */
            public function __construct(private readonly array $classes) {}

            public function getName(): string
            {
                return 'collision-class-collector';
            }

            /** @return list<string> */
            public function provides(): array
            {
                return ['wmc'];
            }

            /** @return list<\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition> */
            public function getMetricDefinitions(): array
            {
                return [];
            }

            public function getVisitor(): NodeVisitorAbstract
            {
                return new class extends NodeVisitorAbstract {};
            }

            /** @param array<\PhpParser\Node> $ast */
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
    }
}
