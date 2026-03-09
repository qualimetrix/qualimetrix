<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\CodeSmell;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\CodeSmell\UnreachableCodeCollector;
use AiMessDetector\Metrics\CodeSmell\UnreachableCodeVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(UnreachableCodeCollector::class)]
#[CoversClass(UnreachableCodeVisitor::class)]
final class UnreachableCodeCollectorTest extends TestCase
{
    private UnreachableCodeCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new UnreachableCodeCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('unreachable-code', $this->collector->getName());
    }

    public function testProvides(): void
    {
        self::assertSame(['unreachableCode', 'unreachableCode.firstLine'], $this->collector->provides());
    }

    public function testNoUnreachableCode(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

class Calculator
{
    public function add(int $a, int $b): int
    {
        $result = $a + $b;
        return $result;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unreachableCode:App\Service\Calculator::add'));
        self::assertNull($metrics->get('unreachableCode.firstLine:App\Service\Calculator::add'));
    }

    public function testReturnFollowedByCode(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
        $unused = 42;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('unreachableCode:App\Service\Calculator::add'));
        self::assertSame(10, $metrics->get('unreachableCode.firstLine:App\Service\Calculator::add'));
    }

    public function testThrowFollowedByCode(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

class Validator
{
    public function validate(mixed $value): void
    {
        throw new \RuntimeException('Invalid');
        $this->log('unreachable');
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('unreachableCode:App\Service\Validator::validate'));
    }

    public function testExitFollowedByCode(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Runner
{
    public function run(): void
    {
        exit;
        echo 'unreachable';
    }

    public function runDie(): void
    {
        die();
        echo 'also unreachable';
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('unreachableCode:App\Runner::run'));
        self::assertSame(1, $metrics->get('unreachableCode:App\Runner::runDie'));
    }

    public function testContinueFollowedByCode(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Processor
{
    public function process(array $items): void
    {
        foreach ($items as $item) {
            if ($item === null) {
                continue;
                $this->log('skipped');
            }
        }
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // continue is inside an if block, not at the top-level of the method
        // The top-level method body has: foreach — no unreachable code
        self::assertSame(0, $metrics->get('unreachableCode:App\Processor::process'));
    }

    public function testBreakFollowedByCode(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Finder
{
    public function find(array $items): mixed
    {
        foreach ($items as $item) {
            if ($item === 'target') {
                break;
                $this->log('found');
            }
        }
        return null;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // break is inside an if block, not at top-level of the method
        self::assertSame(0, $metrics->get('unreachableCode:App\Finder::find'));
    }

    public function testMultipleUnreachableStatements(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Service
{
    public function execute(): void
    {
        return;
        $a = 1;
        $b = 2;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('unreachableCode:App\Service::execute'));
        self::assertSame(10, $metrics->get('unreachableCode.firstLine:App\Service::execute'));
    }

    public function testReturnWithinIfDoesNotMakeCodeAfterIfUnreachable(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Guard
{
    public function check(bool $condition): int
    {
        if ($condition) {
            return 1;
        }
        return 0;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unreachableCode:App\Guard::check'));
    }

    public function testOnlyReturnNoCodeAfter(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Simple
{
    public function getValue(): int
    {
        return 42;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unreachableCode:App\Simple::getValue'));
    }

    public function testReset(): void
    {
        $code1 = <<<'PHP'
<?php

namespace App;

class First
{
    public function method(): void
    {
        return;
        $x = 1;
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
        $y = 2;
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
        self::assertNull($metrics->get('unreachableCode:App\First::method'));
        self::assertSame(0, $metrics->get('unreachableCode:App\Second::otherMethod'));
    }

    public function testGetMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(2, $definitions);

        // First definition: unreachableCode count
        $definition = $definitions[0];
        self::assertSame('unreachableCode', $definition->name);
        self::assertSame(SymbolLevel::Method, $definition->collectedAt);

        // Check Class_ level aggregations
        $classStrategies = $definition->getStrategiesForLevel(SymbolLevel::Class_);
        self::assertCount(1, $classStrategies);
        self::assertContains(AggregationStrategy::Sum, $classStrategies);

        // Check Namespace_ level aggregations
        $namespaceStrategies = $definition->getStrategiesForLevel(SymbolLevel::Namespace_);
        self::assertCount(1, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Sum, $namespaceStrategies);

        // Check Project level aggregations
        $projectStrategies = $definition->getStrategiesForLevel(SymbolLevel::Project);
        self::assertCount(1, $projectStrategies);
        self::assertContains(AggregationStrategy::Sum, $projectStrategies);

        // Second definition: unreachableCode.firstLine (no aggregations)
        $firstLineDefinition = $definitions[1];
        self::assertSame('unreachableCode.firstLine', $firstLineDefinition->name);
        self::assertSame(SymbolLevel::Method, $firstLineDefinition->collectedAt);
        self::assertEmpty($firstLineDefinition->getStrategiesForLevel(SymbolLevel::Class_));
        self::assertEmpty($firstLineDefinition->getStrategiesForLevel(SymbolLevel::Namespace_));
        self::assertEmpty($firstLineDefinition->getStrategiesForLevel(SymbolLevel::Project));
    }

    public function testGlobalFunction(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Utils;

function helper(): int
{
    return 1;
    $unused = 2;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('unreachableCode:App\Utils\helper'));
        self::assertSame(8, $metrics->get('unreachableCode.firstLine:App\Utils\helper'));
    }

    public function testGotoFollowedByCode(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Navigator
{
    public function navigate(): void
    {
        goto end;
        $x = 1;
        end:
        return;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // goto IS terminal — code after goto is unreachable in sequential flow.
        // The label resets reachability (it's a valid jump target), so only $x = 1 is unreachable.
        // After the label, return is reachable via the goto jump.
        self::assertSame(1, $metrics->get('unreachableCode:App\Navigator::navigate'));
    }

    public function testGotoAsTerminalSimple(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class GotoTest
{
    public function test(): void
    {
        goto skip;
        $unreachable = 1;
        skip:
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // goto is terminal: $unreachable = 1 is after it, but skip: label resets reachability
        self::assertSame(1, $metrics->get('unreachableCode:App\GotoTest::test'));
        self::assertSame(10, $metrics->get('unreachableCode.firstLine:App\GotoTest::test'));
    }

    public function testAnonymousClassInsideNamedClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Outer
{
    public function before(): void
    {
        return;
        $dead = 1;
    }

    public function factory(): object
    {
        return new class {
            public function inner(): void {}
        };
    }

    public function after(): void
    {
        return;
        $dead = 2;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Methods of Outer should have correct FQN with class context preserved
        self::assertSame(1, $metrics->get('unreachableCode:App\Outer::before'));
        self::assertSame(0, $metrics->get('unreachableCode:App\Outer::factory'));
        self::assertSame(1, $metrics->get('unreachableCode:App\Outer::after'));

        // Anonymous class methods should NOT appear in metrics
        self::assertNull($metrics->get('unreachableCode:App\Outer::inner'));
    }

    /**
     * Comments after return should not be counted as unreachable code.
     * PHP parser represents standalone comments as Stmt\Nop nodes.
     */
    public function testCommentAfterReturnIsNotUnreachable(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Service
{
    public function execute(): int
    {
        return 42;
        // This is just a comment
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('unreachableCode:App\Service::execute'));
    }

    /**
     * A goto label resets reachability — code after the label is reachable via the goto jump.
     */
    public function testGotoLabelResetsReachability(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class GotoLabel
{
    public function test(): void
    {
        goto done;
        $unreachable1 = 1;
        $unreachable2 = 2;
        done:
        $reachable = 3;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Only $unreachable1 and $unreachable2 are unreachable.
        // The label resets reachability, so $reachable = 3 is reachable.
        self::assertSame(2, $metrics->get('unreachableCode:App\GotoLabel::test'));
        self::assertSame(10, $metrics->get('unreachableCode.firstLine:App\GotoLabel::test'));
    }

    /**
     * Multiple goto labels: each label resets reachability independently.
     */
    public function testMultipleGotoLabelsResetReachability(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MultiLabel
{
    public function test(): void
    {
        goto first;
        $dead1 = 1;
        first:
        $alive1 = 2;
        goto second;
        $dead2 = 3;
        second:
        $alive2 = 4;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // $dead1 and $dead2 are unreachable, labels reset reachability
        self::assertSame(2, $metrics->get('unreachableCode:App\MultiLabel::test'));
    }

    /**
     * Code after goto with no subsequent label remains unreachable.
     */
    public function testGotoWithoutSubsequentLabel(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class GotoNoLabel
{
    public function test(): void
    {
        label:
        $x = 1;
        goto label;
        $dead = 2;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // $dead = 2 is unreachable (no label after it to reset)
        self::assertSame(1, $metrics->get('unreachableCode:App\GotoNoLabel::test'));
    }

    private function collectMetrics(string $code): \AiMessDetector\Core\Metric\MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->collect(new SplFileInfo(__FILE__), $ast);
    }
}
