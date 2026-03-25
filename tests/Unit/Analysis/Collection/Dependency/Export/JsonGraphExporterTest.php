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

        $this->assertIsArray($data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('statistics', $data);
        $this->assertArrayHasKey('nodes', $data);
        $this->assertArrayHasKey('edges', $data);
    }

    public function testMetaSection(): void
    {
        $graph = $this->createGraph([]);
        $exporter = new JsonGraphExporter();
        $data = $this->decode($exporter->export($graph));

        $this->assertSame('1.0.0', $data['meta']['version']);
        $this->assertSame('qmx', $data['meta']['package']);
        $this->assertArrayHasKey('timestamp', $data['meta']);
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

        $this->assertSame(3, $data['statistics']['nodeCount']);
        $this->assertSame(2, $data['statistics']['edgeCount']);
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

        $this->assertCount(2, $data['nodes']);

        // Nodes are sorted by FQN
        $this->assertSame('App\\Repository\\UserRepository', $data['nodes'][0]['fqn']);
        $this->assertSame('App\\Repository', $data['nodes'][0]['namespace']);
        $this->assertSame('App\\Service\\UserService', $data['nodes'][1]['fqn']);
        $this->assertSame('App\\Service', $data['nodes'][1]['namespace']);
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
        $this->assertSame(1, $data['statistics']['edgeCount']);
        $this->assertCount(1, $data['edges']);

        $edge = $data['edges'][0];
        $this->assertSame('App\\ServiceA', $edge['from']);
        $this->assertSame('App\\ServiceB', $edge['to']);
        $this->assertSame(['new', 'type_hint'], $edge['types']); // sorted alphabetically
        $this->assertSame(3, $edge['count']);
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

        $this->assertSame('App\\A', $data['edges'][0]['from']);
        $this->assertSame('App\\B', $data['edges'][0]['to']);
        $this->assertSame('App\\A', $data['edges'][1]['from']);
        $this->assertSame('App\\Z', $data['edges'][1]['to']);
        $this->assertSame('App\\Z', $data['edges'][2]['from']);
        $this->assertSame('App\\A', $data['edges'][2]['to']);
    }

    public function testEmptyGraph(): void
    {
        $graph = $this->createGraph([]);
        $exporter = new JsonGraphExporter();
        $data = $this->decode($exporter->export($graph));

        $this->assertSame(0, $data['statistics']['nodeCount']);
        $this->assertSame(0, $data['statistics']['edgeCount']);
        $this->assertSame([], $data['nodes']);
        $this->assertSame([], $data['edges']);
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

        $this->assertSame(2, $data['statistics']['nodeCount']);
        $fqns = array_column($data['nodes'], 'fqn');
        $this->assertContains('App\\Service\\Foo', $fqns);
        $this->assertContains('App\\Service\\Bar', $fqns);
        $this->assertNotContains('App\\Tests\\FooTest', $fqns);

        // Only edge within included namespace should be present
        $this->assertSame(1, $data['statistics']['edgeCount']);
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
        $this->assertNotContains('App\\Tests\\FooTest', $fqns);
        $this->assertSame(1, $data['statistics']['edgeCount']);
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
        $this->assertSame(0, $data['statistics']['edgeCount']);
        $this->assertSame([], $data['edges']);
    }

    public function testGetFormat(): void
    {
        $exporter = new JsonGraphExporter();
        $this->assertSame('json', $exporter->getFormat());
    }

    public function testGetFileExtension(): void
    {
        $exporter = new JsonGraphExporter();
        $this->assertSame('json', $exporter->getFileExtension());
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
        $this->assertSame(['extends', 'implements', 'static_call'], $edge['types']);
        $this->assertSame(3, $edge['count']);
    }

    public function testOutputIsPrettyPrintedJson(): void
    {
        $graph = $this->createGraph([]);
        $exporter = new JsonGraphExporter();
        $json = $exporter->export($graph);

        // Pretty-printed JSON contains newlines and indentation
        $this->assertStringContainsString("\n", $json);
        $this->assertStringContainsString('    ', $json);
        // Ends with newline
        $this->assertStringEndsWith("\n", $json);
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
