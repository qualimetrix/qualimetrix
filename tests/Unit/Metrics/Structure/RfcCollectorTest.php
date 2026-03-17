<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Structure;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\Structure\RfcCollector;
use AiMessDetector\Metrics\Structure\RfcVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(RfcCollector::class)]
#[CoversClass(RfcVisitor::class)]
final class RfcCollectorTest extends TestCase
{
    private RfcCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new RfcCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('rfc', $this->collector->getName());
    }

    public function testProvides(): void
    {
        $provides = $this->collector->provides();

        self::assertContains('rfc', $provides);
        self::assertContains('rfc_own', $provides);
        self::assertContains('rfc_external', $provides);
        self::assertCount(3, $provides);
    }

    public function testGetMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(3, $definitions);

        // Check rfc metric definition
        $rfcDef = $definitions[0];
        self::assertSame('rfc', $rfcDef->name);
        self::assertSame(SymbolLevel::Class_, $rfcDef->collectedAt);

        $namespaceStrategies = $rfcDef->getStrategiesForLevel(SymbolLevel::Namespace_);
        self::assertContains(AggregationStrategy::Sum, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Average, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Max, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Percentile95, $namespaceStrategies);

        $projectStrategies = $rfcDef->getStrategiesForLevel(SymbolLevel::Project);
        self::assertContains(AggregationStrategy::Sum, $projectStrategies);
        self::assertContains(AggregationStrategy::Average, $projectStrategies);
        self::assertContains(AggregationStrategy::Max, $projectStrategies);
        self::assertContains(AggregationStrategy::Percentile95, $projectStrategies);

        // Check rfc_own metric definition
        $rfcOwnDef = $definitions[1];
        self::assertSame('rfc_own', $rfcOwnDef->name);
        self::assertSame(SymbolLevel::Class_, $rfcOwnDef->collectedAt);

        // Check rfc_external metric definition
        $rfcExternalDef = $definitions[2];
        self::assertSame('rfc_external', $rfcExternalDef->name);
        self::assertSame(SymbolLevel::Class_, $rfcExternalDef->collectedAt);
    }

    public function testEmptyClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class EmptyClass
{
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(0, $metrics->get('rfc:App\EmptyClass'));
        self::assertSame(0, $metrics->get('rfc_own:App\EmptyClass'));
        self::assertSame(0, $metrics->get('rfc_external:App\EmptyClass'));
    }

    public function testSimpleCalculator(): void
    {
        $code = <<<'PHP'
<?php
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

        self::assertSame(2, $metrics->get('rfc:Calculator'));
        self::assertSame(2, $metrics->get('rfc_own:Calculator'));
        self::assertSame(0, $metrics->get('rfc_external:Calculator'));
    }

    public function testClassWithExternalCalls(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class OrderService
{
    public function createOrder(int $userId): void
    {
        $user = $this->userRepo->find($userId);
        $order = $this->factory->create($user);
    }

    public function processPayment(): void
    {
        $this->gateway->charge();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(5, $metrics->get('rfc:App\OrderService')); // 2 own + 3 external
        self::assertSame(2, $metrics->get('rfc_own:App\OrderService'));
        self::assertSame(3, $metrics->get('rfc_external:App\OrderService'));
    }

    public function testDuplicateExternalCalls(): void
    {
        $code = <<<'PHP'
<?php
class Logger
{
    public function logInfo(string $message): void
    {
        $this->writer->write('INFO: ' . $message);
    }

    public function logError(string $message): void
    {
        $this->writer->write('ERROR: ' . $message);
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('rfc:Logger')); // 2 own + 1 external (write counted once)
        self::assertSame(2, $metrics->get('rfc_own:Logger'));
        self::assertSame(1, $metrics->get('rfc_external:Logger'));
    }

    public function testInternalCallsNotCounted(): void
    {
        $code = <<<'PHP'
<?php
class InternalCalls
{
    public function publicMethod(): void
    {
        $this->privateHelper();
    }

    private function privateHelper(): void
    {
        // ...
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('rfc:InternalCalls')); // 2 own + 0 external
        self::assertSame(2, $metrics->get('rfc_own:InternalCalls'));
        self::assertSame(0, $metrics->get('rfc_external:InternalCalls'));
    }

    public function testStaticCalls(): void
    {
        $code = <<<'PHP'
<?php
class StaticCalls
{
    public function doSomething(): void
    {
        Factory::create();
        Cache::get('key');
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('rfc:StaticCalls')); // 1 own + 2 external
        self::assertSame(1, $metrics->get('rfc_own:StaticCalls'));
        self::assertSame(2, $metrics->get('rfc_external:StaticCalls'));
    }

    public function testGlobalFunctionCalls(): void
    {
        $code = <<<'PHP'
<?php
class GlobalFunctions
{
    public function process(array $data): array
    {
        array_map(fn($x) => $x * 2, $data);
        strlen('test');
        return $data;
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('rfc:GlobalFunctions')); // 1 own + 2 external
        self::assertSame(1, $metrics->get('rfc_own:GlobalFunctions'));
        self::assertSame(2, $metrics->get('rfc_external:GlobalFunctions'));
    }

    public function testConstructorCalls(): void
    {
        $code = <<<'PHP'
<?php
class Factory
{
    public function createUser(): void
    {
        $user = new User();
        $order = new Order();
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(3, $metrics->get('rfc:Factory')); // 1 own + 2 external
        self::assertSame(1, $metrics->get('rfc_own:Factory'));
        self::assertSame(2, $metrics->get('rfc_external:Factory'));
    }

    public function testMultipleClasses(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class First
{
    public function method1(): void
    {
        Logger::log('test');
    }
}

class Second
{
    public function method1(): void
    {
        Cache::get('key');
    }

    public function method2(): void
    {
        Cache::set('key', 'value');
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        // First class: 1 own + 1 external = 2
        self::assertSame(2, $metrics->get('rfc:App\First'));
        self::assertSame(1, $metrics->get('rfc_own:App\First'));
        self::assertSame(1, $metrics->get('rfc_external:App\First'));

        // Second class: 2 own + 2 external = 4
        self::assertSame(4, $metrics->get('rfc:App\Second'));
        self::assertSame(2, $metrics->get('rfc_own:App\Second'));
        self::assertSame(2, $metrics->get('rfc_external:App\Second'));
    }

    public function testGetClassesWithMetrics(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Service;

class UserService
{
    public function getUser(int $id): void
    {
        $this->repository->find($id);
        Logger::info('User fetched');
    }
}
PHP;

        // Parse and traverse
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        $classesWithMetrics = $this->collector->getClassesWithMetrics();

        self::assertCount(1, $classesWithMetrics);

        $classMetrics = $classesWithMetrics[0];
        self::assertSame('App\Service', $classMetrics->namespace);
        self::assertSame('UserService', $classMetrics->class);

        $bag = $classMetrics->metrics;
        self::assertSame(3, $bag->get('rfc')); // 1 own + 2 external
        self::assertSame(1, $bag->get('rfc_own'));
        self::assertSame(2, $bag->get('rfc_external'));
    }

    public function testReset(): void
    {
        $code1 = <<<'PHP'
<?php
class First
{
    public function method(): void
    {
        Logger::log('test');
    }
}
PHP;

        $metrics1 = $this->collectMetrics($code1);
        self::assertSame(2, $metrics1->get('rfc:First'));

        // Reset
        $this->collector->reset();

        $code2 = <<<'PHP'
<?php
class Second
{
    public function method(): void {}
}
PHP;

        $metrics2 = $this->collectMetrics($code2);

        // First class should not be in the results
        self::assertNull($metrics2->get('rfc:First'));
        self::assertSame(1, $metrics2->get('rfc:Second'));
    }

    public function testAbstractMethodsNotCounted(): void
    {
        $code = <<<'PHP'
<?php
abstract class AbstractClass
{
    public function concreteMethod(): void
    {
        $this->logger->log('test');
    }

    abstract public function abstractMethod(): void;
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(2, $metrics->get('rfc:AbstractClass')); // 1 concrete + 1 external
        self::assertSame(1, $metrics->get('rfc_own:AbstractClass'));
        self::assertSame(1, $metrics->get('rfc_external:AbstractClass'));
    }

    public function testComplexRealWorldExample(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Service;

class OrderProcessor
{
    public function process($order): void
    {
        $user = $this->userRepo->find($order->userId);
        $this->validator->validate($order);
        $items = $this->inventoryService->checkStock($order->items);
        $this->priceCalculator->calculate($order);
        $this->discountService->apply($order, $user);
        $payment = $this->paymentGateway->process($order);
        $this->notificationService->notify($user, $order);
        $this->logger->info('Order processed');
        $this->eventBus->dispatch(new OrderCreated($order));
    }
}
PHP;

        $metrics = $this->collectMetrics($code);

        self::assertSame(11, $metrics->get('rfc:App\Service\OrderProcessor')); // 1 own + 10 external
        self::assertSame(1, $metrics->get('rfc_own:App\Service\OrderProcessor'));
        self::assertSame(10, $metrics->get('rfc_external:App\Service\OrderProcessor'));
    }

    private function collectMetrics(string $code): MetricBag
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        $file = new SplFileInfo(__FILE__);

        return $this->collector->collect($file, $ast);
    }
}
