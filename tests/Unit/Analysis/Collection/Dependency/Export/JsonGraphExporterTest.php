<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Collection\Dependency\Export;

use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraph;
use Qualimetrix\Analysis\Collection\Dependency\Export\JsonGraphExporter;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;

final class JsonGraphExporterTest extends TestCase
{
    public function testExportsValidJson(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\ServiceA'),
                SymbolPath::fromClassFqn('App\\ServiceB'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new JsonGraphExporter();
        $json = $exporter->export($graph);

        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($data);
        self::assertArrayHasKey('meta', $data);
        self::assertArrayHasKey('statistics', $data);
        self::assertArrayHasKey('nodes', $data);
        self::assertArrayHasKey('edges', $data);
    }

    public function testMetaSection(): void
    {
        $graph = $this->createGraph([]);
        $exporter = new JsonGraphExporter();
        $data = $this->decode($exporter->export($graph));

        self::assertSame('1.0.0', $data['meta']['version']);
        self::assertSame('qmx', $data['meta']['package']);
        self::assertArrayHasKey('timestamp', $data['meta']);
    }

    public function testStatisticsSection(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\ServiceA'),
                SymbolPath::fromClassFqn('App\\ServiceB'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 10),
            ),
            new Dependency(
                SymbolPath::fromClassFqn('App\\ServiceB'),
                SymbolPath::fromClassFqn('App\\ServiceC'),
                DependencyType::New_,
                new Location('/test/file.php', 20),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new JsonGraphExporter();
        $data = $this->decode($exporter->export($graph));

        self::assertSame(3, $data['statistics']['nodeCount']);
        self::assertSame(2, $data['statistics']['edgeCount']);
    }

    public function testNodesContainFqnAndNamespace(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Service\\UserService'),
                SymbolPath::fromClassFqn('App\\Repository\\UserRepository'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new JsonGraphExporter();
        $data = $this->decode($exporter->export($graph));

        self::assertCount(2, $data['nodes']);

        // Nodes are sorted by FQN
        self::assertSame('App\\Repository\\UserRepository', $data['nodes'][0]['fqn']);
        self::assertSame('App\\Repository', $data['nodes'][0]['namespace']);
        self::assertSame('App\\Service\\UserService', $data['nodes'][1]['fqn']);
        self::assertSame('App\\Service', $data['nodes'][1]['namespace']);
    }

    public function testEdgesAreAggregated(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\ServiceA'),
                SymbolPath::fromClassFqn('App\\ServiceB'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 10),
            ),
            new Dependency(
                SymbolPath::fromClassFqn('App\\ServiceA'),
                SymbolPath::fromClassFqn('App\\ServiceB'),
                DependencyType::New_,
                new Location('/test/file.php', 20),
            ),
            new Dependency(
                SymbolPath::fromClassFqn('App\\ServiceA'),
                SymbolPath::fromClassFqn('App\\ServiceB'),
                DependencyType::TypeHint,
                new Location('/test/file2.php', 5),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new JsonGraphExporter();
        $data = $this->decode($exporter->export($graph));

        // Should be aggregated into one edge
        self::assertSame(1, $data['statistics']['edgeCount']);
        self::assertCount(1, $data['edges']);

        $edge = $data['edges'][0];
        self::assertSame('App\\ServiceA', $edge['from']);
        self::assertSame('App\\ServiceB', $edge['to']);
        self::assertSame(['new', 'type_hint'], $edge['types']); // sorted alphabetically
        self::assertSame(3, $edge['count']);
    }

    public function testEdgesSortedByFromThenTo(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Z'),
                SymbolPath::fromClassFqn('App\\A'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 10),
            ),
            new Dependency(
                SymbolPath::fromClassFqn('App\\A'),
                SymbolPath::fromClassFqn('App\\Z'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 20),
            ),
            new Dependency(
                SymbolPath::fromClassFqn('App\\A'),
                SymbolPath::fromClassFqn('App\\B'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 30),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new JsonGraphExporter();
        $data = $this->decode($exporter->export($graph));

        self::assertSame('App\\A', $data['edges'][0]['from']);
        self::assertSame('App\\B', $data['edges'][0]['to']);
        self::assertSame('App\\A', $data['edges'][1]['from']);
        self::assertSame('App\\Z', $data['edges'][1]['to']);
        self::assertSame('App\\Z', $data['edges'][2]['from']);
        self::assertSame('App\\A', $data['edges'][2]['to']);
    }

    public function testEmptyGraph(): void
    {
        $graph = $this->createGraph([]);
        $exporter = new JsonGraphExporter();
        $data = $this->decode($exporter->export($graph));

        self::assertSame(0, $data['statistics']['nodeCount']);
        self::assertSame(0, $data['statistics']['edgeCount']);
        self::assertSame([], $data['nodes']);
        self::assertSame([], $data['edges']);
    }

    public function testFiltersIncludeNamespaces(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Service\\Foo'),
                SymbolPath::fromClassFqn('App\\Service\\Bar'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 10),
            ),
            new Dependency(
                SymbolPath::fromClassFqn('App\\Tests\\FooTest'),
                SymbolPath::fromClassFqn('App\\Service\\Foo'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 20),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new JsonGraphExporter(includeNamespaces: ['App\\Service']);
        $data = $this->decode($exporter->export($graph));

        self::assertSame(2, $data['statistics']['nodeCount']);
        $fqns = array_column($data['nodes'], 'fqn');
        self::assertContains('App\\Service\\Foo', $fqns);
        self::assertContains('App\\Service\\Bar', $fqns);
        self::assertNotContains('App\\Tests\\FooTest', $fqns);

        // Only edge within included namespace should be present
        self::assertSame(1, $data['statistics']['edgeCount']);
    }

    public function testFiltersExcludeNamespaces(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Service\\Foo'),
                SymbolPath::fromClassFqn('App\\Service\\Bar'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 10),
            ),
            new Dependency(
                SymbolPath::fromClassFqn('App\\Tests\\FooTest'),
                SymbolPath::fromClassFqn('App\\Service\\Foo'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 20),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new JsonGraphExporter(excludeNamespaces: ['App\\Tests']);
        $data = $this->decode($exporter->export($graph));

        $fqns = array_column($data['nodes'], 'fqn');
        self::assertNotContains('App\\Tests\\FooTest', $fqns);
        self::assertSame(1, $data['statistics']['edgeCount']);
    }

    public function testFiltersEdgesWhenNodesAreFiltered(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Service\\Foo'),
                SymbolPath::fromClassFqn('App\\Tests\\FooTest'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new JsonGraphExporter(excludeNamespaces: ['App\\Tests']);
        $data = $this->decode($exporter->export($graph));

        // Edge should not appear because target node is filtered out
        self::assertSame(0, $data['statistics']['edgeCount']);
        self::assertSame([], $data['edges']);
    }

    public function testGetFormat(): void
    {
        $exporter = new JsonGraphExporter();
        self::assertSame('json', $exporter->getFormat());
    }

    public function testGetFileExtension(): void
    {
        $exporter = new JsonGraphExporter();
        self::assertSame('json', $exporter->getFileExtension());
    }

    public function testAllDependencyTypesPreserved(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Foo'),
                SymbolPath::fromClassFqn('App\\Bar'),
                DependencyType::Extends,
                new Location('/test/file.php', 1),
            ),
            new Dependency(
                SymbolPath::fromClassFqn('App\\Foo'),
                SymbolPath::fromClassFqn('App\\Bar'),
                DependencyType::Implements,
                new Location('/test/file.php', 2),
            ),
            new Dependency(
                SymbolPath::fromClassFqn('App\\Foo'),
                SymbolPath::fromClassFqn('App\\Bar'),
                DependencyType::StaticCall,
                new Location('/test/file.php', 3),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new JsonGraphExporter();
        $data = $this->decode($exporter->export($graph));

        $edge = $data['edges'][0];
        self::assertSame(['extends', 'implements', 'static_call'], $edge['types']);
        self::assertSame(3, $edge['count']);
    }

    public function testOutputIsPrettyPrintedJson(): void
    {
        $graph = $this->createGraph([]);
        $exporter = new JsonGraphExporter();
        $json = $exporter->export($graph);

        // Pretty-printed JSON contains newlines and indentation
        self::assertStringContainsString("\n", $json);
        self::assertStringContainsString('    ', $json);
        // Ends with newline
        self::assertStringEndsWith("\n", $json);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<Dependency> $dependencies
     */
    private function createGraph(array $dependencies): DependencyGraph
    {
        $bySource = [];
        $byTarget = [];
        /** @var array<string, SymbolPath> $classMap */
        $classMap = [];
        /** @var array<string, SymbolPath> $namespaceMap */
        $namespaceMap = [];

        foreach ($dependencies as $dep) {
            $sourceKey = $dep->source->toCanonical();
            $targetKey = $dep->target->toCanonical();

            if (!isset($bySource[$sourceKey])) {
                $bySource[$sourceKey] = [];
            }
            $bySource[$sourceKey][] = $dep;

            if (!isset($byTarget[$targetKey])) {
                $byTarget[$targetKey] = [];
            }
            $byTarget[$targetKey][] = $dep;

            $classMap[$sourceKey] = $dep->source;
            $classMap[$targetKey] = $dep->target;

            $sourceNs = $dep->source->namespace;
            $targetNs = $dep->target->namespace;

            if ($sourceNs !== null) {
                $nsPath = SymbolPath::forNamespace($sourceNs);
                $namespaceMap[$nsPath->toCanonical()] = $nsPath;
            }
            if ($targetNs !== null) {
                $nsPath = SymbolPath::forNamespace($targetNs);
                $namespaceMap[$nsPath->toCanonical()] = $nsPath;
            }
        }

        return new DependencyGraph(
            $dependencies,
            $bySource,
            $byTarget,
            array_values($classMap),
            array_values($namespaceMap),
            [],
            [],
            [],
            [],
        );
    }
}
