<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\CodeSmell;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\CodeSmell\UnreachableCodeCollector;
use Qualimetrix\Metrics\CodeSmell\UnreachableCodeVisitor;
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

    #[Test]
    public function itReturnsCollectorName(): void
    {
        self::assertSame('unreachable-code', $this->collector->getName());
    }

    #[Test]
    public function itProvidesExpectedMetricKeys(): void
    {
        self::assertSame(['unreachableCode', 'unreachableCode.firstLine'], $this->collector->provides());
    }

    #[Test]
    public function itProducesZeroForMethodWithNoUnreachableCode(): void
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

    #[Test]
    public function itDetectsCodeAfterReturn(): void
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

    #[Test]
    public function itDetectsCodeAfterThrow(): void
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

    #[Test]
    public function itDetectsCodeAfterExit(): void
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

    #[Test]
    public function itDoesNotCountCodeAfterContinueInsideIfBlock(): void
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

    #[Test]
    public function itDoesNotCountCodeAfterBreakInsideIfBlock(): void
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

    #[Test]
    public function itCountsMultipleUnreachableStatements(): void
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

    #[Test]
    public function itDoesNotMarkCodeAfterIfUnreachableWhenReturnIsInsideIf(): void
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

    #[Test]
    public function itProducesZeroWhenOnlyReturnWithNoCodeAfter(): void
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

    #[Test]
    public function itClearsStateOnReset(): void
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

    #[Test]
    public function itReturnsCorrectMetricDefinitions(): void
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

    #[Test]
    public function itDetectsUnreachableCodeInGlobalFunction(): void
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

    #[Test]
    public function itDetectsCodeAfterGoto(): void
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

    #[Test]
    public function itTreatsGotoAsTerminalAndDetectsUnreachableStatement(): void
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

    #[Test]
    public function itSkipsAnonymousClassMethodsInsideNamedClass(): void
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
    #[Test]
    public function itDoesNotCountCommentAfterReturnAsUnreachable(): void
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
    #[Test]
    public function itResetsReachabilityAtGotoLabel(): void
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
    #[Test]
    public function itResetsReachabilityAtEachGotoLabel(): void
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
    #[Test]
    public function itKeepsCodeUnreachableWhenGotoHasNoSubsequentLabel(): void
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

    private function collectMetrics(string $code): \Qualimetrix\Core\Metric\MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->collect(new SplFileInfo(__FILE__), $ast);
    }
}
