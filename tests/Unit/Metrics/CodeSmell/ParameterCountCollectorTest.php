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
use Qualimetrix\Metrics\CodeSmell\ParameterCountCollector;
use Qualimetrix\Metrics\CodeSmell\ParameterCountVisitor;
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

    #[Test]
    public function itReturnsCollectorName(): void
    {
        self::assertSame('parameter-count', $this->collector->getName());
    }

    #[Test]
    public function itProvidesExpectedMetricKeys(): void
    {
        self::assertSame(['parameterCount', 'isVoConstructor'], $this->collector->provides());
    }

    #[Test]
    public function itCountsZeroForMethodWithNoParameters(): void
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

    #[Test]
    public function itCountsThreeForMethodWithThreeParameters(): void
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

    #[Test]
    public function itCountsPromotedPropertiesAsParameters(): void
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

    #[Test]
    public function itCountsParametersForGlobalFunction(): void
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

    #[Test]
    public function itCountsVariadicParameterAsOne(): void
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

    #[Test]
    public function itCountsParametersWithDefaultValues(): void
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

    #[Test]
    public function itCountsParametersForMultipleMethods(): void
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

    #[Test]
    public function itClearsStateOnReset(): void
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

    #[Test]
    public function itReturnsCorrectMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(2, $definitions);

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

        // Check isVoConstructor metric definition
        $voDefinition = $definitions[1];
        self::assertSame('isVoConstructor', $voDefinition->name);
        self::assertSame(SymbolLevel::Method, $voDefinition->collectedAt);
    }

    #[Test]
    public function itCountsParametersForInterfaceMethod(): void
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

    #[Test]
    public function itCountsParametersForAbstractMethod(): void
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

    #[Test]
    public function itDetectsVoConstructorForReadonlyClassWithAllPromotedEmptyBody(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Dto;

readonly class UserDto
{
    public function __construct(
        public string $name,
        public string $email,
        public int $age,
        public bool $active,
    ) {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(4, $metrics->get('parameterCount:App\Dto\UserDto::__construct'));
        self::assertSame(1, $metrics->get('isVoConstructor:App\Dto\UserDto::__construct'));
    }

    #[Test]
    public function itDetectsVoConstructorForFinalReadonlyClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Dto;

final readonly class Point
{
    public function __construct(
        public float $x,
        public float $y,
        public float $z,
    ) {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('isVoConstructor:App\Dto\Point::__construct'));
    }

    #[Test]
    public function itDoesNotDetectVoConstructorForNonReadonlyClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

class UserService
{
    public function __construct(
        private readonly string $name,
        private readonly int $age,
    ) {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('parameterCount:App\Service\UserService::__construct'));
        self::assertNull($metrics->get('isVoConstructor:App\Service\UserService::__construct'));
    }

    #[Test]
    public function itDoesNotDetectVoConstructorWhenMixedPromotedAndNonPromoted(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Dto;

readonly class MixedDto
{
    public function __construct(
        public string $name,
        string $temporary,
    ) {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertNull($metrics->get('isVoConstructor:App\Dto\MixedDto::__construct'));
    }

    #[Test]
    public function itDoesNotDetectVoConstructorWhenBodyHasLogic(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Dto;

readonly class ValidatedDto
{
    public function __construct(
        public string $name,
        public int $age,
    ) {
        assert($age >= 0);
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertNull($metrics->get('isVoConstructor:App\Dto\ValidatedDto::__construct'));
    }

    #[Test]
    public function itDoesNotDetectVoConstructorWhenBodyHasParentCall(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Dto;

readonly class ChildDto
{
    public function __construct(
        public string $name,
        public int $age,
    ) {
        parent::__construct();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertNull($metrics->get('isVoConstructor:App\Dto\ChildDto::__construct'));
    }

    #[Test]
    public function itDetectsVoConstructorWithDefaultValues(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Dto;

readonly class ConfigDto
{
    public function __construct(
        public string $name = 'default',
        public int $timeout = 30,
        public bool $debug = false,
    ) {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('isVoConstructor:App\Dto\ConfigDto::__construct'));
    }

    #[Test]
    public function itAppliesVoDetectionOnlyToConstructMethod(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Dto;

readonly class SomeDto
{
    public function __construct(
        public string $name,
    ) {}

    public function process(string $a, string $b): void {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('isVoConstructor:App\Dto\SomeDto::__construct'));
        self::assertNull($metrics->get('isVoConstructor:App\Dto\SomeDto::process'));
    }

    #[Test]
    public function itDetectsVoConstructorForAbstractReadonlyClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Dto;

abstract readonly class BaseDto
{
    public function __construct(
        public string $id,
        public string $type,
    ) {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(1, $metrics->get('isVoConstructor:App\Dto\BaseDto::__construct'));
    }

    #[Test]
    public function itProducesNoMetricsForReadonlyClassWithNoConstructor(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Dto;

readonly class EmptyDto
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        // No constructor means no parameterCount and no voConstructor metrics
        self::assertNull($metrics->get('parameterCount:App\Dto\EmptyDto::__construct'));
        self::assertNull($metrics->get('isVoConstructor:App\Dto\EmptyDto::__construct'));
    }

    #[Test]
    public function itDoesNotDetectVoConstructorForEmptyConstructor(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Dto;

readonly class NoParamDto
{
    public function __construct() {}
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('parameterCount:App\Dto\NoParamDto::__construct'));
        // Zero-param constructor is not a VO constructor (no promoted properties)
        self::assertNull($metrics->get('isVoConstructor:App\Dto\NoParamDto::__construct'));
    }

    #[Test]
    public function itSkipsAnonymousClassMethodsInsideNamedClass(): void
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
