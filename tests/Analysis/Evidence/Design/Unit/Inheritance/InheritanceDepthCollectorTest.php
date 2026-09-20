<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit\Inheritance;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\InheritanceDepthCollector;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\InheritanceDepthVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Tests\Analysis\Evidence\Design\Support\UnloadableClassProbe;
use RuntimeException;
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

    #[Test]
    public function itGetsName(): void
    {
        self::assertSame('inheritance-depth', $this->collector->getName());
    }

    #[Test]
    public function itProvides(): void
    {
        $provides = $this->collector->provides();

        self::assertContains('design.dit', $provides);
    }

    #[Test]
    public function itReturnsZeroForClassWithNoParent(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class NoParent
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('design.dit:App\NoParent'));
    }

    #[Test]
    public function itCountsDitForClassExtendingStandardException(): void
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
        self::assertSame(1, $metrics->get('design.dit:App\MyException'));
    }

    #[Test]
    public function itCountsDitForClassExtendingStdClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyClass extends \stdClass
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('design.dit:App\MyClass'));
    }

    #[Test]
    public function itCountsSingleLevelInheritanceInSameFile(): void
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

        self::assertSame(0, $metrics->get('design.dit:App\Parent_'));
        self::assertSame(1, $metrics->get('design.dit:App\Child'));
    }

    #[Test]
    public function itCountsTwoLevelInheritanceInSameFile(): void
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

        self::assertSame(0, $metrics->get('design.dit:App\GrandParent_'));
        self::assertSame(1, $metrics->get('design.dit:App\Parent_'));
        self::assertSame(2, $metrics->get('design.dit:App\Child'));
    }

    #[Test]
    public function itCountsThreeLevelInheritance(): void
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

        self::assertSame(0, $metrics->get('design.dit:App\A'));
        self::assertSame(1, $metrics->get('design.dit:App\B'));
        self::assertSame(2, $metrics->get('design.dit:App\C'));
        self::assertSame(3, $metrics->get('design.dit:App\D'));
    }

    #[Test]
    public function itCountsDitForClassExtendingRuntimeException(): void
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
        self::assertSame(1, $metrics->get('design.dit:App\MyRuntimeException'));
    }

    #[Test]
    public function itCountsDitForMultipleBranches(): void
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

        self::assertSame(0, $metrics->get('design.dit:App\Base'));
        self::assertSame(1, $metrics->get('design.dit:App\BranchA'));
        self::assertSame(1, $metrics->get('design.dit:App\BranchB'));
        self::assertSame(2, $metrics->get('design.dit:App\LeafA'));
        self::assertSame(2, $metrics->get('design.dit:App\LeafB'));
    }

    #[Test]
    public function itHandlesClassWithoutNamespace(): void
    {
        $code = <<<'PHP'
<?php

class GlobalParent {}
class GlobalChild extends GlobalParent {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('design.dit:GlobalParent'));
        self::assertSame(1, $metrics->get('design.dit:GlobalChild'));
    }

    /**
     * Not a regression witness for an anonymous class lending its declaration
     * to the class enclosing it, and it cannot become one: this collector's
     * visitor registers only named `Class_` nodes, so an anonymous class's
     * `extends` never reaches it, and this collector does not write the
     * published `design.dit` either. The parent chain here is two levels deep
     * on purpose — a one-level or builtin parent would make the assertion pass
     * whatever the attribution is, which is how this test read as green while
     * the defect was live. The dependency-graph path that carries the defect is
     * covered by
     * {@see \Qualimetrix\Tests\Analysis\Evidence\Design\Unit\Inheritance\DitGlobalCollectorTest}
     * and by the anonymous-class declaration-edge integration run.
     */
    #[Test]
    public function itIgnoresAnonymousClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Base
{
}

class Mid extends Base
{
}

class Factory
{
    public function create(): object
    {
        // If this collector attributed the anonymous class's own `extends`
        // to Factory, Factory would score dit(Mid) + 1 = 2, not 0.
        return new class extends Mid {};
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('design.dit:App\Base'));
        self::assertSame(1, $metrics->get('design.dit:App\Mid'));
        self::assertSame(0, $metrics->get('design.dit:App\Factory'));

        // No metric key exists for the anonymous class itself: the visitor
        // never registers it as a class at all, named or otherwise.
        self::assertCount(3, $metrics->all(), 'Expected metrics for exactly the three named classes, nothing for the anonymous one');
    }

    #[Test]
    public function itResetsState(): void
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
        self::assertNull($metrics->get('design.dit:App\First'));
        self::assertSame(0, $metrics->get('design.dit:App\Second'));
    }

    #[Test]
    public function itDeclaresNoMetricDefinition(): void
    {
        // DIT's definition belongs to the collector that writes the published
        // value. Declaring it here too would put DIT into the first
        // aggregation pass, which runs before the global pass corrects it.
        self::assertSame([], $this->collector->getMetricDefinitions());
    }

    #[Test]
    public function itCountsDitForClassExtendingExternalClass(): void
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
        $dit = $metrics->get('design.dit:App\MyTestCase');
        self::assertIsInt($dit);
        self::assertGreaterThanOrEqual(1, $dit);
    }

    #[Test]
    public function itHandlesFullyQualifiedExtends(): void
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

        self::assertSame(1, $metrics->get('design.dit:App\MyException'));
        self::assertSame(1, $metrics->get('design.dit:App\MyRuntimeException'));
    }

    #[Test]
    public function itHandlesRelativeExtends(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Base {}

class Child extends Base {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('design.dit:App\Base'));
        self::assertSame(1, $metrics->get('design.dit:App\Child'));
    }

    #[Test]
    public function itCountsDitForDateTimeClasses(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyDateTime extends \DateTime {}
class MyDateTimeImmutable extends \DateTimeImmutable {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('design.dit:App\MyDateTime'));
        self::assertSame(1, $metrics->get('design.dit:App\MyDateTimeImmutable'));
    }

    #[Test]
    public function itCountsDitForSplClasses(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyFileInfo extends \SplFileInfo {}
class MyIterator extends \ArrayIterator {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('design.dit:App\MyFileInfo'));
        self::assertSame(1, $metrics->get('design.dit:App\MyIterator'));
    }

    #[Test]
    public function itCountsDitForClassExtendingSplStack(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class MyStack extends \SplStack
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        // SplStack is a standard PHP class = DIT 1
        self::assertSame(1, $metrics->get('design.dit:App\MyStack'));
    }

    #[Test]
    public function itDoesNotTreatNamespacedExceptionAsStandardPhpClass(): void
    {
        // Bug 9: App\Exception should NOT match standard Exception
        $code = <<<'PHP'
<?php

namespace App;

class Exception
{
}

class MyException extends Exception
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        // App\Exception is NOT standard — it's a user class with DIT 0
        self::assertSame(0, $metrics->get('design.dit:App\Exception'));
        // MyException extends App\Exception (not standard) — DIT 1
        self::assertSame(1, $metrics->get('design.dit:App\MyException'));
    }

    #[Test]
    public function itDoesNotTreatNamespacedErrorAsStandardPhpClass(): void
    {
        // App\Error should NOT match standard Error
        $code = <<<'PHP'
<?php

namespace App\Domain;

class Error
{
}

class MyError extends Error
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('design.dit:App\Domain\Error'));
        self::assertSame(1, $metrics->get('design.dit:App\Domain\MyError'));
    }

    #[Test]
    public function itTreatsUnqualifiedExceptionAsStandardPhpClass(): void
    {
        // Unqualified "Exception" (no namespace) IS a standard PHP class
        $code = <<<'PHP'
<?php

class MyException extends Exception
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('design.dit:MyException'));
    }

    #[Test]
    public function itDeliberatelyDoesNotProvideCallableMetrics(): void
    {
        self::assertNotContains(\Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface::class, class_implements($this->collector));
    }

    /**
     * The per-file pass no longer asks any autoloader about a parent it cannot
     * see in this file, so it cannot execute the analysed project's code.
     *
     * The probe is the oracle for that, and its reading inverts: it used to
     * assert the load was attempted and failed on the parent's absence, which
     * was the shape a standalone install hit. Now the claim is that the load is
     * never attempted, and `queryCount()` is what separates "was not asked"
     * from "was asked and threw" -- the depth cannot, because an unresolved
     * parent scores 1 either way.
     */
    #[Test]
    public function itAsksNoAutoloaderAboutAParentItCannotSee(): void
    {
        $probe = UnloadableClassProbe::start();

        try {
            $metrics = $this->collectMetrics(\sprintf(
                "<?php\n\nnamespace App;\n\nclass Local extends \\%s\n{\n}\n",
                $probe->childFqcn(),
            ));

            self::assertSame(0, $probe->queryCount(), 'The collector still consulted an autoloader');
            self::assertFalse($probe->failedOnTheMissingParent(), 'A load was attempted, so foreign code ran');
            self::assertSame(1, $metrics->get('design.dit:App\Local'));
        } finally {
            $probe->stop();
        }
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
