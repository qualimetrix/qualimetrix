<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Integration\Aggregation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
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
        $containerFactory = new ContainerFactory();
        $container = $containerFactory->create();
        $fixturesPath = \dirname(__DIR__, 2) . '/Fixtures/GoldenMetrics';
        $fixtureRoot = AbsolutePath::fromString($fixturesPath);
        $document = new ConfigurationDocument([], $fixtureRoot);

        /** @var ComputedMetricConfiguratorInterface $computedMetrics */
        $computedMetrics = $container->get(ComputedMetricConfiguratorInterface::class);
        $computedMetrics->replace($computedMetrics->resolve($document));

        /** @var ArchitecturePolicyConfiguratorInterface $architecturePolicy */
        $architecturePolicy = $container->get(ArchitecturePolicyConfiguratorInterface::class);
        $architecturePolicy->replace($architecturePolicy->resolve($document));

        /** @var AnalysisPipelineInterface $pipeline */
        $pipeline = $container->get(AnalysisPipelineInterface::class);

        $root = AbsolutePath::fromString((string) getcwd());
        $result = $pipeline->analyze(new \Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration([$fixtureRoot], [], $root, \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Include));

        self::$repository = $result->metrics;
    }

    // ──────────────────────────────────────────────────────────────────
    // 1. Method-level complexity
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function itVerifiesMethodLevelComplexity(): void
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
            // foreach wrapper: body if=4 (|| + ?? + assignment + skip), plus loop exit path = 5.
            ['GoldenMetrics\App\Service', 'UserService', 'listUsers', 5, 5, 5],
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
            self::assertSame($ccn, $metrics->get('complexity.ccn'), "{$label} ccn");
            self::assertSame($cognitive, $metrics->get('complexity.cognitive'), "{$label} cognitive");
            self::assertSame($npath, $metrics->get('complexity.npath'), "{$label} npath");
        }

        // Standalone function
        $fnMetrics = self::$repository->get(
            SymbolPath::forGlobalFunction('GoldenMetrics\App\Repository', 'findFirstMatch'),
        );
        self::assertSame(4, $fnMetrics->get('complexity.ccn'), 'findFirstMatch ccn');
        self::assertSame(4, $fnMetrics->get('complexity.cognitive'), 'findFirstMatch cognitive');
        self::assertSame(6, $fnMetrics->get('complexity.npath'), 'findFirstMatch npath');

        // Interface methods (ccn=1, cognitive=0, npath=1)
        foreach (['findById', 'findAll', 'save'] as $ifaceMethod) {
            $metrics = self::$repository->get(
                SymbolPath::forMethod('GoldenMetrics\App\Repository', 'UserRepositoryInterface', $ifaceMethod),
            );
            self::assertSame(1, $metrics->get('complexity.ccn'), "UserRepositoryInterface::{$ifaceMethod} ccn");
            self::assertSame(0, $metrics->get('complexity.cognitive'), "UserRepositoryInterface::{$ifaceMethod} cognitive");
            self::assertSame(1, $metrics->get('complexity.npath'), "UserRepositoryInterface::{$ifaceMethod} npath");
        }
    }

    #[Test]
    public function itVerifiesMethodStatementCountsAndMaintainabilityIndex(): void
    {
        $cases = [
            ['GoldenMetrics\App\Repository', 'UserRepository', 'findById', 3, 77.3835],
            ['GoldenMetrics\App\Service', 'UserService', 'createUser', 11, 60.1192],
            ['GoldenMetrics\App\Repository', 'UserRepositoryInterface', 'findById', 0, 100.0],
        ];

        foreach ($cases as [$namespace, $class, $method, $statementCount, $mi]) {
            $metrics = self::$repository->get(SymbolPath::forMethod($namespace, $class, $method));
            $label = "{$class}::{$method}";

            self::assertSame($statementCount, $metrics->get('size.method-statement-count'), "{$label} statement count");
            self::assertEqualsWithDelta($mi, $metrics->get('maintainability.mi'), 0.0001, "{$label} MI");
        }

        $function = self::$repository->get(
            SymbolPath::forGlobalFunction('GoldenMetrics\App\Repository', 'findFirstMatch'),
        );
        self::assertSame(6, $function->get('size.method-statement-count'), 'findFirstMatch statement count');
        self::assertEqualsWithDelta(69.5784, $function->get('maintainability.mi'), 0.0001, 'findFirstMatch MI');

        $project = self::$repository->get(SymbolPath::forProject());
        self::assertSame(75, $project->get('size.method-statement-count.sum'), 'project statement count sum');
        self::assertEqualsWithDelta(3.5714, $project->get('size.method-statement-count.avg'), 0.0001, 'project statement count average');
        self::assertSame(11, $project->get('size.method-statement-count.max'), 'project maximum method statement count');
    }

    // ──────────────────────────────────────────────────────────────────
    // 2. Class-level aggregation
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function itVerifiesClassLevelAggregation(): void
    {
        // UserRepository
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Repository', 'UserRepository'));
        self::assertSame(8, $m->get('complexity.ccn.sum'), 'UserRepository ccn.sum');
        self::assertSame(3, $m->get('complexity.ccn.max'), 'UserRepository ccn.max');
        self::assertEqualsWithDelta(2.0, $m->get('complexity.ccn.avg'), 0.01, 'UserRepository ccn.avg');
        self::assertSame(8, $m->get('complexity.wmc'), 'UserRepository wmc');
        self::assertSame(4, $m->get('size.method-count'), 'UserRepository methodCount');
        self::assertSame(1, $m->get('size.property-count'), 'UserRepository propertyCount');
        self::assertSame(55, $m->get('size.class-loc'), 'UserRepository classLoc');

        // UserRepositoryInterface
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Repository', 'UserRepositoryInterface'));
        self::assertSame(3, $m->get('complexity.ccn.sum'), 'UserRepositoryInterface ccn.sum');
        self::assertSame(1, $m->get('complexity.ccn.max'), 'UserRepositoryInterface ccn.max');
        self::assertEqualsWithDelta(1.0, $m->get('complexity.ccn.avg'), 0.01, 'UserRepositoryInterface ccn.avg');
        self::assertSame(3, $m->get('complexity.wmc'), 'UserRepositoryInterface wmc');
        self::assertSame(3, $m->get('size.method-count'), 'UserRepositoryInterface methodCount');
        self::assertSame(0, $m->get('size.property-count'), 'UserRepositoryInterface propertyCount');

        // TokenValidator
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service\Auth', 'TokenValidator'));
        self::assertSame(6, $m->get('complexity.ccn.sum'), 'TokenValidator ccn.sum');
        self::assertSame(3, $m->get('complexity.ccn.max'), 'TokenValidator ccn.max');
        self::assertEqualsWithDelta(2.0, $m->get('complexity.ccn.avg'), 0.01, 'TokenValidator ccn.avg');
        self::assertSame(6, $m->get('complexity.wmc'), 'TokenValidator wmc');
        self::assertSame(2, $m->get('size.method-count'), 'TokenValidator methodCount');
        self::assertSame(2, $m->get('size.property-count'), 'TokenValidator propertyCount');

        // SessionManager
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service\Auth', 'SessionManager'));
        self::assertSame(5, $m->get('complexity.ccn.sum'), 'SessionManager ccn.sum');
        self::assertSame(3, $m->get('complexity.ccn.max'), 'SessionManager ccn.max');
        self::assertEqualsWithDelta(2.5, $m->get('complexity.ccn.avg'), 0.01, 'SessionManager ccn.avg');
        self::assertSame(5, $m->get('complexity.wmc'), 'SessionManager wmc');
        self::assertSame(2, $m->get('size.method-count'), 'SessionManager methodCount');
        self::assertSame(1, $m->get('size.property-count'), 'SessionManager propertyCount');

        // UserService
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service', 'UserService'));
        self::assertSame(12, $m->get('complexity.ccn.sum'), 'UserService ccn.sum');
        self::assertSame(5, $m->get('complexity.ccn.max'), 'UserService ccn.max');
        self::assertEqualsWithDelta(3.0, $m->get('complexity.ccn.avg'), 0.01, 'UserService ccn.avg');
        self::assertSame(12, $m->get('complexity.wmc'), 'UserService wmc');
        self::assertSame(3, $m->get('size.method-count'), 'UserService methodCount');
        self::assertSame(2, $m->get('size.property-count'), 'UserService propertyCount');

        // OrderService
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service', 'OrderService'));
        self::assertSame(6, $m->get('complexity.ccn.sum'), 'OrderService ccn.sum');
        self::assertSame(3, $m->get('complexity.ccn.max'), 'OrderService ccn.max');
        self::assertEqualsWithDelta(2.0, $m->get('complexity.ccn.avg'), 0.01, 'OrderService ccn.avg');
        self::assertSame(6, $m->get('complexity.wmc'), 'OrderService wmc');
        self::assertSame(3, $m->get('size.method-count'), 'OrderService methodCount');
        self::assertSame(1, $m->get('size.property-count'), 'OrderService propertyCount');

        // EmptyMarker
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\ValueObject', 'EmptyMarker'));
        self::assertSame(0, $m->get('size.method-count'), 'EmptyMarker methodCount');
        self::assertSame(1, $m->get('size.property-count'), 'EmptyMarker propertyCount');
        self::assertSame(4, $m->get('size.class-loc'), 'EmptyMarker classLoc');

        // GlobalHelper
        $m = self::$repository->get(SymbolPath::forClass('', 'GlobalHelper'));
        self::assertSame(2, $m->get('complexity.ccn.sum'), 'GlobalHelper ccn.sum');
        self::assertSame(2, $m->get('complexity.ccn.max'), 'GlobalHelper ccn.max');
        self::assertEqualsWithDelta(2.0, $m->get('complexity.ccn.avg'), 0.01, 'GlobalHelper ccn.avg');
        self::assertSame(2, $m->get('complexity.wmc'), 'GlobalHelper wmc');
        self::assertSame(1, $m->get('size.method-count'), 'GlobalHelper methodCount');
    }

    // ──────────────────────────────────────────────────────────────────
    // 3. Class-level cohesion
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function itVerifiesClassLevelCohesion(): void
    {
        // UserRepository: tcc=1, lcc=1, lcom=1
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Repository', 'UserRepository'));
        self::assertEqualsWithDelta(1.0, $m->get('cohesion.tcc'), 0.01, 'UserRepository tcc');
        self::assertEqualsWithDelta(1.0, $m->get('cohesion.lcc'), 0.01, 'UserRepository lcc');
        self::assertSame(1, $m->get('cohesion.lcom'), 'UserRepository lcom');

        // TokenValidator: tcc=0, lcc=0, lcom=2 (validate/isExpired touch different
        // properties and don't call each other; __construct is excluded from the
        // graph, so it can no longer bridge them the way it did before)
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service\Auth', 'TokenValidator'));
        self::assertEqualsWithDelta(0.0, $m->get('cohesion.tcc'), 0.01, 'TokenValidator tcc');
        self::assertEqualsWithDelta(0.0, $m->get('cohesion.lcc'), 0.01, 'TokenValidator lcc');
        self::assertSame(2, $m->get('cohesion.lcom'), 'TokenValidator lcom');

        // SessionManager: tcc=1, lcc=1, lcom=1
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service\Auth', 'SessionManager'));
        self::assertEqualsWithDelta(1.0, $m->get('cohesion.tcc'), 0.01, 'SessionManager tcc');
        self::assertEqualsWithDelta(1.0, $m->get('cohesion.lcc'), 0.01, 'SessionManager lcc');
        self::assertSame(1, $m->get('cohesion.lcom'), 'SessionManager lcom');

        // UserService: tcc=1, lcc=1, lcom=1
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service', 'UserService'));
        self::assertEqualsWithDelta(1.0, $m->get('cohesion.tcc'), 0.01, 'UserService tcc');
        self::assertEqualsWithDelta(1.0, $m->get('cohesion.lcc'), 0.01, 'UserService lcc');
        self::assertSame(1, $m->get('cohesion.lcom'), 'UserService lcom');

        // OrderService: tcc=0, lcc=0, lcom=2
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service', 'OrderService'));
        self::assertEqualsWithDelta(0.0, $m->get('cohesion.tcc'), 0.01, 'OrderService tcc');
        self::assertEqualsWithDelta(0.0, $m->get('cohesion.lcc'), 0.01, 'OrderService lcc');
        self::assertSame(2, $m->get('cohesion.lcom'), 'OrderService lcom');

        // EmptyMarker: lcom=0
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\ValueObject', 'EmptyMarker'));
        self::assertSame(0, $m->get('cohesion.lcom'), 'EmptyMarker lcom');
    }

    // ──────────────────────────────────────────────────────────────────
    // 4. Class-level coupling
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function itVerifiesClassLevelCoupling(): void
    {
        // UserRepository: cbo=3 (implements UserRepositoryInterface + used by UserService, OrderService)
        // ca=2 (UserService, OrderService), ce=1 (UserRepositoryInterface), instability=1/3
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Repository', 'UserRepository'));
        self::assertSame(3, $m->get('coupling.cbo'), 'UserRepository cbo');
        self::assertSame(2, $m->get('coupling.ca'), 'UserRepository ca');
        self::assertSame(1, $m->get('coupling.ce'), 'UserRepository ce');
        self::assertEqualsWithDelta(1 / 3, $m->get('coupling.instability'), 0.001, 'UserRepository instability');

        // UserService: cbo=1 (depends on UserRepository)
        // ca=0 (nobody depends on it), ce=1 (UserRepository), instability=1.0
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service', 'UserService'));
        self::assertSame(1, $m->get('coupling.cbo'), 'UserService cbo');
        self::assertSame(0, $m->get('coupling.ca'), 'UserService ca');
        self::assertSame(1, $m->get('coupling.ce'), 'UserService ce');
        self::assertEqualsWithDelta(1.0, $m->get('coupling.instability'), 0.001, 'UserService instability');

        // OrderService: cbo=1 (depends on UserRepository)
        // ca=0 (nobody depends on it), ce=1 (UserRepository), instability=1.0
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service', 'OrderService'));
        self::assertSame(1, $m->get('coupling.cbo'), 'OrderService cbo');
        self::assertSame(0, $m->get('coupling.ca'), 'OrderService ca');
        self::assertSame(1, $m->get('coupling.ce'), 'OrderService ce');
        self::assertEqualsWithDelta(1.0, $m->get('coupling.instability'), 0.001, 'OrderService instability');
    }

    // ──────────────────────────────────────────────────────────────────
    // 5. Inheritance metrics
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function itVerifiesInheritanceMetrics(): void
    {
        // SessionManager extends TokenValidator (dit=1)
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service\Auth', 'SessionManager'));
        self::assertSame(1, $m->get('design.dit'), 'SessionManager dit');

        // TokenValidator (dit=0, no parent)
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Service\Auth', 'TokenValidator'));
        self::assertSame(0, $m->get('design.dit'), 'TokenValidator dit');

        // UserRepository (dit=0)
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\Repository', 'UserRepository'));
        self::assertSame(0, $m->get('design.dit'), 'UserRepository dit');

        // EmptyMarker (dit=0)
        $m = self::$repository->get(SymbolPath::forClass('GoldenMetrics\App\ValueObject', 'EmptyMarker'));
        self::assertSame(0, $m->get('design.dit'), 'EmptyMarker dit');
    }

    // ──────────────────────────────────────────────────────────────────
    // 6. File-level metrics
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function itVerifiesFileLevelMetrics(): void
    {
        $fixturesPath = 'tests/Analysis/Evidence/Measurement/Fixtures/GoldenMetrics';

        // UserRepository.php
        $m = self::$repository->get(SymbolPath::forFile(RelativePath::fromString("{$fixturesPath}/App/Repository/UserRepository.php")));
        self::assertNotNull($m->get('size.loc'), 'UserRepository.php loc exists');
        self::assertSame(1, $m->get('size.class-count'), 'UserRepository.php classCount');

        // UserRepositoryInterface.php
        $m = self::$repository->get(SymbolPath::forFile(RelativePath::fromString("{$fixturesPath}/App/Repository/UserRepositoryInterface.php")));
        self::assertSame(1, $m->get('size.interface-count'), 'UserRepositoryInterface.php interfaceCount');

        // UserService.php
        $m = self::$repository->get(SymbolPath::forFile(RelativePath::fromString("{$fixturesPath}/App/Service/UserService.php")));
        self::assertNotNull($m->get('size.loc'), 'UserService.php loc exists');
        self::assertSame(1, $m->get('size.class-count'), 'UserService.php classCount');

        // global_helper.php
        $m = self::$repository->get(SymbolPath::forFile(RelativePath::fromString("{$fixturesPath}/global_helper.php")));
        self::assertNotNull($m->get('size.loc'), 'global_helper.php loc exists');
        self::assertSame(1, $m->get('size.class-count'), 'global_helper.php classCount');
    }

    // ──────────────────────────────────────────────────────────────────
    // 7. Leaf namespace aggregation
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function itVerifiesLeafNamespaceAggregation(): void
    {
        // GoldenMetrics\App\Repository
        $m = self::$repository->get(SymbolPath::forNamespace('GoldenMetrics\App\Repository'));
        self::assertSame(15, $m->get('complexity.ccn.sum'), 'Repository ccn.sum');
        self::assertSame(4, $m->get('complexity.ccn.max'), 'Repository ccn.max');
        self::assertSame(8, $m->get(MetricName::SIZE_SYMBOL_METHOD_COUNT), 'Repository symbolMethodCount');
        self::assertSame(2, $m->get(MetricName::SIZE_SYMBOL_CLASS_COUNT), 'Repository symbolClassCount');
        self::assertEqualsWithDelta(0.5, $m->get('coupling.abstractness'), 0.01, 'Repository abstractness');
        self::assertSame(114, $m->get('size.loc.sum'), 'Repository loc.sum = namespace spans 71 + 19 + 24');

        // GoldenMetrics\App\Service\Auth
        $m = self::$repository->get(SymbolPath::forNamespace('GoldenMetrics\App\Service\Auth'));
        self::assertSame(11, $m->get('complexity.ccn.sum'), 'Auth ccn.sum');
        self::assertSame(3, $m->get('complexity.ccn.max'), 'Auth ccn.max');
        self::assertSame(5, $m->get(MetricName::SIZE_SYMBOL_METHOD_COUNT), 'Auth symbolMethodCount');
        self::assertSame(2, $m->get(MetricName::SIZE_SYMBOL_CLASS_COUNT), 'Auth symbolClassCount');
        self::assertSame(123.0, $m->get('size.loc.sum'), 'Auth loc.sum = namespace spans 57 + 66');
    }

    // ──────────────────────────────────────────────────────────────────
    // 8. Parent namespace aggregation
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function itVerifiesParentNamespaceAggregation(): void
    {
        // GoldenMetrics\App\Service — has own classes (UserService, OrderService) + child Auth
        $m = self::$repository->get(SymbolPath::forNamespace('GoldenMetrics\App\Service'));
        self::assertSame(29, $m->get('complexity.ccn.sum'), 'Service ccn.sum');
        self::assertSame(5, $m->get('complexity.ccn.max'), 'Service ccn.max');
        self::assertEqualsWithDelta(2.4167, $m->get('complexity.ccn.avg'), 0.01, 'Service ccn.avg');
        self::assertSame(12, $m->get(MetricName::SIZE_SYMBOL_METHOD_COUNT), 'Service symbolMethodCount');
        self::assertSame(4, $m->get(MetricName::SIZE_SYMBOL_CLASS_COUNT), 'Service symbolClassCount');
        self::assertSame(281.0, $m->get('size.loc.sum'), 'Service loc.sum = own spans 95 + 63 + Auth spans 57 + 66');
    }

    // ──────────────────────────────────────────────────────────────────
    // 9. Root namespace aggregation
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function itVerifiesRootNamespaceAggregation(): void
    {
        // GoldenMetrics\App — root parent with no own classes, aggregates all children
        $m = self::$repository->get(SymbolPath::forNamespace('GoldenMetrics\App'));
        self::assertSame(44, $m->get('complexity.ccn.sum'), 'App ccn.sum');
        self::assertSame(5, $m->get('complexity.ccn.max'), 'App ccn.max');
        self::assertEqualsWithDelta(2.2, $m->get('complexity.ccn.avg'), 0.01, 'App ccn.avg');
        self::assertSame(20, $m->get(MetricName::SIZE_SYMBOL_METHOD_COUNT), 'App symbolMethodCount');
        self::assertSame(7, $m->get(MetricName::SIZE_SYMBOL_CLASS_COUNT), 'App symbolClassCount');
        self::assertSame(413.0, $m->get('size.loc.sum'), 'App loc.sum = Repository 114 + Service 281 + ValueObject 18');
    }

    #[Test]
    public function itVerifiesSyntheticRootNamespaceAggregation(): void
    {
        // GoldenMetrics — synthetic root with single child (GoldenMetrics\App)
        // Should have identical values to GoldenMetrics\App since it's the only child
        $m = self::$repository->get(SymbolPath::forNamespace('GoldenMetrics'));
        $app = self::$repository->get(SymbolPath::forNamespace('GoldenMetrics\App'));

        self::assertSame($app->get('complexity.ccn.sum'), $m->get('complexity.ccn.sum'), 'GoldenMetrics ccn.sum = App ccn.sum');
        self::assertSame($app->get('complexity.ccn.max'), $m->get('complexity.ccn.max'), 'GoldenMetrics ccn.max = App ccn.max');
        self::assertEqualsWithDelta(
            $app->get('complexity.ccn.avg'),
            $m->get('complexity.ccn.avg'),
            0.01,
            'GoldenMetrics ccn.avg = App ccn.avg',
        );
        self::assertSame($app->get(MetricName::SIZE_SYMBOL_METHOD_COUNT), $m->get(MetricName::SIZE_SYMBOL_METHOD_COUNT), 'GoldenMetrics m["size.symbol-method-count"]');
        self::assertSame($app->get(MetricName::SIZE_SYMBOL_CLASS_COUNT), $m->get(MetricName::SIZE_SYMBOL_CLASS_COUNT), 'GoldenMetrics symbolClassCount');
    }

    // ──────────────────────────────────────────────────────────────────
    // 10. Project-level aggregation
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function itVerifiesProjectLevelAggregation(): void
    {
        $m = self::$repository->get(SymbolPath::forProject());
        self::assertSame(46, $m->get('complexity.ccn.sum'), 'project ccn.sum');
        self::assertSame(5, $m->get('complexity.ccn.max'), 'project ccn.max');
        self::assertEqualsWithDelta(2.1905, $m->get('complexity.ccn.avg'), 0.01, 'project ccn.avg');
        self::assertSame(482, $m->get('size.loc.sum'), 'project loc.sum counts each physical file once');
        self::assertSame(7, $m->get('size.class-count.sum'), 'project classCount.sum (excludes interfaces)');
        self::assertSame(1, $m->get('size.interface-count.sum'), 'project interfaceCount.sum');
        self::assertSame(21, $m->get(MetricName::SIZE_SYMBOL_METHOD_COUNT), 'project m["size.symbol-method-count"]');
        // symbolClassCount includes interfaces (7 classes + 1 interface = 8)
        self::assertSame(8, $m->get(MetricName::SIZE_SYMBOL_CLASS_COUNT), 'project symbolClassCount');
    }

    // ──────────────────────────────────────────────────────────────────
    // 11. Health scores
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function itConfirmsHealthScoresExist(): void
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

    #[Test]
    public function itKeepsClassDerivedTypingMetricsOnExactClassDeclarations(): void
    {
        $class = self::$repository->get(SymbolPath::forClass('GoldenMetrics\\App\\Repository', 'UserRepository'));
        self::assertSame(100.0, $class->get('design.type-coverage.pct'));
        self::assertSame(100.0, $class->get('health.typing'));

        $method = self::$repository->get(
            SymbolPath::forMethod('GoldenMetrics\\App\\Repository', 'UserRepository', 'findById'),
        );
        self::assertNull($method->get('design.type-coverage.pct'));

        $emptyClass = self::$repository->get(SymbolPath::forClass('GoldenMetrics\\App\\ValueObject', 'EmptyMarker'));
        self::assertSame(100.0, $emptyClass->get('design.type-coverage.pct'), 'empty class surface is fully typed');
    }

    // ──────────────────────────────────────────────────────────────────
    // 12. Global namespace handling
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function itHandlesGlobalNamespace(): void
    {
        // GlobalHelper is in the global namespace (empty string)
        $m = self::$repository->get(SymbolPath::forClass('', 'GlobalHelper'));
        self::assertSame(2, $m->get('complexity.ccn.sum'), 'GlobalHelper ccn.sum');
        self::assertSame(1, $m->get('size.method-count'), 'GlobalHelper methodCount');

        // Global namespace aggregation
        $nsMetrics = self::$repository->get(SymbolPath::forNamespace(''));
        self::assertSame(2, $nsMetrics->get('complexity.ccn.sum'), 'global ns ccn.sum');
        self::assertSame(1, $nsMetrics->get(MetricName::SIZE_SYMBOL_METHOD_COUNT), 'global ns m["size.symbol-method-count"]');
        self::assertSame(1, $nsMetrics->get(MetricName::SIZE_SYMBOL_CLASS_COUNT), 'global ns symbolClassCount');
        self::assertSame(29, $nsMetrics->get('size.loc.sum'), 'global ns loc.sum');
    }
}
