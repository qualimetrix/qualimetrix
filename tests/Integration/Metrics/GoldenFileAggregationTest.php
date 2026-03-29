<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Metrics;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefaults;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * Integration test that runs the full analysis pipeline on fixture files
 * and asserts exact metric values at every hierarchy level.
 *
 * Fixture directory: tests/Fixtures/GoldenMetrics/
 */
#[Group('integration')]
final class GoldenFileAggregationTest extends TestCase
{
    private static MetricRepositoryInterface $repository;

    public static function setUpBeforeClass(): void
    {
        // Populate computed metric definitions (health scores) — normally done by RuntimeConfigurator
        ComputedMetricDefinitionHolder::setDefinitions(array_values(ComputedMetricDefaults::getDefaults()));

        $containerFactory = new ContainerFactory();
        $container = $containerFactory->create();

        /** @var AnalysisPipelineInterface $pipeline */
        $pipeline = $container->get(AnalysisPipelineInterface::class);

        $fixturesPath = \dirname(__DIR__, 2) . '/Fixtures/GoldenMetrics';
        $result = $pipeline->analyze($fixturesPath);

        self::$repository = $result->metrics;
    }

    public static function tearDownAfterClass(): void
    {
        ComputedMetricDefinitionHolder::setDefinitions([]);
    }

    // ──────────────────────────────────────────────────────────────────
    // 1. Method-level complexity
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testMethodLevelComplexity(): void
    {
        $cases = [
            ['GoldenMetrics\App\Repository', 'UserRepository', 'findById', 2, 1, 2],
            ['GoldenMetrics\App\Repository', 'UserRepository', 'findAll', 1, 0, 1],
            ['GoldenMetrics\App\Repository', 'UserRepository', 'save', 3, 3, 4],
            ['GoldenMetrics\App\Repository', 'UserRepository', 'delete', 2, 1, 2],
            ['GoldenMetrics\App\Service\Auth', 'TokenValidator', '__construct', 1, 0, 1],
            ['GoldenMetrics\App\Service\Auth', 'TokenValidator', 'validate', 3, 2, 4],
            ['GoldenMetrics\App\Service\Auth', 'TokenValidator', 'isExpired', 2, 1, 2],
            ['GoldenMetrics\App\Service\Auth', 'SessionManager', 'startSession', 2, 1, 2],
            ['GoldenMetrics\App\Service\Auth', 'SessionManager', 'destroySession', 3, 2, 4],
            ['GoldenMetrics\App\Service', 'UserService', '__construct', 1, 0, 1],
            ['GoldenMetrics\App\Service', 'UserService', 'getUser', 2, 1, 2],
            ['GoldenMetrics\App\Service', 'UserService', 'createUser', 4, 4, 8],
            ['GoldenMetrics\App\Service', 'UserService', 'listUsers', 5, 5, 4],
            ['GoldenMetrics\App\Service', 'OrderService', '__construct', 1, 0, 1],
            ['GoldenMetrics\App\Service', 'OrderService', 'placeOrder', 3, 2, 4],
            ['GoldenMetrics\App\Service', 'OrderService', 'cancelOrder', 2, 1, 2],
            ['', 'GlobalHelper', 'format', 2, 1, 2],
        ];

        foreach ($cases as [$ns, $class, $method, $ccn, $cognitive, $npath]) {
            $metrics = self::$repository->get(
                SymbolPath::forMethod($ns, $class, $method),
            );

            $label = "{$class}::{$method}";
            self::assertSame($ccn, $metrics->get('ccn'), "{$label} ccn");
            self::assertSame($cognitive, $metrics->get('cognitive'), "{$label} cognitive");
            self::assertSame($npath, $metrics->get('npath'), "{$label} npath");
        }

        // Standalone function
        $fnMetrics = self::$repository->get(
            SymbolPath::forGlobalFunction('GoldenMetrics\App\Repository', 'findFirstMatch'),
        );
        self::assertSame(4, $fnMetrics->get('ccn'), 'findFirstMatch ccn');
        self::assertSame(4, $fnMetrics->get('cognitive'), 'findFirstMatch cognitive');
        self::assertSame(6, $fnMetrics->get('npath'), 'findFirstMatch npath');

        // Interface methods (ccn=1, cognitive=0, npath=1)
        foreach (['findById', 'findAll', 'save'] as $ifaceMethod) {
            $metrics = self::$repository->get(
                SymbolPath::forMethod('GoldenMetrics\App\Repository', 'UserRepositoryInterface', $ifaceMethod),
            );
            self::assertSame(1, $metrics->get('ccn'), "UserRepositoryInterface::{$ifaceMethod} ccn");
            self::assertSame(0, $metrics->get('cognitive'), "UserRepositoryInterface::{$ifaceMethod} cognitive");
            self::assertSame(1, $metrics->get('npath'), "UserRepositoryInterface::{$ifaceMethod} npath");
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 2. Class-level aggregation
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testClassLevelAggregation(): void
    {
        // UserRepository
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Repository', 'UserRepository'));
        self::assertSame(8, $m->get('ccn.sum'), 'UserRepository ccn.sum');
        self::assertSame(3, $m->get('ccn.max'), 'UserRepository ccn.max');
        self::assertEqualsWithDelta(2.0, $m->get('ccn.avg'), 0.01, 'UserRepository ccn.avg');
        self::assertSame(8, $m->get('wmc'), 'UserRepository wmc');
        self::assertSame(4, $m->get('methodCount'), 'UserRepository methodCount');
        self::assertSame(1, $m->get('propertyCount'), 'UserRepository propertyCount');
        self::assertSame(55, $m->get('classLoc'), 'UserRepository classLoc');

        // UserRepositoryInterface
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Repository', 'UserRepositoryInterface'));
        self::assertSame(3, $m->get('ccn.sum'), 'UserRepositoryInterface ccn.sum');
        self::assertSame(1, $m->get('ccn.max'), 'UserRepositoryInterface ccn.max');
        self::assertEqualsWithDelta(1.0, $m->get('ccn.avg'), 0.01, 'UserRepositoryInterface ccn.avg');
        self::assertSame(3, $m->get('wmc'), 'UserRepositoryInterface wmc');
        self::assertSame(3, $m->get('methodCount'), 'UserRepositoryInterface methodCount');
        self::assertSame(0, $m->get('propertyCount'), 'UserRepositoryInterface propertyCount');

        // TokenValidator
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service\Auth', 'TokenValidator'));
        self::assertSame(6, $m->get('ccn.sum'), 'TokenValidator ccn.sum');
        self::assertSame(3, $m->get('ccn.max'), 'TokenValidator ccn.max');
        self::assertEqualsWithDelta(2.0, $m->get('ccn.avg'), 0.01, 'TokenValidator ccn.avg');
        self::assertSame(6, $m->get('wmc'), 'TokenValidator wmc');
        self::assertSame(2, $m->get('methodCount'), 'TokenValidator methodCount');
        self::assertSame(2, $m->get('propertyCount'), 'TokenValidator propertyCount');

        // SessionManager
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service\Auth', 'SessionManager'));
        self::assertSame(5, $m->get('ccn.sum'), 'SessionManager ccn.sum');
        self::assertSame(3, $m->get('ccn.max'), 'SessionManager ccn.max');
        self::assertEqualsWithDelta(2.5, $m->get('ccn.avg'), 0.01, 'SessionManager ccn.avg');
        self::assertSame(5, $m->get('wmc'), 'SessionManager wmc');
        self::assertSame(2, $m->get('methodCount'), 'SessionManager methodCount');
        self::assertSame(1, $m->get('propertyCount'), 'SessionManager propertyCount');

        // UserService
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service', 'UserService'));
        self::assertSame(12, $m->get('ccn.sum'), 'UserService ccn.sum');
        self::assertSame(5, $m->get('ccn.max'), 'UserService ccn.max');
        self::assertEqualsWithDelta(3.0, $m->get('ccn.avg'), 0.01, 'UserService ccn.avg');
        self::assertSame(12, $m->get('wmc'), 'UserService wmc');
        self::assertSame(3, $m->get('methodCount'), 'UserService methodCount');
        self::assertSame(2, $m->get('propertyCount'), 'UserService propertyCount');

        // OrderService
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service', 'OrderService'));
        self::assertSame(6, $m->get('ccn.sum'), 'OrderService ccn.sum');
        self::assertSame(3, $m->get('ccn.max'), 'OrderService ccn.max');
        self::assertEqualsWithDelta(2.0, $m->get('ccn.avg'), 0.01, 'OrderService ccn.avg');
        self::assertSame(6, $m->get('wmc'), 'OrderService wmc');
        self::assertSame(3, $m->get('methodCount'), 'OrderService methodCount');
        self::assertSame(1, $m->get('propertyCount'), 'OrderService propertyCount');

        // EmptyMarker
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\ValueObject', 'EmptyMarker'));
        self::assertSame(0, $m->get('methodCount'), 'EmptyMarker methodCount');
        self::assertSame(1, $m->get('propertyCount'), 'EmptyMarker propertyCount');
        self::assertSame(4, $m->get('classLoc'), 'EmptyMarker classLoc');

        // GlobalHelper
        $m = self::$repository->get(SymbolPath::forClass('', 'GlobalHelper'));
        self::assertSame(2, $m->get('ccn.sum'), 'GlobalHelper ccn.sum');
        self::assertSame(2, $m->get('ccn.max'), 'GlobalHelper ccn.max');
        self::assertEqualsWithDelta(2.0, $m->get('ccn.avg'), 0.01, 'GlobalHelper ccn.avg');
        self::assertSame(2, $m->get('wmc'), 'GlobalHelper wmc');
        self::assertSame(1, $m->get('methodCount'), 'GlobalHelper methodCount');
    }

    // ──────────────────────────────────────────────────────────────────
    // 3. Class-level cohesion
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testClassLevelCohesion(): void
    {
        // UserRepository: tcc=1, lcc=1, lcom=1
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Repository', 'UserRepository'));
        self::assertEqualsWithDelta(1.0, $m->get('tcc'), 0.01, 'UserRepository tcc');
        self::assertEqualsWithDelta(1.0, $m->get('lcc'), 0.01, 'UserRepository lcc');
        self::assertSame(1, $m->get('lcom'), 'UserRepository lcom');

        // TokenValidator: tcc=0, lcc=0, lcom=1
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service\Auth', 'TokenValidator'));
        self::assertEqualsWithDelta(0.0, $m->get('tcc'), 0.01, 'TokenValidator tcc');
        self::assertEqualsWithDelta(0.0, $m->get('lcc'), 0.01, 'TokenValidator lcc');
        self::assertSame(1, $m->get('lcom'), 'TokenValidator lcom');

        // SessionManager: tcc=1, lcc=1, lcom=1
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service\Auth', 'SessionManager'));
        self::assertEqualsWithDelta(1.0, $m->get('tcc'), 0.01, 'SessionManager tcc');
        self::assertEqualsWithDelta(1.0, $m->get('lcc'), 0.01, 'SessionManager lcc');
        self::assertSame(1, $m->get('lcom'), 'SessionManager lcom');

        // UserService: tcc=1, lcc=1, lcom=1
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service', 'UserService'));
        self::assertEqualsWithDelta(1.0, $m->get('tcc'), 0.01, 'UserService tcc');
        self::assertEqualsWithDelta(1.0, $m->get('lcc'), 0.01, 'UserService lcc');
        self::assertSame(1, $m->get('lcom'), 'UserService lcom');

        // OrderService: tcc=0, lcc=0, lcom=2
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service', 'OrderService'));
        self::assertEqualsWithDelta(0.0, $m->get('tcc'), 0.01, 'OrderService tcc');
        self::assertEqualsWithDelta(0.0, $m->get('lcc'), 0.01, 'OrderService lcc');
        self::assertSame(2, $m->get('lcom'), 'OrderService lcom');

        // EmptyMarker: lcom=0
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\ValueObject', 'EmptyMarker'));
        self::assertSame(0, $m->get('lcom'), 'EmptyMarker lcom');
    }

    // ──────────────────────────────────────────────────────────────────
    // 4. Class-level coupling
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testClassLevelCoupling(): void
    {
        // UserRepository: cbo=3 (implements UserRepositoryInterface + used by UserService, OrderService)
        // ca=2 (UserService, OrderService), ce=1 (UserRepositoryInterface), instability=1/3
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Repository', 'UserRepository'));
        self::assertSame(3, $m->get('cbo'), 'UserRepository cbo');
        self::assertSame(2, $m->get('ca'), 'UserRepository ca');
        self::assertSame(1, $m->get('ce'), 'UserRepository ce');
        self::assertEqualsWithDelta(1 / 3, $m->get('instability'), 0.001, 'UserRepository instability');

        // UserService: cbo=1 (depends on UserRepository)
        // ca=0 (nobody depends on it), ce=1 (UserRepository), instability=1.0
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service', 'UserService'));
        self::assertSame(1, $m->get('cbo'), 'UserService cbo');
        self::assertSame(0, $m->get('ca'), 'UserService ca');
        self::assertSame(1, $m->get('ce'), 'UserService ce');
        self::assertEqualsWithDelta(1.0, $m->get('instability'), 0.001, 'UserService instability');

        // OrderService: cbo=1 (depends on UserRepository)
        // ca=0 (nobody depends on it), ce=1 (UserRepository), instability=1.0
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service', 'OrderService'));
        self::assertSame(1, $m->get('cbo'), 'OrderService cbo');
        self::assertSame(0, $m->get('ca'), 'OrderService ca');
        self::assertSame(1, $m->get('ce'), 'OrderService ce');
        self::assertEqualsWithDelta(1.0, $m->get('instability'), 0.001, 'OrderService instability');
    }

    // ──────────────────────────────────────────────────────────────────
    // 5. Inheritance metrics
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testInheritanceMetrics(): void
    {
        // SessionManager extends TokenValidator (dit=1)
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service\Auth', 'SessionManager'));
        self::assertSame(1, $m->get('dit'), 'SessionManager dit');

        // TokenValidator (dit=0, no parent)
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service\Auth', 'TokenValidator'));
        self::assertSame(0, $m->get('dit'), 'TokenValidator dit');

        // UserRepository (dit=0)
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Repository', 'UserRepository'));
        self::assertSame(0, $m->get('dit'), 'UserRepository dit');

        // EmptyMarker (dit=0)
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\ValueObject', 'EmptyMarker'));
        self::assertSame(0, $m->get('dit'), 'EmptyMarker dit');
    }

    // ──────────────────────────────────────────────────────────────────
    // 6. File-level metrics
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testFileLevelMetrics(): void
    {
        $fixturesPath = 'tests/Fixtures/GoldenMetrics';

        // UserRepository.php
        $m = self::$repository->get(SymbolPath::forFile("{$fixturesPath}/App/Repository/UserRepository.php"));
        self::assertNotNull($m->get('loc'), 'UserRepository.php loc exists');
        self::assertSame(1, $m->get('classCount'), 'UserRepository.php classCount');

        // UserRepositoryInterface.php
        $m = self::$repository->get(SymbolPath::forFile("{$fixturesPath}/App/Repository/UserRepositoryInterface.php"));
        self::assertSame(1, $m->get('interfaceCount'), 'UserRepositoryInterface.php interfaceCount');

        // UserService.php
        $m = self::$repository->get(SymbolPath::forFile("{$fixturesPath}/App/Service/UserService.php"));
        self::assertNotNull($m->get('loc'), 'UserService.php loc exists');
        self::assertSame(1, $m->get('classCount'), 'UserService.php classCount');

        // global_helper.php
        $m = self::$repository->get(SymbolPath::forFile("{$fixturesPath}/global_helper.php"));
        self::assertNotNull($m->get('loc'), 'global_helper.php loc exists');
        self::assertSame(1, $m->get('classCount'), 'global_helper.php classCount');
    }

    // ──────────────────────────────────────────────────────────────────
    // 7. Leaf namespace aggregation
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testLeafNamespaceAggregation(): void
    {
        // GoldenMetrics\App\Repository
        $m = self::$repository->get(SymbolPath::forNamespace('GoldenMetrics\App\Repository'));
        self::assertSame(15, $m->get('ccn.sum'), 'Repository ccn.sum');
        self::assertSame(4, $m->get('ccn.max'), 'Repository ccn.max');
        self::assertSame(8, $m->get('symbolMethodCount'), 'Repository symbolMethodCount');
        self::assertSame(2, $m->get('symbolClassCount'), 'Repository symbolClassCount');
        self::assertEqualsWithDelta(0.5, $m->get('abstractness'), 0.01, 'Repository abstractness');
        self::assertSame(129, $m->get('loc.sum'), 'Repository loc.sum');

        // GoldenMetrics\App\Service\Auth
        $m = self::$repository->get(SymbolPath::forNamespace('GoldenMetrics\App\Service\Auth'));
        self::assertSame(11, $m->get('ccn.sum'), 'Auth ccn.sum');
        self::assertSame(3, $m->get('ccn.max'), 'Auth ccn.max');
        self::assertSame(5, $m->get('symbolMethodCount'), 'Auth symbolMethodCount');
        self::assertSame(2, $m->get('symbolClassCount'), 'Auth symbolClassCount');
        self::assertSame(133, $m->get('loc.sum'), 'Auth loc.sum');
    }

    // ──────────────────────────────────────────────────────────────────
    // 8. Parent namespace aggregation
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testParentNamespaceAggregation(): void
    {
        // GoldenMetrics\App\Service — has own classes (UserService, OrderService) + child Auth
        $m = self::$repository->get(SymbolPath::forNamespace('GoldenMetrics\App\Service'));
        self::assertSame(29, $m->get('ccn.sum'), 'Service ccn.sum');
        self::assertSame(5, $m->get('ccn.max'), 'Service ccn.max');
        self::assertEqualsWithDelta(2.4167, $m->get('ccn.avg'), 0.01, 'Service ccn.avg');
        self::assertSame(12, $m->get('symbolMethodCount'), 'Service symbolMethodCount');
        self::assertSame(4, $m->get('symbolClassCount'), 'Service symbolClassCount');
        self::assertSame(301, $m->get('loc.sum'), 'Service loc.sum');
    }

    // ──────────────────────────────────────────────────────────────────
    // 9. Root namespace aggregation
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testRootNamespaceAggregation(): void
    {
        // GoldenMetrics\App — root parent with no own classes, aggregates all children
        $m = self::$repository->get(SymbolPath::forNamespace('GoldenMetrics\App'));
        self::assertSame(44, $m->get('ccn.sum'), 'App ccn.sum');
        self::assertSame(5, $m->get('ccn.max'), 'App ccn.max');
        self::assertEqualsWithDelta(2.2, $m->get('ccn.avg'), 0.01, 'App ccn.avg');
        self::assertSame(20, $m->get('symbolMethodCount'), 'App symbolMethodCount');
        self::assertSame(7, $m->get('symbolClassCount'), 'App symbolClassCount');
        self::assertSame(453, $m->get('loc.sum'), 'App loc.sum');
    }

    #[Test]
    public function testSyntheticRootNamespaceAggregation(): void
    {
        // GoldenMetrics — synthetic root with single child (GoldenMetrics\App)
        // Should have identical values to GoldenMetrics\App since it's the only child
        $m = self::$repository->get(SymbolPath::forNamespace('GoldenMetrics'));
        $app = self::$repository->get(SymbolPath::forNamespace('GoldenMetrics\App'));

        self::assertSame($app->get('ccn.sum'), $m->get('ccn.sum'), 'GoldenMetrics ccn.sum = App ccn.sum');
        self::assertSame($app->get('ccn.max'), $m->get('ccn.max'), 'GoldenMetrics ccn.max = App ccn.max');
        self::assertEqualsWithDelta(
            $app->get('ccn.avg'),
            $m->get('ccn.avg'),
            0.01,
            'GoldenMetrics ccn.avg = App ccn.avg',
        );
        self::assertSame($app->get('symbolMethodCount'), $m->get('symbolMethodCount'), 'GoldenMetrics symbolMethodCount');
        self::assertSame($app->get('symbolClassCount'), $m->get('symbolClassCount'), 'GoldenMetrics symbolClassCount');
    }

    // ──────────────────────────────────────────────────────────────────
    // 10. Project-level aggregation
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testProjectLevelAggregation(): void
    {
        $m = self::$repository->get(SymbolPath::forProject());
        self::assertSame(46, $m->get('ccn.sum'), 'project ccn.sum');
        self::assertSame(5, $m->get('ccn.max'), 'project ccn.max');
        self::assertEqualsWithDelta(2.1905, $m->get('ccn.avg'), 0.01, 'project ccn.avg');
        self::assertSame(482, $m->get('loc.sum'), 'project loc.sum');
        self::assertSame(7, $m->get('classCount.sum'), 'project classCount.sum (excludes interfaces)');
        self::assertSame(1, $m->get('interfaceCount.sum'), 'project interfaceCount.sum');
        self::assertSame(21, $m->get('symbolMethodCount'), 'project symbolMethodCount');
        // symbolClassCount includes interfaces (7 classes + 1 interface = 8)
        self::assertSame(8, $m->get('symbolClassCount'), 'project symbolClassCount');
    }

    // ──────────────────────────────────────────────────────────────────
    // 11. Health scores
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testHealthScoresExist(): void
    {
        $m = self::$repository->get(SymbolPath::forProject());

        $healthMetrics = [
            'health.complexity',
            'health.cohesion',
            'health.coupling',
            'health.typing',
            'health.maintainability',
            'health.overall',
        ];

        foreach ($healthMetrics as $metric) {
            $value = $m->get($metric);
            self::assertNotNull($value, "{$metric} should exist");
            self::assertGreaterThanOrEqual(0, $value, "{$metric} >= 0");
            self::assertLessThanOrEqual(100, $value, "{$metric} <= 100");
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 12. Global namespace handling
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function testGlobalNamespaceHandling(): void
    {
        // GlobalHelper is in the global namespace (empty string)
        $m = self::$repository->get(SymbolPath::forClass('', 'GlobalHelper'));
        self::assertSame(2, $m->get('ccn.sum'), 'GlobalHelper ccn.sum');
        self::assertSame(1, $m->get('methodCount'), 'GlobalHelper methodCount');

        // Global namespace aggregation
        $nsMetrics = self::$repository->get(SymbolPath::forNamespace(''));
        self::assertSame(2, $nsMetrics->get('ccn.sum'), 'global ns ccn.sum');
        self::assertSame(1, $nsMetrics->get('symbolMethodCount'), 'global ns symbolMethodCount');
        self::assertSame(1, $nsMetrics->get('symbolClassCount'), 'global ns symbolClassCount');
        self::assertSame(29, $nsMetrics->get('loc.sum'), 'global ns loc.sum');
    }
}
