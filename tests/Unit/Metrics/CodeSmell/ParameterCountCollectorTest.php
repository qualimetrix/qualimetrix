<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\CodeSmell;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\CodeSmell\ParameterCountCollector;
use AiMessDetector\Metrics\CodeSmell\ParameterCountVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(ParameterCountCollector::class)]
#[CoversClass(ParameterCountVisitor::class)]
final class ParameterCountCollectorTest extends TestCase
{
    private ParameterCountCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new ParameterCountCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('parameter-count', $this->collector->getName());
    }

    public function testProvides(): void
    {
        self::assertSame(['parameterCount'], $this->collector->provides());
    }

    public function testMethodWithNoParameters(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

class Calculator
{
    public function reset(): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('parameterCount:App\Service\Calculator::reset'));
    }

    public function testMethodWithThreeParameters(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

class Calculator
{
    public function add(int $a, int $b, int $c): int
    {
        return $a + $b + $c;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('parameterCount:App\Service\Calculator::add'));
    }

    public function testConstructorWithPromotedProperties(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

class UserService
{
    public function __construct(
        private readonly string $name,
        private readonly int $age,
        private readonly string $email,
        private readonly bool $active,
    ) {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(4, $metrics->get('parameterCount:App\Service\UserService::__construct'));
    }

    public function testGlobalFunction(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Utils;

function formatName(string $first, string $last): string
{
    return $first . ' ' . $last;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('parameterCount:App\Utils\formatName'));
    }

    public function testVariadicParameter(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Logger
{
    public function log(string $message, mixed ...$context): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('parameterCount:App\Logger::log'));
    }

    public function testDefaultValues(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Config
{
    public function setup(string $name = 'default', int $timeout = 30, bool $debug = false): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('parameterCount:App\Config::setup'));
    }

    public function testMultipleMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Service
{
    public function noParams(): void
    {
    }

    public function oneParam(int $a): void
    {
    }

    public function threeParams(int $a, int $b, int $c): void
    {
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('parameterCount:App\Service::noParams'));
        self::assertSame(1, $metrics->get('parameterCount:App\Service::oneParam'));
        self::assertSame(3, $metrics->get('parameterCount:App\Service::threeParams'));
    }

    public function testReset(): void
    {
        $code1 = <<<'PHP'
<?php

namespace App;

class First
{
    public function method(int $a, int $b): void
    {
    }
}
PHP;

        $code2 = <<<'PHP'
<?php

namespace App;

class Second
{
    public function otherMethod(string $name): void
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
        self::assertNull($metrics->get('parameterCount:App\First::method'));
        self::assertSame(1, $metrics->get('parameterCount:App\Second::otherMethod'));
    }

    public function testGetMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);

        $definition = $definitions[0];
        self::assertSame('parameterCount', $definition->name);
        self::assertSame(SymbolLevel::Method, $definition->collectedAt);

        // Check Class_ level aggregations
        $classStrategies = $definition->getStrategiesForLevel(SymbolLevel::Class_);
        self::assertCount(2, $classStrategies);
        self::assertContains(AggregationStrategy::Max, $classStrategies);
        self::assertContains(AggregationStrategy::Average, $classStrategies);

        // Check Namespace_ level aggregations
        $namespaceStrategies = $definition->getStrategiesForLevel(SymbolLevel::Namespace_);
        self::assertCount(3, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Max, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Average, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Percentile95, $namespaceStrategies);

        // Check Project level aggregations
        $projectStrategies = $definition->getStrategiesForLevel(SymbolLevel::Project);
        self::assertCount(3, $projectStrategies);
        self::assertContains(AggregationStrategy::Max, $projectStrategies);
        self::assertContains(AggregationStrategy::Average, $projectStrategies);
        self::assertContains(AggregationStrategy::Percentile95, $projectStrategies);
    }

    public function testInterfaceMethod(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Contracts;

interface ServiceInterface
{
    public function execute(string $command, array $options): void;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('parameterCount:App\Contracts\ServiceInterface::execute'));
    }

    public function testAbstractMethod(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

abstract class AbstractHandler
{
    abstract public function handle(string $input, int $priority, bool $force): mixed;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('parameterCount:App\AbstractHandler::handle'));
    }

    public function testAnonymousClassInsideNamedClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

class Outer
{
    public function before(int $a, int $b, int $c): void {}

    public function factory(): object
    {
        return new class {
            public function inner(int $x): void {}
        };
    }

    public function after(int $a, int $b): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        // Methods of Outer should have correct FQN with class context preserved
        self::assertSame(3, $metrics->get('parameterCount:App\Outer::before'));
        self::assertSame(0, $metrics->get('parameterCount:App\Outer::factory'));
        self::assertSame(2, $metrics->get('parameterCount:App\Outer::after'));

        // Anonymous class methods should NOT appear in metrics
        self::assertNull($metrics->get('parameterCount:App\Outer::inner'));
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
