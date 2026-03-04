<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Structure;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\Structure\InheritanceDepthCollector;
use AiMessDetector\Metrics\Structure\InheritanceDepthVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(InheritanceDepthCollector::class)]
#[CoversClass(InheritanceDepthVisitor::class)]
final class InheritanceDepthCollectorTest extends TestCase
{
    private InheritanceDepthCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new InheritanceDepthCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('inheritance-depth', $this->collector->getName());
    }

    public function testProvides(): void
    {
        $provides = $this->collector->provides();

        self::assertContains('dit', $provides);
    }

    public function testClassWithNoParent(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class NoParent
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('dit:App\NoParent'));
    }

    public function testClassExtendsStandardException(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyException extends \Exception
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Extends Exception (standard PHP) = DIT 1
        self::assertSame(1, $metrics->get('dit:App\MyException'));
    }

    public function testClassExtendsStdClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyClass extends \stdClass
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('dit:App\MyClass'));
    }

    public function testSingleLevelInheritanceInSameFile(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Parent_
{
}

class Child extends Parent_
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('dit:App\Parent_'));
        self::assertSame(1, $metrics->get('dit:App\Child'));
    }

    public function testTwoLevelInheritanceInSameFile(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class GrandParent_
{
}

class Parent_ extends GrandParent_
{
}

class Child extends Parent_
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('dit:App\GrandParent_'));
        self::assertSame(1, $metrics->get('dit:App\Parent_'));
        self::assertSame(2, $metrics->get('dit:App\Child'));
    }

    public function testThreeLevelInheritance(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class A {}
class B extends A {}
class C extends B {}
class D extends C {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('dit:App\A'));
        self::assertSame(1, $metrics->get('dit:App\B'));
        self::assertSame(2, $metrics->get('dit:App\C'));
        self::assertSame(3, $metrics->get('dit:App\D'));
    }

    public function testClassExtendsRuntimeException(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyRuntimeException extends \RuntimeException
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        // RuntimeException is standard PHP = DIT 1
        self::assertSame(1, $metrics->get('dit:App\MyRuntimeException'));
    }

    public function testMultipleBranches(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Base {}

class BranchA extends Base {}
class BranchB extends Base {}

class LeafA extends BranchA {}
class LeafB extends BranchB {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('dit:App\Base'));
        self::assertSame(1, $metrics->get('dit:App\BranchA'));
        self::assertSame(1, $metrics->get('dit:App\BranchB'));
        self::assertSame(2, $metrics->get('dit:App\LeafA'));
        self::assertSame(2, $metrics->get('dit:App\LeafB'));
    }

    public function testClassWithoutNamespace(): void
    {
        $code = <<<'PHP'
<?php

class GlobalParent {}
class GlobalChild extends GlobalParent {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('dit:GlobalParent'));
        self::assertSame(1, $metrics->get('dit:GlobalChild'));
    }

    public function testAnonymousClassIgnored(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Factory
{
    public function create(): object
    {
        return new class extends \stdClass {};
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Only Factory should have metrics
        self::assertSame(0, $metrics->get('dit:App\Factory'));
    }

    public function testReset(): void
    {
        $code1 = <<<'PHP'
<?php

namespace App;

class First {}
PHP;

        $code2 = <<<'PHP'
<?php

namespace App;

class Second {}
PHP;

        // Collect first file
        $this->collectMetrics($code1);

        // Reset
        $this->collector->reset();

        // Collect second file
        $metrics = $this->collectMetrics($code2);

        // Should only contain metrics from second file
        self::assertNull($metrics->get('dit:App\First'));
        self::assertSame(0, $metrics->get('dit:App\Second'));
    }

    public function testGetMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);

        $def = $definitions[0];
        self::assertSame('dit', $def->name);
        self::assertSame(SymbolLevel::Class_, $def->collectedAt);

        $namespaceStrategies = $def->getStrategiesForLevel(SymbolLevel::Namespace_);
        self::assertContains(AggregationStrategy::Average, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Max, $namespaceStrategies);

        $projectStrategies = $def->getStrategiesForLevel(SymbolLevel::Project);
        self::assertContains(AggregationStrategy::Average, $projectStrategies);
        self::assertContains(AggregationStrategy::Max, $projectStrategies);
    }

    public function testExtendsExternalClass(): void
    {
        // This extends a real PHPUnit class
        $code = <<<'PHP'
<?php

namespace App;

class MyTestCase extends \PHPUnit\Framework\TestCase
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        // PHPUnit\Framework\TestCase exists and has some depth
        // We just check it's >= 1 (extends something)
        $dit = $metrics->get('dit:App\MyTestCase');
        self::assertIsInt($dit);
        self::assertGreaterThanOrEqual(1, $dit);
    }

    public function testClassWithFullyQualifiedExtends(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyException extends \Exception
{
}

class MyRuntimeException extends \RuntimeException
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('dit:App\MyException'));
        self::assertSame(1, $metrics->get('dit:App\MyRuntimeException'));
    }

    public function testRelativeExtends(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Base {}

class Child extends Base {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('dit:App\Base'));
        self::assertSame(1, $metrics->get('dit:App\Child'));
    }

    public function testDateTimeClasses(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyDateTime extends \DateTime {}
class MyDateTimeImmutable extends \DateTimeImmutable {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('dit:App\MyDateTime'));
        self::assertSame(1, $metrics->get('dit:App\MyDateTimeImmutable'));
    }

    public function testSplClasses(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyFileInfo extends \SplFileInfo {}
class MyIterator extends \ArrayIterator {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('dit:App\MyFileInfo'));
        self::assertSame(1, $metrics->get('dit:App\MyIterator'));
    }

    private function collectMetrics(string $code): MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->collect(new SplFileInfo(__FILE__), $ast);
    }
}
