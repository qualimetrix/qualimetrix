<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Complexity\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\CognitiveComplexityCollector;
use Qualimetrix\Analysis\Evidence\Complexity\CognitiveComplexityVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Core\Symbol\SymbolLevel;
use SplFileInfo;

#[CoversClass(CognitiveComplexityCollector::class)]
#[CoversClass(CognitiveComplexityVisitor::class)]
final class CognitiveComplexityCollectorTest extends TestCase
{
    private CognitiveComplexityCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new CognitiveComplexityCollector();
    }

    #[Test]
    public function itGetsName(): void
    {
        self::assertSame('cognitive-complexity', $this->collector->getName());
    }

    #[Test]
    public function itProvides(): void
    {
        self::assertSame(['complexity.cognitive'], $this->collector->provides());
    }

    #[Test]
    public function itMeasuresGlobalFunctions(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Utils;

function validate(mixed $value): bool
{
    if ($value === null) {
        return false;
    }
    return true;
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (if)
        self::assertSame(1, $metrics->get('complexity.cognitive:App\Utils\validate'));
    }

    #[Test]
    public function itMeasuresGlobalFunctionsWithoutNamespace(): void
    {
        $code = <<<'PHP'
<?php

function globalHelper(): void
{
    if (true) {
        // do something
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (if)
        self::assertSame(1, $metrics->get('complexity.cognitive:globalHelper'));
    }

    #[Test]
    public function itMeasuresClosures(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class ClosureTest
{
    public function withClosure(): callable
    {
        return function (int $x): int {
            if ($x > 0) {
                return $x * 2;
            }
            return $x;
        };
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // The closure adds nothing to the method; its own if runs at nesting=1
        self::assertSame(0, $metrics->get('complexity.cognitive:App\ClosureTest::withClosure'));
        self::assertSame(2, $metrics->get('complexity.cognitive:App\ClosureTest::{closure#1}'));
    }

    #[Test]
    public function itMeasuresMultipleMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MultiMethod
{
    public function simple(): void
    {
    }

    public function withIf(): void
    {
        if (true) {}
    }

    public function withLoop(): void
    {
        foreach ([] as $item) {}
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('complexity.cognitive:App\MultiMethod::simple'));
        self::assertSame(1, $metrics->get('complexity.cognitive:App\MultiMethod::withIf'));
        self::assertSame(1, $metrics->get('complexity.cognitive:App\MultiMethod::withLoop'));
    }

    #[Test]
    public function itResetsState(): void
    {
        $code1 = <<<'PHP'
<?php

namespace App;

class First
{
    public function method(): void
    {
        if (true) {}
    }
}
PHP;

        $code2 = <<<'PHP'
<?php

namespace App;

class Second
{
    public function otherMethod(): void
    {
    }
}
PHP;

        // Collect first file
        $this->collectMetrics($code1);

        // Reset
        $this->collector->reset();

        // Collect second file
        $metrics = $this->collectMetrics($code2);

        // Should only contain metrics from second file
        self::assertNull($metrics->get('complexity.cognitive:App\First::method'));
        self::assertSame(0, $metrics->get('complexity.cognitive:App\Second::otherMethod'));
    }

    #[Test]
    public function itMeasuresComplexMethod(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

class ComplexService
{
    public function process(array $items, bool $validate): array
    {
        $result = [];

        if (empty($items)) {                            // +1
            return $result;
        }

        foreach ($items as $key => $item) {             // +1
            if ($validate && !$this->isValid($item)) {  // +2 (nesting=1) + 1 (logical) = 3
                continue;
            }

            try {
                $value = $item['value'] ?? 0;           // +0 (null coalescing is ignored)

                if ($value > 100 || $value < 0) {       // +2 (nesting=1) + 1 (logical) = 3
                    throw new \InvalidArgumentException('Invalid value');
                }

                $result[$key] = $value > 50 ? 'high' : 'low'; // +2 (nesting=1)
            } catch (\InvalidArgumentException $e) {    // +2 (nesting=1)
                $result[$key] = 'error';
            } catch (\RuntimeException $e) {            // +2 (nesting=1)
                $result[$key] = 'runtime_error';
            }
        }

        return $result;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (if empty) + 1 (foreach) + 2 (if validate) + 1 (&&)
        //           + 2 (if value) + 1 (||) + 2 (ternary) + 2 (first catch) + 2 (second catch)
        //           = 14
        self::assertSame(14, $metrics->get('complexity.cognitive:App\Service\ComplexService::process'));
    }

    #[Test]
    public function itCountsRecursiveCall(): void
    {
        $code = <<<'PHP'
<?php

function factorial(int $n): int
{
    if ($n <= 1) {
        return 1;
    }
    return $n * factorial($n - 1);
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Cognitive = +1 (if) + 1 (recursive call) = 2
        self::assertSame(2, $metrics->get('complexity.cognitive:factorial'));
    }

    #[Test]
    public function itProvidesMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);

        $cognitiveDefinition = $definitions[0];
        self::assertSame('complexity.cognitive', $cognitiveDefinition->name);
        self::assertSame(SymbolLevel::Callable, $cognitiveDefinition->collectedAt);

        // Check Class_ level aggregations
        $classStrategies = $cognitiveDefinition->getStrategiesForLevel(SymbolLevel::Class_);
        self::assertCount(3, $classStrategies);
        self::assertContains(AggregationStrategy::Sum, $classStrategies);
        self::assertContains(AggregationStrategy::Average, $classStrategies);
        self::assertContains(AggregationStrategy::Max, $classStrategies);

        // Check Namespace_ level aggregations
        $namespaceStrategies = $cognitiveDefinition->getStrategiesForLevel(SymbolLevel::Namespace_);
        self::assertCount(4, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Sum, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Average, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Max, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Percentile95, $namespaceStrategies);

        // Check Project level aggregations
        $projectStrategies = $cognitiveDefinition->getStrategiesForLevel(SymbolLevel::Project);
        self::assertCount(4, $projectStrategies);
        self::assertContains(AggregationStrategy::Sum, $projectStrategies);
        self::assertContains(AggregationStrategy::Average, $projectStrategies);
        self::assertContains(AggregationStrategy::Max, $projectStrategies);
        self::assertContains(AggregationStrategy::Percentile95, $projectStrategies);
    }

    private function collectMetrics(string $code): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $indexAwareVisitor = $this->collector->getVisitor();
        self::assertInstanceOf(DeclarationIndexAwareInterface::class, $indexAwareVisitor);
        $indexAwareVisitor->useDeclarationIndex($registrar->index());
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->collect(new SplFileInfo(__FILE__), $ast);
    }
}
