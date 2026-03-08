<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Collection\Dependency\Export;

use AiMessDetector\Analysis\Collection\Dependency\DependencyGraph;
use AiMessDetector\Analysis\Collection\Dependency\Export\DotExporter;
use AiMessDetector\Analysis\Collection\Dependency\Export\DotExporterOptions;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use PHPUnit\Framework\TestCase;

final class DotExporterTest extends TestCase
{
    public function testExportsValidDot(): void
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
                DependencyType::TypeHint,
                new Location('/test/file.php', 20),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter();
        $dot = $exporter->export($graph);

        $this->assertStringContainsString('digraph Dependencies', $dot);
        $this->assertStringContainsString('"App\\\\ServiceA" -> "App\\\\ServiceB"', $dot);
        $this->assertStringContainsString('"App\\\\ServiceB" -> "App\\\\ServiceC"', $dot);
    }

    public function testGroupsByNamespace(): void
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
        $exporter = new DotExporter(new DotExporterOptions(groupByNamespace: true));
        $dot = $exporter->export($graph);

        $this->assertStringContainsString('subgraph cluster_', $dot);
        $this->assertStringContainsString('label="App\\\\Service"', $dot);
        $this->assertStringContainsString('label="App\\\\Repository"', $dot);
    }

    public function testUsesShortLabels(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Very\\Long\\Namespace\\UserService'),
                SymbolPath::fromClassFqn('App\\Very\\Long\\Namespace\\UserRepository'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(shortLabels: true));
        $dot = $exporter->export($graph);

        $this->assertStringContainsString('label="UserService"', $dot);
        $this->assertStringContainsString('label="UserRepository"', $dot);
    }

    public function testUsesFullLabelsWhenDisabled(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\UserService'),
                SymbolPath::fromClassFqn('App\\UserRepository'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(
            shortLabels: false,
            groupByNamespace: false, // Disable clusters to use full labels
        ));
        $dot = $exporter->export($graph);

        $this->assertStringContainsString('label="App\\\\UserService"', $dot);
        $this->assertStringContainsString('label="App\\\\UserRepository"', $dot);
    }

    public function testEscapesSpecialCharacters(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Class"With"Quotes'),
                SymbolPath::fromClassFqn('App\\Another'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter();
        $dot = $exporter->export($graph);

        // Backslashes should be escaped as \\
        // Quotes should be escaped as \"
        $this->assertStringContainsString('\\"', $dot);
        $this->assertStringContainsString('\\\\', $dot);
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
        $exporter = new DotExporter(new DotExporterOptions(
            includeNamespaces: ['App\\Service'],
        ));
        $dot = $exporter->export($graph);

        $this->assertStringContainsString('Foo', $dot);
        $this->assertStringContainsString('Bar', $dot);
        $this->assertStringNotContainsString('FooTest', $dot);
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
        $exporter = new DotExporter(new DotExporterOptions(
            excludeNamespaces: ['App\\Tests'],
        ));
        $dot = $exporter->export($graph);

        $this->assertStringContainsString('Foo', $dot);
        $this->assertStringContainsString('Bar', $dot);
        $this->assertStringNotContainsString('FooTest', $dot);
    }

    public function testColorsByInstability(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Stable'),
                SymbolPath::fromClassFqn('App\\Unstable'),
                DependencyType::TypeHint,
                new Location('/test/file.php', 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(colorByInstability: true));
        $dot = $exporter->export($graph);

        // Should contain color information
        $this->assertStringContainsString('fillcolor=', $dot);
    }

    public function testDisablesColorByInstability(): void
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
        $exporter = new DotExporter(new DotExporterOptions(colorByInstability: false));
        $dot = $exporter->export($graph);

        $this->assertStringContainsString('fillcolor="lightblue"', $dot);
    }

    public function testChangesDirection(): void
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
        $exporter = new DotExporter(new DotExporterOptions(direction: 'TB'));
        $dot = $exporter->export($graph);

        $this->assertStringContainsString('rankdir=TB', $dot);
    }

    public function testExportsEmptyGraph(): void
    {
        $graph = $this->createGraph([]);
        $exporter = new DotExporter();
        $dot = $exporter->export($graph);

        $this->assertStringContainsString('digraph Dependencies', $dot);
        $this->assertStringContainsString('No classes to display', $dot);
    }

    public function testGetFormat(): void
    {
        $exporter = new DotExporter();
        $this->assertSame('dot', $exporter->getFormat());
    }

    public function testGetFileExtension(): void
    {
        $exporter = new DotExporter();
        $this->assertSame('dot', $exporter->getFileExtension());
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
        $exporter = new DotExporter(new DotExporterOptions(
            excludeNamespaces: ['App\\Tests'],
        ));
        $dot = $exporter->export($graph);

        // Edge should not appear because one node is filtered out
        $this->assertStringNotContainsString('Foo" -> "', $dot);
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
        $namespaceCe = [];
        $namespaceCa = [];

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
            $namespaceCe,
            $namespaceCa,
            [],
            [],
        );
    }
}
