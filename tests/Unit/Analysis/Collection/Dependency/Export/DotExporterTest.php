<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Collection\Dependency\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraph;
use Qualimetrix\Analysis\Collection\Dependency\Export\DotExporter;
use Qualimetrix\Analysis\Collection\Dependency\Export\DotExporterOptions;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;

final class DotExporterTest extends TestCase
{
    #[Test]
    public function itExportsValidDot(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\ServiceA'),
                SymbolPath::fromClassFqn('App\\ServiceB'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 10),
            ),
            new Dependency(
                SymbolPath::fromClassFqn('App\\ServiceB'),
                SymbolPath::fromClassFqn('App\\ServiceC'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 20),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter();
        $dot = $exporter->export($graph);

        self::assertStringContainsString('digraph Dependencies', $dot);
        self::assertStringContainsString('"App\\\\ServiceA" -> "App\\\\ServiceB"', $dot);
        self::assertStringContainsString('"App\\\\ServiceB" -> "App\\\\ServiceC"', $dot);
    }

    #[Test]
    public function itGroupsByNamespace(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Service\\UserService'),
                SymbolPath::fromClassFqn('App\\Repository\\UserRepository'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(groupByNamespace: true));
        $dot = $exporter->export($graph);

        self::assertStringContainsString('subgraph cluster_', $dot);
        self::assertStringContainsString('label="App\\\\Service"', $dot);
        self::assertStringContainsString('label="App\\\\Repository"', $dot);
    }

    #[Test]
    public function itUsesShortLabels(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Very\\Long\\Namespace\\UserService'),
                SymbolPath::fromClassFqn('App\\Very\\Long\\Namespace\\UserRepository'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(shortLabels: true));
        $dot = $exporter->export($graph);

        self::assertStringContainsString('label="UserService"', $dot);
        self::assertStringContainsString('label="UserRepository"', $dot);
    }

    #[Test]
    public function itUsesFullLabelsWhenDisabled(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\UserService'),
                SymbolPath::fromClassFqn('App\\UserRepository'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(
            shortLabels: false,
            groupByNamespace: false, // Disable clusters to use full labels
        ));
        $dot = $exporter->export($graph);

        self::assertStringContainsString('label="App\\\\UserService"', $dot);
        self::assertStringContainsString('label="App\\\\UserRepository"', $dot);
    }

    #[Test]
    public function itUsesFullLabelsInClusterModeWhenShortLabelsDisabled(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Service\\UserService'),
                SymbolPath::fromClassFqn('App\\Repository\\UserRepository'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(
            groupByNamespace: true,
            shortLabels: false,
        ));
        $dot = $exporter->export($graph);

        // With shortLabels=false and groupByNamespace=true, labels should be full FQN
        self::assertStringContainsString('label="App\\\\Service\\\\UserService"', $dot);
        self::assertStringContainsString('label="App\\\\Repository\\\\UserRepository"', $dot);
    }

    #[Test]
    public function itUsesShortLabelsInClusterMode(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Service\\UserService'),
                SymbolPath::fromClassFqn('App\\Repository\\UserRepository'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(
            groupByNamespace: true,
            shortLabels: true,
        ));
        $dot = $exporter->export($graph);

        // With shortLabels=true (default) in cluster mode, labels should be class name only
        self::assertStringContainsString('label="UserService"', $dot);
        self::assertStringContainsString('label="UserRepository"', $dot);
    }

    #[Test]
    public function itEscapesSpecialCharacters(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Class"With"Quotes'),
                SymbolPath::fromClassFqn('App\\Another'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter();
        $dot = $exporter->export($graph);

        // Backslashes should be escaped as \\
        // Quotes should be escaped as \"
        self::assertStringContainsString('\\"', $dot);
        self::assertStringContainsString('\\\\', $dot);
    }

    #[Test]
    public function itFiltersIncludeNamespaces(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Service\\Foo'),
                SymbolPath::fromClassFqn('App\\Service\\Bar'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 10),
            ),
            new Dependency(
                SymbolPath::fromClassFqn('App\\Tests\\FooTest'),
                SymbolPath::fromClassFqn('App\\Service\\Foo'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 20),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(
            includeNamespaces: ['App\\Service'],
        ));
        $dot = $exporter->export($graph);

        self::assertStringContainsString('Foo', $dot);
        self::assertStringContainsString('Bar', $dot);
        self::assertStringNotContainsString('FooTest', $dot);
    }

    #[Test]
    public function itFiltersExcludeNamespaces(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Service\\Foo'),
                SymbolPath::fromClassFqn('App\\Service\\Bar'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 10),
            ),
            new Dependency(
                SymbolPath::fromClassFqn('App\\Tests\\FooTest'),
                SymbolPath::fromClassFqn('App\\Service\\Foo'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 20),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(
            excludeNamespaces: ['App\\Tests'],
        ));
        $dot = $exporter->export($graph);

        self::assertStringContainsString('Foo', $dot);
        self::assertStringContainsString('Bar', $dot);
        self::assertStringNotContainsString('FooTest', $dot);
    }

    #[Test]
    public function itColorsByInstability(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Stable'),
                SymbolPath::fromClassFqn('App\\Unstable'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(colorByInstability: true));
        $dot = $exporter->export($graph);

        // Should contain color information
        self::assertStringContainsString('fillcolor=', $dot);
    }

    #[Test]
    public function itDisablesColorByInstability(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\ServiceA'),
                SymbolPath::fromClassFqn('App\\ServiceB'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(colorByInstability: false));
        $dot = $exporter->export($graph);

        self::assertStringContainsString('fillcolor="lightblue"', $dot);
    }

    #[Test]
    public function itChangesDirection(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\ServiceA'),
                SymbolPath::fromClassFqn('App\\ServiceB'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(direction: 'TB'));
        $dot = $exporter->export($graph);

        self::assertStringContainsString('rankdir=TB', $dot);
    }

    #[Test]
    public function itExportsEmptyGraph(): void
    {
        $graph = $this->createGraph([]);
        $exporter = new DotExporter();
        $dot = $exporter->export($graph);

        self::assertStringContainsString('digraph Dependencies', $dot);
        self::assertStringContainsString('No classes to display', $dot);
    }

    #[Test]
    public function itGetsFormat(): void
    {
        $exporter = new DotExporter();
        self::assertSame('dot', $exporter->getFormat());
    }

    #[Test]
    public function itGetsFileExtension(): void
    {
        $exporter = new DotExporter();
        self::assertSame('dot', $exporter->getFileExtension());
    }

    #[Test]
    public function itFiltersEdgesWhenNodesAreFiltered(): void
    {
        $dependencies = [
            new Dependency(
                SymbolPath::fromClassFqn('App\\Service\\Foo'),
                SymbolPath::fromClassFqn('App\\Tests\\FooTest'),
                DependencyType::TypeHint,
                new Location(RelativePath::fromString('test/file.php'), 10),
            ),
        ];

        $graph = $this->createGraph($dependencies);
        $exporter = new DotExporter(new DotExporterOptions(
            excludeNamespaces: ['App\\Tests'],
        ));
        $dot = $exporter->export($graph);

        // Edge should not appear because one node is filtered out
        self::assertStringNotContainsString('Foo" -> "', $dot);
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
