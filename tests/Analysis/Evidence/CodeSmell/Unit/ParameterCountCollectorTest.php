<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCountCollector;
use Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCountVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Core\Path\RelativePath;
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
        self::assertSame(SymbolLevel::Callable, $definition->collectedAt);

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
        self::assertSame(SymbolLevel::Callable, $voDefinition->collectedAt);
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

    // -- getCallablesWithMetrics() per-symbol propagation ----------------------
    //
    // Regression coverage: FileProcessor builds per-symbol metrics exclusively
    // from CallableWithMetrics (see getCallablesWithMetrics()), not from the
    // file-level MetricBag produced by collect(). isVoConstructor must be
    // present on the per-method MetricBag returned here, or LongParameterListRule
    // never sees it and falls back to the non-VO thresholds at analysis time.

    #[Test]
    public function itIncludesVoConstructorFlagInPerMethodMetricsForVoConstructor(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Dto;

final readonly class UserDto
{
    public function __construct(
        public string $name,
        public string $email,
        public int $age,
    ) {}
}
PHP;

        $methodsWithMetrics = $this->collectMethodsWithMetrics($code);
        $construct = $this->findCallableWithMetrics($methodsWithMetrics, 'UserDto', '__construct');

        self::assertSame(3, $construct->metrics->get('parameterCount'));
        self::assertSame(1, $construct->metrics->get('isVoConstructor'));
    }

    #[Test]
    public function itOmitsVoConstructorFlagInPerMethodMetricsForNonVoConstructor(): void
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

        $methodsWithMetrics = $this->collectMethodsWithMetrics($code);
        $construct = $this->findCallableWithMetrics($methodsWithMetrics, 'UserService', '__construct');

        self::assertSame(2, $construct->metrics->get('parameterCount'));
        self::assertNull($construct->metrics->get('isVoConstructor'));
    }

    #[Test]
    public function itOmitsVoConstructorFlagInPerMethodMetricsForRegularMethod(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Dto;

final readonly class UserDto
{
    public function __construct(
        public string $name,
    ) {}

    public function withName(string $name): self
    {
        return new self($name);
    }
}
PHP;

        $methodsWithMetrics = $this->collectMethodsWithMetrics($code);
        $withName = $this->findCallableWithMetrics($methodsWithMetrics, 'UserDto', 'withName');

        self::assertSame(1, $withName->metrics->get('parameterCount'));
        self::assertNull($withName->metrics->get('isVoConstructor'));
    }

    /**
     * @return list<\Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics>
     */
    private function collectMethodsWithMetrics(string $code): array
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->getCallablesWithMetrics(RelativePath::fromString('src/Callable.php'));
    }

    /**
     * @param list<\Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics> $methodsWithMetrics
     */
    private function findCallableWithMetrics(array $methodsWithMetrics, string $class, string $method): \Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics
    {
        foreach ($methodsWithMetrics as $methodWithMetrics) {
            $logical = $methodWithMetrics->declarationPath->logical;
            if ($logical->type === $class && $logical->member === $method) {
                return $methodWithMetrics;
            }
        }

        self::fail(\sprintf('No CallableWithMetrics found for %s::%s', $class, $method));
    }

    private function collectMetrics(string $code): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        return $this->collector->collect(new SplFileInfo(__FILE__), $ast);
    }
}
