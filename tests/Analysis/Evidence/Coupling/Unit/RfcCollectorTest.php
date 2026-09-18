<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\RfcCollector;
use Qualimetrix\Analysis\Evidence\Coupling\RfcVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;
use Qualimetrix\Core\Symbol\SymbolLevel;
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

    #[Test]
    public function itReturnsCollectorName(): void
    {
        self::assertSame('rfc', $this->collector->getName());
    }

    #[Test]
    public function itProvidesExpectedMetricNames(): void
    {
        $provides = $this->collector->provides();

        self::assertContains('coupling.rfc', $provides);
        self::assertContains('coupling.rfc-own', $provides);
        self::assertContains('coupling.rfc-external', $provides);
        self::assertCount(3, $provides);
    }

    #[Test]
    public function itReturnsCorrectMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(3, $definitions);

        // Check rfc metric definition
        $rfcDef = $definitions[0];
        self::assertSame('coupling.rfc', $rfcDef->name);
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
        self::assertSame('coupling.rfc-own', $rfcOwnDef->name);
        self::assertSame(SymbolLevel::Class_, $rfcOwnDef->collectedAt);

        // Check rfc_external metric definition
        $rfcExternalDef = $definitions[2];
        self::assertSame('coupling.rfc-external', $rfcExternalDef->name);
        self::assertSame(SymbolLevel::Class_, $rfcExternalDef->collectedAt);
    }

    #[Test]
    public function itCountsExternalCallsSeparately(): void
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

        self::assertSame(5, $metrics->get('coupling.rfc:App\OrderService')); // 2 own + 3 external
        self::assertSame(2, $metrics->get('coupling.rfc-own:App\OrderService'));
        self::assertSame(3, $metrics->get('coupling.rfc-external:App\OrderService'));
    }

    #[Test]
    public function itReturnsClassesWithComputedMetrics(): void
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
        $this->collector->useDeclarationIndex(new FileDeclarationIndex());
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->collector->getVisitor());
        $traverser->traverse($ast);

        $classesWithMetrics = $this->collector->getClassesWithMetrics(\Qualimetrix\Core\Path\RelativePath::fromString('UserService.php'));

        self::assertCount(1, $classesWithMetrics);

        $classMetrics = $classesWithMetrics[0];
        self::assertSame('App\Service', $classMetrics->declarationPath->logical->namespace);
        self::assertSame('UserService', $classMetrics->declarationPath->logical->type);

        $bag = $classMetrics->metrics;
        self::assertSame(3, $bag->get('coupling.rfc')); // 1 own + 2 external
        self::assertSame(1, $bag->get('coupling.rfc-own'));
        self::assertSame(2, $bag->get('coupling.rfc-external'));
    }

    #[Test]
    public function itClearsStateOnReset(): void
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
        self::assertSame(2, $metrics1->get('coupling.rfc:First'));

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
        self::assertNull($metrics2->get('coupling.rfc:First'));
        self::assertSame(1, $metrics2->get('coupling.rfc:Second'));
    }

    #[Test]
    public function itDeliberatelyDoesNotProvideCallableMetrics(): void
    {
        self::assertNotContains(\Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface::class, class_implements($this->collector));
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
