<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Cohesion\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Cohesion\CohesionCompositionCollector;
use Qualimetrix\Analysis\Evidence\Cohesion\CohesionFactWire;
use Qualimetrix\Analysis\Evidence\Cohesion\CohesionMethodReachability;
use Qualimetrix\Analysis\Evidence\Cohesion\CohesionTraitComposition;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomClassData;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomCollector;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomVisitor;
use Qualimetrix\Analysis\Evidence\Cohesion\TccLccClassData;
use Qualimetrix\Analysis\Evidence\Cohesion\TccLccCollector;
use Qualimetrix\Analysis\Evidence\Cohesion\TccLccVisitor;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MeasurementAggregationService;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Ast\NameResolution;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitUseFacts;

#[CoversClass(CohesionCompositionCollector::class)]
final class CohesionSemanticCompositionTest extends TestCase
{
    #[Test]
    public function itDeclaresRunReachForEveryComposedMetric(): void
    {
        $collector = new CohesionCompositionCollector();
        $catalog = new MeasurementAggregationService(
            [$collector],
            new CompositeCollector([new LcomCollector(), new TccLccCollector()], new DeclarationRegistrarFactory()),
            self::createStub(ProfilerInterface::class),
        );

        self::assertSame(MetricReach::Run, $collector->getMetricReach());
        foreach ($collector->provides() as $key) {
            self::assertSame(MetricReach::Run, $catalog->metricReach($key));
        }
    }

    #[Test]
    public function itChangesClassEvidenceWhenTheTraitFileIsNotSelected(): void
    {
        $bridge = new LcomClassData('App', 'Bridge');
        $bridge->declaration()->declareMethod('bridge', 'public', false);
        $bridge->addMethod('bridge');
        $bridge->addPropertyAccess('bridge', 'p');
        $bridge->addPropertyAccess('bridge', 'q');

        $class = new LcomClassData('App', 'Candidate');
        $class->declaration()->addTraitUse(new TraitUseFacts(['App\\Bridge']));
        foreach (['readP' => 'p', 'readQ' => 'q'] as $method => $property) {
            $class->declaration()->declareMethod($method, 'public', false);
            $class->addMethod($method);
            $class->addPropertyAccess($method, $property);
        }
        $class->declaration()->incrementInstancePropertyCount(2);
        $tcc = new TccLccClassData('App', 'Candidate');
        $tcc->incrementPropertyCount(2);

        $bothFiles = self::calculate(['Candidate' => [$class, $tcc]], ['Bridge' => $bridge]);
        $classFileOnly = self::calculate(['Candidate' => [$class, $tcc]], []);

        self::assertSame(0, $bothFiles['Candidate']->get('cohesion.unread-trait-count'));
        self::assertSame(1, $bothFiles['Candidate']->get('cohesion.lcom'));
        self::assertSame(3, $bothFiles['Candidate']->get('cohesion.lcom-method-count'));
        self::assertSame(0.667, $bothFiles['Candidate']->get('cohesion.tcc'));
        self::assertSame(1, $classFileOnly['Candidate']->get('cohesion.unread-trait-count'));
        self::assertSame(2, $classFileOnly['Candidate']->get('cohesion.lcom'));
        self::assertSame(2, $classFileOnly['Candidate']->get('cohesion.lcom-method-count'));
        self::assertSame(0.0, $classFileOnly['Candidate']->get('cohesion.tcc'));
    }

    #[Test]
    public function itCarriesResolvedTraitAdaptationsAcrossRawRows(): void
    {
        $source = <<<'PHP'
<?php
namespace Demo;
use Vendor\A as Imported;
trait Local { public function hello(): int { return 1; } }
class Candidate {
    use Imported, Local {
        Local::hello insteadof Imported;
        Imported::hello as protected helloImported;
    }
}
PHP;
        $ast = (new ParserFactory())->createForHostVersion()->parse($source) ?? [];
        NameResolution::resolve($ast);
        $visitor = new LcomVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        $candidate = array_values($visitor->getClassData())[1];
        $decoded = CohesionFactWire::lcomFromRows(unserialize(serialize(CohesionFactWire::lcomRows($candidate))));
        $uses = $decoded->declaration()->traitUses();

        self::assertCount(1, $uses);
        self::assertSame(['Vendor\A', 'Demo\Local'], $uses[0]->traits);
        self::assertSame('Demo\Local', $uses[0]->precedences[0]['trait']);
        self::assertSame(['Vendor\A'], $uses[0]->precedences[0]['insteadOf']);
        self::assertSame('helloImported', $uses[0]->aliases[0]['alias']);
        self::assertSame('protected', $uses[0]->aliases[0]['visibility']);
    }

    #[Test]
    public function itOmitsLcomWithoutVerticesAndPublishesCompleteness(): void
    {
        $empty = new LcomClassData('App', 'Empty');
        $metrics = self::calculate(['Empty' => [$empty, new TccLccClassData('App', 'Empty')]], []);

        self::assertNull($metrics['Empty']->get('cohesion.lcom'));
        self::assertSame(0, $metrics['Empty']->get('cohesion.lcom-method-count'));
        self::assertSame(0, $metrics['Empty']->get('cohesion.tcc-method-count'));
        self::assertSame(0, $metrics['Empty']->get('cohesion.unread-trait-count'));
    }

    #[Test]
    public function itRetainsCalledStatelessMethodsAsVertices(): void
    {
        $class = new LcomClassData('App', 'Calls');
        foreach (['work', 'read', 'getName', 'getDescription'] as $name) {
            $class->addMethod($name);
        }
        $class->addPropertyAccess('work', 'value');
        $class->addPropertyAccess('read', 'value');
        $class->addMethodCall('work', 'GETNAME');
        $class->markStatelessConstant('getName');
        $class->markStatelessConstant('getDescription');
        $metrics = self::calculate(['Calls' => [$class, new TccLccClassData('App', 'Calls')]], []);

        self::assertSame(2, $metrics['Calls']->get('cohesion.lcom'));
        self::assertSame(4, $metrics['Calls']->get('cohesion.lcom-method-count'));
    }

    #[Test]
    public function itComposesAdaptedMethodsAndTracksUnreadTraits(): void
    {
        $a = new LcomClassData('App', 'A');
        $a->declaration()->declareMethod('hello', 'public', false);
        $a->addMethod('hello');
        $a->addPropertyAccess('hello', 'p');
        $b = new LcomClassData('App', 'B');
        $b->declaration()->declareMethod('hello', 'public', false);
        $b->addMethod('hello');
        $b->addPropertyAccess('hello', 'q');
        $class = new LcomClassData('App', 'Candidate');
        $class->declaration()->addTraitUse(new TraitUseFacts(
            ['App\\A', 'App\\B', 'Vendor\\Unavailable'],
            [['trait' => 'App\\A', 'method' => 'hello', 'alias' => 'helloA', 'visibility' => 'protected']],
            [['trait' => 'App\\B', 'method' => 'hello', 'insteadOf' => ['App\\A']]],
        ));
        $class->declaration()->declareMethod('readP', 'public', false);
        $class->addMethod('readP');
        $class->addMethodCall('readP', 'helloA');
        $class->declaration()->incrementInstancePropertyCount();
        $tcc = new TccLccClassData('App', 'Candidate');
        $tcc->incrementPropertyCount();

        $metrics = self::calculate(['Candidate' => [$class, $tcc]], ['A' => $a, 'B' => $b]);

        self::assertSame(2, $metrics['Candidate']->get('cohesion.lcom'));
        self::assertSame(3, $metrics['Candidate']->get('cohesion.lcom-method-count'));
        self::assertSame(2, $metrics['Candidate']->get('cohesion.tcc-method-count'));
        self::assertSame(1, $metrics['Candidate']->get('cohesion.unread-trait-count'));
        self::assertSame(0.0, $metrics['Candidate']->get('cohesion.tcc'));
    }

    #[Test]
    public function itFollowsPrivateHelpersForTccAndPureMethodCount(): void
    {
        $class = new LcomClassData('App', 'Delegating');
        foreach (['one', 'two'] as $method) {
            $class->declaration()->declareMethod($method, 'public', false);
            $class->addMethod($method);
            $class->addMethodCall($method, 'helper');
        }
        $class->declaration()->declareMethod('helper', 'private', false);
        $class->addMethod('helper');
        $class->addMethodCall('helper', 'propertyHelper');
        $class->declaration()->declareMethod('propertyHelper', 'private', false);
        $class->addMethod('propertyHelper');
        $class->addPropertyAccess('propertyHelper', 'inherited');

        $metrics = self::calculate(['Delegating' => [$class, new TccLccClassData('App', 'Delegating')]], []);

        self::assertSame(2, $metrics['Delegating']->get('cohesion.tcc-method-count'));
        self::assertSame(1.0, $metrics['Delegating']->get('cohesion.tcc'));
        self::assertSame(1.0, $metrics['Delegating']->get('cohesion.lcc'));
        self::assertSame(0, $metrics['Delegating']->get('cohesion.pure-method-count'));
    }

    #[Test]
    public function itReachesInstancePropertiesThroughMixedCaseCyclesWithoutFollowingOtherCalls(): void
    {
        $class = new LcomClassData('App', 'Recursive');
        foreach (['entry', 'Helper', 'Tail', 'staticHelper'] as $method) {
            $class->addMethod($method);
        }
        $class->addPropertyAccess('entry', 'root');
        $class->addPropertyAccess('Tail', 'state');
        $class->addPropertyAccess('staticHelper', 'staticOnly');
        $class->markStatic('staticHelper');
        $class->declaration()->declareMethod('uncollected', 'private', true);
        $class->addPropertyAccess('uncollected', 'uncollectedOnly');
        $class->addPropertyAccess('unknown', 'unknownOnly');
        $class->addMethodCall('entry', 'hELPER');
        $class->addMethodCall('Helper', 'TAIL');
        $class->addMethodCall('Tail', 'HELPER');
        foreach (['staticHelper', 'uncollected', 'unknown'] as $callee) {
            $class->addMethodCall('Helper', $callee);
        }

        self::assertSame(['root', 'state'], CohesionMethodReachability::properties($class, 'entry'));
    }

    #[Test]
    public function itComposesNestedTraits(): void
    {
        $inner = new LcomClassData('App', 'Inner');
        $inner->declaration()->declareMethod('touch', 'public', false);
        $inner->addMethod('touch');
        $inner->addPropertyAccess('touch', 'value');
        $outer = new LcomClassData('App', 'Outer');
        $outer->declaration()->addTraitUse(new TraitUseFacts(['App\\Inner']));
        $class = new LcomClassData('App', 'Nested');
        $class->declaration()->addTraitUse(new TraitUseFacts(['App\\Outer']));
        $class->declaration()->declareMethod('read', 'public', false);
        $class->addMethod('read');
        $class->addPropertyAccess('read', 'value');

        $metrics = self::calculate(['Nested' => [$class, new TccLccClassData('App', 'Nested')]], ['Inner' => $inner, 'Outer' => $outer]);

        self::assertSame(1, $metrics['Nested']->get('cohesion.lcom'));
        self::assertSame(2, $metrics['Nested']->get('cohesion.tcc-method-count'));
        self::assertSame(1.0, $metrics['Nested']->get('cohesion.tcc'));
        self::assertSame(0, $metrics['Nested']->get('cohesion.unread-trait-count'));
    }

    #[Test]
    public function itPreservesOwnFactsAndRetainsUnusedTraitPropertiesForTcc(): void
    {
        $trait = new LcomClassData('App', 'HasProperty');
        $trait->declaration()->incrementInstancePropertyCount();
        $own = new LcomClassData('App', 'Candidate');
        $own->declaration()->addTraitUse(new TraitUseFacts(['App\\HasProperty']));
        foreach (['one', 'two'] as $method) {
            $own->declaration()->declareMethod($method, 'public', false);
            $own->addMethod($method);
        }

        [$effective, $tcc, $unread] = CohesionTraitComposition::compose($own, new TccLccClassData('App', 'Candidate'), ['app\\hasproperty' => [$trait]]);

        self::assertSame(0, $own->declaration()->instancePropertyCount());
        self::assertSame(1, $effective->declaration()->instancePropertyCount());
        self::assertSame(1, $tcc->getPropertyCount());
        self::assertSame(['one', 'two'], $tcc->getMethods());
        self::assertSame(0.0, $tcc->calculateTcc());
        self::assertSame(0, $unread);
    }

    #[Test]
    public function itCountsAmbiguousDuplicateTraitNamesAsUnread(): void
    {
        $first = new LcomClassData('App', 'Repeated');
        $first->declaration()->declareMethod('touch', 'public', false);
        $first->addMethod('touch');
        $second = clone $first;
        $class = new LcomClassData('App', 'Ambiguous');
        $class->declaration()->addTraitUse(new TraitUseFacts(['App\\Repeated']));
        $class->declaration()->declareMethod('own', 'public', false);
        $class->addMethod('own');

        $metrics = self::calculate(['Ambiguous' => [$class, new TccLccClassData('App', 'Ambiguous')]], ['first' => $first, 'second' => $second]);

        self::assertSame(1, $metrics['Ambiguous']->get('cohesion.unread-trait-count'));
        self::assertSame(1, $metrics['Ambiguous']->get('cohesion.lcom-method-count'));
        self::assertSame(1, $metrics['Ambiguous']->get('cohesion.tcc-method-count'));
    }

    #[Test]
    #[DataProvider('lifecycleAdaptations')]
    public function itSelectsEffectiveLifecycleNamesAfterTraitComposition(string $members, int $lcomCount, int $lcomValue, int $tccCount, ?float $tccValue, ?int $pureCount): void
    {
        $metrics = self::measureCandidate($members);

        self::assertSame($lcomCount, $metrics->get('cohesion.lcom-method-count'));
        self::assertSame($lcomValue, $metrics->get('cohesion.lcom'));
        self::assertSame($tccCount, $metrics->get('cohesion.tcc-method-count'));
        self::assertSame($tccValue, $metrics->get('cohesion.tcc'));
        self::assertSame($tccValue, $metrics->get('cohesion.lcc'));
        self::assertSame($pureCount, $metrics->get('cohesion.pure-method-count'));
        self::assertSame(0, $metrics->get('cohesion.unread-trait-count'));
    }

    /** @return iterable<string, array{string, int, int, int, ?float, ?int}> */
    public static function lifecycleAdaptations(): iterable
    {
        $read = 'private int $x = 0; public function read(): int { return $this->x; }';
        yield 'ordinary method aliased to constructor' => [
            'trait Initializer { public function init(): void { $this->x = 1; } } class Candidate { use Initializer { init as __construct; } ' . $read . ' }',
            2, 1, 2, 1.0, 0,
        ];
        yield 'constructor aliased to ordinary method' => [
            'trait Initializer { public function __construct() { $this->x = 1; } } class Candidate { use Initializer { __construct as init; } private int $x = 0; private int $y = 0; public function read(): int { return $this->y; } }',
            2, 2, 2, 0.0, 0,
        ];
        yield 'ordinary method aliased to destructor without case' => [
            'trait Initializer { public function init(): void { $this->x = 1; } } class Candidate { use Initializer { INIT as __DESTRUCT; } ' . $read . ' }',
            2, 1, 2, 1.0, 0,
        ];
        yield 'destructor aliased to ordinary method without case' => [
            'trait Initializer { public function __DeStRuCt() { $this->x = 1; } } class Candidate { use Initializer { __DESTRUCT as init; } ' . $read . ' }',
            2, 1, 2, 1.0, 0,
        ];
        yield 'nested lifecycle alias reexposed as ordinary method' => [
            'trait Inner { public function init(): void { $this->x = 1; } } trait Outer { use Inner { init as __construct; } } class Candidate { use Outer { __construct as resume; } ' . $read . ' }',
            3, 1, 3, 1.0, 0,
        ];
        yield 'constructor alias retains calls through private helper' => [
            'trait Initializer { public function __construct() { $this->helper(); } private function helper(): void { $this->x = 1; } } class Candidate { use Initializer { __construct as init; } ' . $read . ' }',
            3, 1, 2, 1.0, 0,
        ];
        yield 'stateless constructor alias merges with metadata' => [
            'trait Initializer { public function __construct() {} } class Candidate { use Initializer { __construct as init; } public function label(): string { return "label"; } ' . $read . ' }',
            2, 2, 3, 0.0, 2,
        ];
        yield 'static ordinary alias remains excluded' => [
            'trait Initializer { public static function init(): int { return 1; } } class Candidate { use Initializer { init as resume; } ' . $read . ' }',
            1, 1, 1, null, null,
        ];
        yield 'abstract constructor alias remains excluded' => [
            'trait Initializer { abstract public function __construct(); } abstract class Candidate { use Initializer { __construct as init; } ' . $read . ' }',
            1, 1, 1, null, null,
        ];
        yield 'authored equivalent' => [
            'class Candidate { public function __construct() { $this->x = 1; } public function init(): void { $this->x = 1; } ' . $read . ' }',
            2, 1, 2, 1.0, 0,
        ];
    }

    /** @param list<string> $excludedMethods */
    #[Test]
    #[DataProvider('initializationHelpers')]
    public function itRemovesInitializationHelpersWithTheirLifecycleBody(string $members, int $lcomCount, ?int $lcomValue, array $excludedMethods = []): void
    {
        $metrics = self::measureCandidate($members, $excludedMethods);

        self::assertSame($lcomCount, $metrics->get('cohesion.lcom-method-count'));
        self::assertSame($lcomValue, $metrics->get('cohesion.lcom'));
    }

    /** @return iterable<string, array{0: string, 1: int, 2: ?int, 3?: list<string>}> */
    public static function initializationHelpers(): iterable
    {
        $throws = '{ if ($c === 0) { throw new \\RuntimeException(); } }';
        $readers = 'private int $p = 0; public function x(): int { return $this->p; } public function y(): int { return $this->p + 1; }';
        $handler = 'private $callback; private array $listeners = []; private int $seen = 0; private function handle(int $e = 1): void { $this->seen += $e; } public function seen(): int { return $this->seen; }';

        yield 'issue fixture: four validators called only by the constructor' => [
            'final class Candidate { private array $a; private array $b;
                public function __construct(array $cfg) { $this->v1($cfg); $this->v2($cfg); $this->v3($cfg); $this->v4($cfg); $this->a = $cfg["a"]; $this->b = $cfg["b"]; }
                private function v1(array $c): void { if (!isset($c["a"])) { throw new \\RuntimeException("a"); } }
                private function v2(array $c): void { if (!isset($c["b"])) { throw new \\RuntimeException("b"); } }
                private function v3(array $c): void { if (!isset($c["c"])) { throw new \\RuntimeException("c"); } }
                private function v4(array $c): void { if (!isset($c["d"])) { throw new \\RuntimeException("d"); } }
                public function x(): array { return $this->a; } public function y(): array { return $this->b; }
                public function z(): int { return \\count($this->a) + \\count($this->b); } }',
            3, 1,
        ];
        yield 'helper also called by an ordinary method stays' => [
            'class Candidate { public function __construct() { $this->v(1); } private function v(int $c): void ' . $throws . ' public function check(): void { $this->v($this->p); } ' . $readers . ' }',
            4, 1,
        ];
        yield 'chain from the constructor leaves entirely' => [
            'class Candidate { public function __construct() { $this->a(1); } private function a(int $c): void { $this->b($c); } private function b(int $c): void ' . $throws . ' ' . $readers . ' }',
            2, 1,
        ];
        yield 'chain tail also called by an ordinary method stays' => [
            'class Candidate { public function __construct() { $this->a(1); } private function a(int $c): void { $this->b($c); } private function b(int $c): void ' . $throws . ' public function check(): void { $this->b($this->p); } ' . $readers . ' }',
            4, 1,
        ];
        yield 'cycle reached only from the constructor leaves' => [
            'class Candidate { public function __construct() { $this->r(1); } private function r(int $c): void { if ($c > 0) { $this->s($c - 1); } } private function s(int $c): void { $this->r($c); } ' . $readers . ' }',
            2, 1,
        ];
        yield 'cycle entered by an ordinary method stays' => [
            'class Candidate { public function __construct() { $this->r(1); } private function r(int $c): void { if ($c > 0) { $this->s($c - 1); } } private function s(int $c): void { $this->r($c); } public function check(): void { $this->s($this->p); } ' . $readers . ' }',
            5, 1,
        ];
        yield 'self and static calls from the constructor' => [
            'class Candidate { public function __construct() { self::h(1); static::g(1); } private function h(int $c): void ' . $throws . ' private function g(int $c): void ' . $throws . ' ' . $readers . ' }',
            2, 1,
        ];
        yield 'static helpers were already outside the graph' => [
            'class Candidate { public function __construct() { self::h(1); } private static function h(int $c): void ' . $throws . ' ' . $readers . ' }',
            2, 1,
        ];
        yield 'destructor helper leaves' => [
            'class Candidate { public function __destruct() { $this->close(1); } private function close(int $c): void ' . $throws . ' ' . $readers . ' }',
            2, 1,
        ];
        yield 'helper shared by constructor and destructor leaves' => [
            'class Candidate { public function __construct() { $this->h(1); } public function __destruct() { $this->h(2); } private function h(int $c): void ' . $throws . ' ' . $readers . ' }',
            2, 1,
        ];
        yield 'class of initialization helpers only has no value' => [
            'class Candidate { private int $p = 0; public function __construct() { $this->h1(1); $this->h2(2); } private function h1(int $c): void { $this->p = $c; } private function h2(int $c): void ' . $throws . ' }',
            0, null,
        ];
        yield 'helper writing a property an ordinary method reads leaves' => [
            'class Candidate { private int $a = 0; private int $b = 0; public function __construct() { $this->init(); } private function init(): void { $this->a = 1; } public function x(): int { return $this->a; } public function y(): int { return $this->b; } }',
            2, 2,
        ];
        yield 'bridging helper leaves and LCOM rises' => [
            'class Candidate { private int $a = 0; private int $b = 0; public function __construct() { $this->init(); } private function init(): void { $this->a = 1; $this->b = 1; } public function x(): int { return $this->a; } public function y(): int { return $this->b; } }',
            2, 2,
        ];
        yield 'parent constructor call does not keep the helper' => [
            'class Candidate extends Base { public function __construct() { parent::__construct(); $this->v(1); } private function v(int $c): void ' . $throws . ' ' . $readers . ' }',
            2, 1,
        ];
        yield 'private trait helper called by the constructor leaves' => [
            'trait Checks { private function v(int $c): void ' . $throws . ' } class Candidate { use Checks; public function __construct() { $this->v(1); } ' . $readers . ' }',
            2, 1,
        ];
        yield 'constructor from a trait drops its helper' => [
            'trait Builds { public function __construct() { $this->v(1); } } class Candidate { use Builds; private function v(int $c): void ' . $throws . ' ' . $readers . ' }',
            2, 1,
        ];
        yield 'constructor aliased to an ordinary method keeps its helper' => [
            'trait Builds { public function __construct() { $this->v(1); } } class Candidate { use Builds { __construct as init; } private function v(int $c): void ' . $throws . ' ' . $readers . ' }',
            4, 2,
        ];
        yield 'lifecycle and helper names fold case' => [
            'class Candidate { public function __CONSTRUCT() { $this->HELPER(1); } private function helper(int $c): void ' . $throws . ' ' . $readers . ' }',
            2, 1,
        ];
        yield 'static caller keeps the helper' => [
            'class Candidate { public function __construct() { $this->v(1); } public static function make(): void { self::v(1); } private function v(int $c): void ' . $throws . ' ' . $readers . ' }',
            3, 2,
        ];
        yield 'ordinary self caller keeps the helper' => [
            'class Candidate { public function __construct() { $this->v(1); } public function check(): void { self::v($this->p); } private function v(int $c): void ' . $throws . ' ' . $readers . ' }',
            4, 1,
        ];
        yield 'call on another instance is not recorded' => [
            'class Candidate { public function __construct() { $this->v(1); } public static function make(): self { $o = new self(); $o->v(1); return $o; } private function v(int $c): void ' . $throws . ' ' . $readers . ' }',
            2, 1,
        ];
        yield 'excluded caller keeps the helper' => [
            'class Candidate { public function __construct() { $this->v(1); } public function skipMe(): void { $this->v(2); } private function v(int $c): void ' . $throws . ' ' . $readers . ' }',
            3, 2, ['skipMe'],
        ];
        yield 'constructor reaching state through a public reset keeps both' => [
            'class Candidate { private array $items = []; public function __construct() { $this->reset(); } public function reset(): void { $this->clear(); } private function clear(): void { $this->items = []; } public function add(int $i): void { $this->items[] = $i; } public function size(): int { return \\count($this->items); } }',
            4, 1,
        ];
        yield 'public method called only by the constructor stays' => [
            'class Candidate { private int $q = 0; public function __construct() { $this->setup(); } public function setup(): void { $this->q = 1; } ' . $readers . ' }',
            3, 2,
        ];
        yield 'arrow function registered by the constructor keeps its handler' => [
            'class Candidate { ' . $handler . ' public function __construct() { $this->listeners[] = fn(int $e) => $this->handle($e); } public function dispatch(int $e): void { foreach ($this->listeners as $l) { $l($e); } } }',
            3, 2,
        ];
        yield 'closure registered by a private helper keeps the handler, not the helper' => [
            'class Candidate { ' . $handler . ' public function __construct() { $this->registerListeners(); } private function registerListeners(): void { $this->listeners[] = function (int $e): void { $this->handle($e); }; } public function dispatch(int $e): void { foreach ($this->listeners as $l) { $l($e); } } }',
            3, 2,
        ];
        yield 'first-class callable passed by the constructor keeps its handler' => [
            'class Candidate { ' . $handler . ' public function __construct() { $this->callback = $this->handle(...); } }',
            2, 1,
        ];
        yield 'handler called directly and from a closure keeps its deferred reach' => [
            'class Candidate { ' . $handler . ' public function __construct() { $this->handle(0); $this->callback = fn() => $this->handle(); } }',
            2, 1,
        ];
        yield 'ordinary method aliased to the constructor defers its closure calls' => [
            'trait Boot { public function boot(): void { $this->prepare(1); $this->callback = fn() => $this->handle(); } }
             class Candidate { use Boot { boot as __construct; } ' . $handler . ' private bool $ready = false;
                public function boot(): void { $this->ready = true; } public function isReady(): bool { return $this->ready; }
                private function prepare(int $c): void ' . $throws . ' }',
            4, 2,
        ];
        yield 'stateless constant helper leaves before the virtual node forms' => [
            'class Candidate { private array $d = []; public function __construct() { $this->d = $this->defaults(); } private function defaults(): array { return []; } ' . $readers . ' }',
            2, 1,
        ];
        yield 'trait method adapted to private leaves' => [
            'trait Checks { public function v(int $c): void ' . $throws . ' } class Candidate { use Checks { v as private; } public function __construct() { $this->v(1); } ' . $readers . ' }',
            2, 1,
        ];
        yield 'trait method kept public stays' => [
            'trait Checks { public function v(int $c): void ' . $throws . ' } class Candidate { use Checks; public function __construct() { $this->v(1); } ' . $readers . ' }',
            3, 2,
        ];
        yield 'helper whose closure touches state registers behaviour and stays' => [
            'class Candidate { private array $listeners = []; private int $count = 0; private array $log = [];
                public function __construct() { $this->register(); }
                private function register(): void { $this->listeners[] = function (): void { $this->count++; $this->log[] = "x"; }; }
                public function total(): int { return $this->count; } public function entries(): array { return $this->log; } }',
            3, 1,
        ];
        yield 'helper binding properties by reference stays' => [
            'class Candidate { private $callback; private int $seen = 0; private array $stats = [];
                public function __construct() { $this->wire(); }
                private function wire(): void { $seen = &$this->seen; $hits = &$this->stats["hits"]; $this->callback = static function () use (&$seen, &$hits): void { $seen++; $hits++; }; }
                public function seen(): int { return $this->seen; } public function hits(): int { return $this->stats["hits"] ?? 0; } }',
            3, 1,
        ];
        yield 'helper binding a property element by reference stays' => [
            'class Candidate { private $callback; private array $stats = [];
                public function __construct() { $this->wire(); }
                private function wire(): void { $hits = &$this->stats["hits"]; $this->callback = static function () use (&$hits): void { $hits++; }; }
                public function hits(): int { return $this->stats["hits"] ?? 0; } }',
            2, 1,
        ];
        yield 'unread trait keeps every helper' => [
            'class Candidate { use \\Vendor\\Unread; private int $a = 0; private int $b = 0;
                public function __construct() { $this->reset(); } private function reset(): void { $this->a = 0; $this->b = 0; }
                public function x(): int { return $this->a; } public function y(): int { return $this->b; } }',
            3, 1,
        ];
        foreach ([
            'dynamic method name' => '$this->$name()',
            'dynamic method expression' => '$this->{"lo" . "ad"}()',
            'dynamic self call' => 'self::$name()',
            'dynamic static call' => 'static::{$name}()',
            'callable array on this' => 'array_map([$this, $name], [])',
            'callable array on self class' => 'array_map([self::class, $name], [])',
            'callable array on static class' => 'array_map([static::class, $name], [])',
        ] as $form => $call) {
            yield 'unresolvable own call keeps every helper: ' . $form => [
                'class Candidate { private int $a = 0; private int $b = 0;
                    public function __construct() { $this->load(); } private function load(): void { $this->a = 1; $this->b = 1; }
                    public function dispatch(string $name): void { ' . $call . '; }
                    public function x(): int { return $this->a; } public function y(): int { return $this->b; } }',
                4, 2,
            ];
        }
        foreach ([
            'own short class name' => 'Candidate::load()',
            'own qualified class name' => '\\App\\Candidate::load()',
            'this used as a class' => '$this::load()',
            'literal dynamic method name' => '$this->{"load"}()',
            'literal dynamic self call' => 'self::{"load"}()',
            'own class first-class callable' => '$f = Candidate::load(...)',
            'static first-class callable' => '$f = static::load(...)',
            'callable array on __CLASS__' => '$f = [__CLASS__, "load"]',
            'callable array on this class constant' => '$f = [$this::class, "load"]',
            'callable array on own class constant' => '$f = [Candidate::class, "load"]',
            'callable array on get_class' => '$f = [get_class($this), "load"]',
            'callable array on get_called_class' => '$f = [get_called_class(), "load"]',
            'closure from callable array' => '$f = \\Closure::fromCallable([$this, "load"])',
            'self callable string' => '$f = "self::load"',
            'static callable string' => '$f = "static::load"',
            'own class callable string' => '$f = "App\\\\Candidate::load"',
            'reflection method' => '$f = new \\ReflectionMethod($this, "load")',
        ] as $form => $call) {
            yield 'own call through ' . $form . ' keeps the helper' => [
                'class Candidate { private int $a = 0; private int $b = 0;
                    public function __construct() { $this->load(); } private function load(): void { $this->a = 1; $this->b = 1; }
                    public function again(): void { ' . $call . '; }
                    public function x(): int { return $this->a; } public function y(): int { return $this->b; } }',
                4, 1,
            ];
        }
        foreach ([
            'dynamic call on this as a class' => '$this::$name()',
            'callable array on __CLASS__ with a dynamic name' => '$f = [__CLASS__, $name]',
            'reflection method with a dynamic name' => '$f = new \\ReflectionMethod($this, $name)',
        ] as $form => $call) {
            yield 'unresolvable own call keeps every helper: ' . $form => [
                'class Candidate { private int $a = 0; private int $b = 0;
                    public function __construct() { $this->load(); } private function load(): void { $this->a = 1; $this->b = 1; }
                    public function dispatch(string $name): void { ' . $call . '; }
                    public function x(): int { return $this->a; } public function y(): int { return $this->b; } }',
                4, 2,
            ];
        }
        foreach ([
            'property bound to a reference' => '$count = 0; $this->count = &$count; $this->callback = static function () use (&$count): void { $count++; };',
            'property as a by-reference array item' => '$refs = ["count" => &$this->count]; $this->callback = static function () use ($refs): void { $refs["count"]++; };',
            'by-reference foreach over a property' => 'foreach ($this->slots as &$slot) { $this->callback = static function () use (&$slot): void { $slot++; }; }',
            'nested member bound by reference' => '$count = &$this->holder->count; $this->callback = static function () use (&$count): void { $count++; };',
            'dynamic property in a closure' => '$field = "count"; $this->callback = function () use ($field): void { $this->{$field}++; };',
        ] as $form => $body) {
            // A dynamic name hands off state without naming it, so it adds no shared-property edge.
            $lcom = str_contains($form, 'dynamic') ? 2 : 1;
            yield 'helper handing off state through ' . $form . ' stays' => [
                'class Candidate { private $callback; private int $count = 0; private array $slots = []; private ?object $holder = null;
                    public function __construct() { $this->wire(); }
                    private function wire(): void { ' . $body . ' }
                    public function run(): void { ($this->callback)(); } public function total(): int { return $this->count + \\count($this->slots) + (int) $this->holder?->count; } }',
                3, $lcom,
            ];
        }
        yield 'generator helper stays' => [
            'class Candidate { private iterable $rows; private array $source = []; private int $read = 0;
                public function __construct() { $this->rows = $this->generate(); }
                private function generate(): iterable { foreach ($this->source as $row) { $this->read++; yield $row; } }
                public function next(): mixed { return $this->rows; } public function consumed(): int { return $this->read; } public function replace(array $s): void { $this->source = $s; } }',
            4, 2,
        ];
        yield 'trait helper whose closure touches host state stays' => [
            'trait Registers { private function register(): void { $this->listeners[] = function (): void { $this->count++; }; } }
             class Candidate { use Registers; private array $listeners = []; private int $count = 0;
                public function __construct() { $this->register(); }
                public function total(): int { return $this->count; } public function dispatch(): int { return \\count($this->listeners); } }',
            3, 1,
        ];
        yield 'invoking the object reaches only its public __invoke' => [
            'class Candidate { private int $a = 0; private int $b = 0;
                public function __construct() { $this->load(); } private function load(): void { $this->a = 1; $this->b = 1; }
                public function __invoke(): void { $this->load(); } public function dispatch(): void { \\call_user_func($this); }
                public function x(): int { return $this->a; } public function y(): int { return $this->b; } }',
            5, 2,
        ];
        yield 'invoking an object without __invoke keeps no helper' => [
            'class Candidate { private int $a = 0; private int $b = 0;
                public function __construct() { $this->load(); } private function load(): void { $this->a = 1; $this->b = 1; }
                public function dispatch(): void { \\call_user_func_array($this, []); }
                public function x(): int { return $this->a; } public function y(): int { return $this->b; } }',
            3, 3,
        ];
        yield 'static first-class callable passed by the constructor keeps its handler' => [
            'class Candidate { ' . $handler . ' public function __construct() { $this->callback = self::handle(...); } }',
            2, 1,
        ];
        yield 'closure of a removed helper keeps a helper the constructor also calls' => [
            'class Candidate { ' . $handler . ' public function __construct() { $this->handle(1); $this->registerListeners(); }
                private function registerListeners(): void { $this->listeners[] = fn() => $this->handle(2); }
                public function dispatch(): int { return \\count($this->listeners); } }',
            3, 2,
        ];
        yield 'public method re-entering the constructor does not keep its helpers' => [
            'class Candidate { private int $a = 0; public function __construct() { $this->init(); } private function init(): void { $this->a = 1; }
                public function reset(): void { $this->__construct(); } public function x(): int { return $this->a; } }',
            2, 2,
        ];
        yield 'protected method called only by the constructor stays' => [
            'class Candidate { private int $q = 0; public function __construct() { $this->setup(); } protected function setup(): void { $this->q = 1; } ' . $readers . ' }',
            3, 2,
        ];
        yield 'closure depth resumes after a method nested in a closure' => [
            'class Candidate { ' . $handler . ' public function __construct() { $this->callback = function (): void { $o = new class { public function m(): void {} }; $this->handle(); }; } }',
            2, 1,
        ];
    }

    #[Test]
    public function itKeepsClosureEdgesOfAConstructorAliasedToAnOrdinaryMethod(): void
    {
        $metrics = self::measureCandidate('trait Builds { public function __construct() { $this->callback = fn() => $this->handle(); } }
            class Candidate { use Builds { __construct as init; } private $callback; private int $seen = 0;
                private function handle(): void { $this->seen++; } public function seen(): int { return $this->seen; } }');

        self::assertSame(3, $metrics->get('cohesion.lcom-method-count'));
        self::assertSame(1, $metrics->get('cohesion.lcom'));
        self::assertSame(2, $metrics->get('cohesion.tcc-method-count'));
        self::assertSame(1.0, $metrics->get('cohesion.tcc'));
        self::assertSame(1.0, $metrics->get('cohesion.lcc'));
    }

    /** @param list<string> $excludedMethods */
    private static function measureCandidate(string $members, array $excludedMethods = []): MetricBag
    {
        $ast = (new ParserFactory())->createForHostVersion()->parse('<?php namespace App; ' . $members) ?? [];
        NameResolution::resolve($ast);
        $lcomVisitor = new LcomVisitor();
        $tccVisitor = new TccLccVisitor();
        $traverser = new NodeTraverser($lcomVisitor, $tccVisitor);
        $traverser->traverse($ast);
        $lcom = $lcomVisitor->getClassData();
        $tcc = $tccVisitor->getClassData();
        $classes = $traits = [];
        foreach ($lcom as $position => $data) {
            if ($data->className === 'Candidate') {
                $classes['Candidate'] = [$data, $tcc[$position]];
            } else {
                $traits[$data->className] = $data;
            }
        }

        return self::calculate($classes, $traits, new LcomCollectionConfiguration($excludedMethods))['Candidate'];
    }

    /** @param array<string, array{LcomClassData, TccLccClassData}> $classes
     * @param array<string, LcomClassData> $traits
     *
     * @return array<string, MetricBag>
     */
    private static function calculate(array $classes, array $traits, ?LcomCollectionConfiguration $configuration = null): array
    {
        $collector = new CohesionCompositionCollector();
        if ($configuration !== null) {
            $collector->applyLcomCollectionConfiguration($configuration);
        }
        $repository = new InMemoryMetricRepository($collector->getMetricDefinitions());
        $declarations = [];
        foreach ($traits as $name => $data) {
            $declaration = self::declaration($data->className, 'src/' . $name . '.php');
            $repository->addSubject(MetricSubject::declaration($declaration), self::wire($data, null), $declaration->file, 1);
            $declarations[] = ClassLikeDeclaration::of($declaration, ClassType::Trait_, false, false);
        }
        foreach ($classes as $name => [$lcom, $tcc]) {
            $declaration = self::declaration($name, 'src/' . $name . '.php');
            $repository->addSubject(MetricSubject::declaration($declaration), self::wire($lcom, $tcc), $declaration->file, 1);
            $declarations[] = ClassLikeDeclaration::of($declaration, ClassType::Class_, false, false);
        }
        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getClassLikeDeclarations')->willReturn($declarations);
        $collector->calculate($graph, $repository);
        $metrics = [];
        foreach (array_keys($classes) as $name) {
            $metrics[$name] = $repository->getSubject(MetricSubject::declaration(self::declaration($name, 'src/' . $name . '.php')));
        }

        return $metrics;
    }

    private static function declaration(string $name, string $file): DeclarationPath
    {
        return DeclarationPath::of(SymbolPath::forClass('App', $name), RelativePath::fromString($file), DeclarationOrdinal::fromRank(0));
    }

    private static function wire(LcomClassData $lcom, ?TccLccClassData $tcc): MetricBag
    {
        $bag = new MetricBag();
        foreach (CohesionFactWire::lcomRows($lcom) as $row) {
            $bag = $bag->withEntry(LcomCollector::FACTS, $row);
        }
        foreach (($tcc === null ? [] : CohesionFactWire::tccRows($tcc)) as $row) {
            $bag = $bag->withEntry(TccLccCollector::FACTS, $row);
        }
        $wire = unserialize(serialize($bag));
        self::assertInstanceOf(MetricBag::class, $wire);

        return $wire;
    }
}
