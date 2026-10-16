<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Complexity\Integration;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Analysis\Evidence\Complexity\CyclomaticComplexityVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\AggregationHelper;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MetricAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

final class WmcIntegrationTest extends TestCase
{
    #[Test]
    public function itPublishesTheSameMethodSumAsWmc(): void
    {
        $repository = $this->measure([
            'src/TestClass.php' => <<<'SOURCE'
<?php
namespace App;
class TestClass {
    public function first($x) { if ($x) {} }
    public function second($x) { if ($x) {} else if (!$x) {} }
}
SOURCE,
        ]);

        $metrics = $repository->getSubject($this->classSubject('App', 'TestClass', 'src/TestClass.php'));
        self::assertSame(5, $metrics->get('complexity.wmc'));
        self::assertSame($metrics->get('complexity.ccn.sum'), $metrics->get('complexity.wmc'));
    }

    #[Test]
    public function itPublishesZeroForANamedClassWithoutMethods(): void
    {
        $repository = $this->measure([
            'src/EmptyClass.php' => '<?php namespace App; class EmptyClass {}',
        ]);

        self::assertSame(0, $repository->getSubject($this->classSubject('App', 'EmptyClass', 'src/EmptyClass.php'))->get('complexity.wmc'));
        self::assertCount(1, iterator_to_array($repository->allClassDeclarations(), false));
    }

    #[Test]
    public function itKeepsEachClassSumInItsOwnDeclaration(): void
    {
        $repository = $this->measure([
            'src/Class1.php' => '<?php namespace App; class Class1 { public function first() {} }',
            'src/Class2.php' => '<?php namespace App; class Class2 { public function first($x) { if ($x) {} } public function second() {} }',
        ]);

        $first = $repository->getSubject($this->classSubject('App', 'Class1', 'src/Class1.php'));
        $second = $repository->getSubject($this->classSubject('App', 'Class2', 'src/Class2.php'));
        self::assertSame(1, $first->get('complexity.wmc'));
        self::assertSame(3, $second->get('complexity.wmc'));
        self::assertSame($first->get('complexity.ccn.sum'), $first->get('complexity.wmc'));
        self::assertSame($second->get('complexity.ccn.sum'), $second->get('complexity.wmc'));
    }

    #[Test]
    public function itCarriesAnonymousClassContextFromSourceIntoCallableRecords(): void
    {
        $repository = $this->measure([
            'src/Outer.php' => <<<'SOURCE'
<?php
namespace App;
class Outer {
    public function run() {}
    public function make() {
        return new class {
            public function hidden() {}
        };
    }
}
SOURCE,
        ]);
        $named = [];
        $anonymous = [];
        foreach ($repository->allCallables() as $info) {
            if ($info->anonymousClassContext) {
                $anonymous[] = $info;
            } else {
                $named[] = $info;
            }
        }

        self::assertCount(2, $named);
        self::assertCount(1, $anonymous);
        self::assertNull($anonymous[0]->classAggregationOwner);
        self::assertSame(2, $repository->getSubject($this->classSubject('App', 'Outer', 'src/Outer.php'))->get('complexity.wmc'));
    }

    /** @param array<string, string> $sources */
    private function measure(array $sources): InMemoryMetricRepository
    {
        $collector = new CyclomaticComplexityCollector();
        $definitions = [
            ...AggregationHelper::collectDefinitions([$collector]),
            new MetricDefinition(MetricName::SIZE_SYMBOL_METHOD_COUNT, SymbolLevel::Class_),
        ];
        $repository = new InMemoryMetricRepository($definitions);
        $parser = (new ParserFactory())->createForHostVersion();
        foreach ($sources as $file => $code) {
            $ast = $parser->parse($code) ?? [];
            $registrar = (new DeclarationRegistrarFactory())->createForFile();
            $visitor = $collector->getVisitor();
            self::assertInstanceOf(CyclomaticComplexityVisitor::class, $visitor);
            $visitor->reset();
            $collector->useDeclarationIndex($registrar->index());
            $visitor->useDeclarationIndex($registrar->index());
            $traverser = new NodeTraverser();
            $traverser->addVisitor($registrar);
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            $path = RelativePath::fromString($file);
            $collector->collect(new SplFileInfo($file), $ast);
            foreach ($collector->getClassesWithMetrics($path) as $class) {
                $repository->addSubject(MetricSubject::declaration($class->declarationPath), $class->metrics, $path, $class->line);
            }
            foreach ($collector->getCallablesWithMetrics($path) as $callable) {
                $repository->addCallable($callable);
            }
        }
        (new MetricAggregator($definitions, self::createStub(ProfilerInterface::class)))->aggregate($repository);

        return $repository;
    }

    private function classSubject(string $namespace, string $name, string $file): MetricSubject
    {
        return MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forClass($namespace, $name),
            RelativePath::fromString($file),
            DeclarationOrdinal::fromRank(0),
        ));
    }
}
