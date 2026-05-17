<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Size;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\Size\ClassCountCollector;
use Qualimetrix\Metrics\Size\ClassCountVisitor;
use SplFileInfo;

#[CoversClass(ClassCountCollector::class)]
#[CoversClass(ClassCountVisitor::class)]
final class ClassCountCollectorTest extends TestCase
{
    private ClassCountCollector $collector;
    private string $testFilePath;

    protected function setUp(): void
    {
        $this->collector = new ClassCountCollector();
        $this->testFilePath = '/test/file.php';
    }

    #[Test]
    public function itGetsName(): void
    {
        self::assertSame('class-count', $this->collector->getName());
    }

    #[Test]
    public function itProvides(): void
    {
        self::assertSame(
            ['classCount', 'abstractClassCount', 'interfaceCount', 'traitCount', 'enumCount', 'functionCount'],
            $this->collector->provides(),
        );
    }

    #[Test]
    public function itHandlesEmptyFile(): void
    {
        $code = '<?php';

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('classCount'));
        self::assertSame(0, $metrics->get('interfaceCount'));
        self::assertSame(0, $metrics->get('traitCount'));
        self::assertSame(0, $metrics->get('enumCount'));
        self::assertSame(0, $metrics->get('functionCount'));
    }

    #[Test]
    public function itCountsSingleClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class UserService
{
    public function getUser(): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('classCount'));
        self::assertSame(0, $metrics->get('interfaceCount'));
        self::assertSame(0, $metrics->get('traitCount'));
        self::assertSame(0, $metrics->get('enumCount'));
    }

    #[Test]
    public function itCountsMultipleClasses(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class FirstClass {}
class SecondClass {}
class ThirdClass {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('classCount'));
    }

    #[Test]
    public function itCountsInterface(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Contracts;

interface UserRepositoryInterface
{
    public function find(int $id): ?object;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('classCount'));
        self::assertSame(1, $metrics->get('interfaceCount'));
    }

    #[Test]
    public function itCountsMultipleInterfaces(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

interface Readable {}
interface Writable {}
interface Closable {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('interfaceCount'));
    }

    #[Test]
    public function itCountsTrait(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Traits;

trait LoggableTrait
{
    public function log(string $message): void
    {
        echo $message;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('classCount'));
        self::assertSame(1, $metrics->get('traitCount'));
    }

    #[Test]
    public function itCountsMultipleTraits(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait FirstTrait {}
trait SecondTrait {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('traitCount'));
    }

    #[Test]
    public function itCountsEnum(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Enums;

enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('classCount'));
        self::assertSame(1, $metrics->get('enumCount'));
    }

    #[Test]
    public function itCountsMultipleEnums(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

enum Color { case Red; case Green; }
enum Size { case Small; case Large; }
enum Status { case Active; }
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('enumCount'));
    }

    #[Test]
    public function itCountsMixedTypes(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Service {}
interface Contract {}
trait Helper {}
enum Status { case Active; }

class AnotherService {}
interface AnotherContract {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('classCount'));
        self::assertSame(2, $metrics->get('interfaceCount'));
        self::assertSame(1, $metrics->get('traitCount'));
        self::assertSame(1, $metrics->get('enumCount'));
        self::assertSame(0, $metrics->get('functionCount'));
    }

    #[Test]
    public function itIgnoresAnonymousClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Factory
{
    public function create(): object
    {
        return new class {
            public function doSomething(): void {}
        };
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Only the named class is counted, anonymous class is ignored
        self::assertSame(1, $metrics->get('classCount'));
    }

    #[Test]
    public function itIgnoresMultipleAnonymousClasses(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Container
{
    public function getServices(): array
    {
        return [
            new class implements \Stringable {
                public function __toString(): string { return 'a'; }
            },
            new class implements \Stringable {
                public function __toString(): string { return 'b'; }
            },
            new class implements \Stringable {
                public function __toString(): string { return 'c'; }
            },
        ];
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Only the named class Container is counted
        self::assertSame(1, $metrics->get('classCount'));
    }

    #[Test]
    public function itHandlesNestedNamedClassInAnonymousClass(): void
    {
        // This is a rare edge case in PHP
        $code = <<<'PHP'
<?php

namespace App;

class Outer
{
    public function create(): object
    {
        return new class {
            // Anonymous classes cannot contain nested named classes in PHP
        };
    }
}

class AnotherOuter {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('classCount'));
    }

    #[Test]
    public function itCountsStandaloneFunction(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Helpers;

function calculateSum(int $a, int $b): int
{
    return $a + $b;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('classCount'));
        self::assertSame(1, $metrics->get('functionCount'));
    }

    #[Test]
    public function itCountsMultipleStandaloneFunctions(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Helpers;

function add(int $a, int $b): int { return $a + $b; }
function subtract(int $a, int $b): int { return $a - $b; }
function multiply(int $a, int $b): int { return $a * $b; }
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('functionCount'));
    }

    #[Test]
    public function itDoesNotCountClassMethodsAsFunctions(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function subtract(int $a, int $b): int
    {
        return $a - $b;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('classCount'));
        self::assertSame(0, $metrics->get('functionCount'));
    }

    #[Test]
    public function itCountsMixedFunctionsAndClasses(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

function helperFunction(): void {}

class Service
{
    public function method(): void {}
}

function anotherHelper(): void {}

interface Contract {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('classCount'));
        self::assertSame(1, $metrics->get('interfaceCount'));
        self::assertSame(2, $metrics->get('functionCount'));
    }

    #[Test]
    public function itResetsState(): void
    {
        $code1 = <<<'PHP'
<?php

class A {}
class B {}
PHP;

        $code2 = <<<'PHP'
<?php

class C {}
PHP;

        // Collect first file
        $this->collectMetrics($code1);

        // Reset collector
        $this->collector->reset();

        // Collect second file
        $metrics = $this->collectMetrics($code2);

        // Should only reflect second file
        self::assertSame(1, $metrics->get('classCount'));
    }

    #[Test]
    public function itCountsAbstractClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

abstract class AbstractService
{
    abstract public function execute(): void;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('classCount'));
        self::assertSame(1, $metrics->get('abstractClassCount'));
    }

    #[Test]
    public function itCountsMultipleAbstractClasses(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

abstract class AbstractBase {}
abstract class AbstractService extends AbstractBase {}
class ConcreteService extends AbstractService {}
class AnotherConcrete {}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(4, $metrics->get('classCount'));
        self::assertSame(2, $metrics->get('abstractClassCount'));
    }

    #[Test]
    public function itCountsFinalClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

final class FinalService
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('classCount'));
    }

    #[Test]
    public function itCountsReadonlyClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

readonly class ValueObject
{
    public function __construct(
        public string $value,
    ) {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('classCount'));
    }

    #[Test]
    public function itCountsBackedEnum(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

enum Priority: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('enumCount'));
    }

    #[Test]
    public function itCountsUnitEnum(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

enum Direction
{
    case North;
    case South;
    case East;
    case West;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('enumCount'));
    }

    #[Test]
    public function itGetsMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(6, $definitions);

        $metricNames = array_map(fn($d) => $d->name, $definitions);
        self::assertContains('classCount', $metricNames);
        self::assertContains('abstractClassCount', $metricNames);
        self::assertContains('interfaceCount', $metricNames);
        self::assertContains('traitCount', $metricNames);
        self::assertContains('enumCount', $metricNames);
        self::assertContains('functionCount', $metricNames);

        foreach ($definitions as $definition) {
            self::assertSame(SymbolLevel::File, $definition->collectedAt);

            // Check Namespace_ level aggregations - only Sum
            $namespaceStrategies = $definition->getStrategiesForLevel(SymbolLevel::Namespace_);
            self::assertCount(1, $namespaceStrategies);
            self::assertContains(AggregationStrategy::Sum, $namespaceStrategies);

            // Check Project level aggregations - only Sum
            $projectStrategies = $definition->getStrategiesForLevel(SymbolLevel::Project);
            self::assertCount(1, $projectStrategies);
            self::assertContains(AggregationStrategy::Sum, $projectStrategies);

            // Should not have Class_ level aggregations
            self::assertEmpty($definition->getStrategiesForLevel(SymbolLevel::Class_));
        }
    }

    private function collectMetrics(string $code): \Qualimetrix\Core\Metric\MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        // Create a mock file info with our test path
        $file = new class ($this->testFilePath) extends SplFileInfo {
            public function __construct(private readonly string $path)
            {
                parent::__construct($path);
            }

            public function getPathname(): string
            {
                return $this->path;
            }
        };

        return $this->collector->collect($file, $ast);
    }
}
