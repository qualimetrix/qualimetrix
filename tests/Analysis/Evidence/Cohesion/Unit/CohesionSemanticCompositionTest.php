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
        $metrics = self::calculate($classes, $traits)['Candidate'];

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

    /** @param array<string, array{LcomClassData, TccLccClassData}> $classes
     * @param array<string, LcomClassData> $traits
     *
     * @return array<string, MetricBag>
     */
    private static function calculate(array $classes, array $traits): array
    {
        $collector = new CohesionCompositionCollector();
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
