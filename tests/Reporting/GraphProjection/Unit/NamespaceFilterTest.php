<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\GraphProjection\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\DependencyGraph;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphExportFormat;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphProjectionRequest;
use Qualimetrix\Reporting\GraphProjection\DependencyGraphProjector;
use Qualimetrix\Reporting\GraphProjection\NamespaceFilter;
use Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub;

#[CoversClass(NamespaceFilter::class)]
final class NamespaceFilterTest extends TestCase
{
    /** @return iterable<string, array{list<string>|null, list<string>, list<string>}> */
    public static function provideFilterCases(): iterable
    {
        yield 'no filters keeps every class' => [null, [], [
            'App\\Excluded\\Ignored',
            'App\\Service\\Consumer',
            'App\\Service\\Producer',
            'Other\\Outsider',
        ]];
        yield 'include by exact namespace' => [['App\\Service'], [], [
            'App\\Service\\Consumer',
            'App\\Service\\Producer',
        ]];
        yield 'include by parent namespace' => [['App'], [], [
            'App\\Excluded\\Ignored',
            'App\\Service\\Consumer',
            'App\\Service\\Producer',
        ]];
        yield 'exclude wins over include' => [['App'], ['App\\Excluded'], [
            'App\\Service\\Consumer',
            'App\\Service\\Producer',
        ]];
        yield 'exclude alone' => [null, ['App'], ['Other\\Outsider']];
        yield 'a miss selects nothing' => [['Zzz\\Nope'], [], []];
        yield 'prefix stops at a namespace separator' => [['App\\Serv'], [], []];
    }

    /**
     * The merge of the two exporters' former private copies has to be
     * provable, not asserted: both renderings and the filter itself are asked
     * for the same class set, so a copy growing back in either exporter — or
     * the projector answering a binding question the exporters disagree with —
     * fails here.
     *
     * @param list<string>|null $include
     * @param list<string> $exclude
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideFilterCases')]
    public function itSelectsTheSameClassesInEveryExporter(?array $include, array $exclude, array $expected): void
    {
        $graph = self::graph();
        $includePatterns = self::patterns($include);
        $excludePatterns = self::patterns($exclude) ?? [];
        $request = new GraphProjectionRequest(includeNamespaces: $includePatterns, excludeNamespaces: $excludePatterns);
        $projector = new DependencyGraphProjector();

        $admitted = array_map(
            static fn(SymbolPath $path): string => $path->toString(),
            (new NamespaceFilter($includePatterns, $excludePatterns))->apply($graph->getAllClasses()),
        );
        sort($admitted);
        self::assertSame($expected, $admitted);

        $json = $projector->project($graph, new GraphProjectionRequest(
            format: GraphExportFormat::Json,
            includeNamespaces: $includePatterns,
            excludeNamespaces: $excludePatterns,
        ));
        /** @var array{nodes: list<array{fqn: string}>} $decoded */
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $jsonNodes = array_column($decoded['nodes'], 'fqn');
        sort($jsonNodes);
        self::assertSame($expected, $jsonNodes, 'the JSON exporter selects a different set');

        $dot = $projector->project($graph, new GraphProjectionRequest(
            format: GraphExportFormat::Dot,
            includeNamespaces: $includePatterns,
            excludeNamespaces: $excludePatterns,
        ));
        self::assertSame($expected, self::dotNodes($dot), 'the DOT exporter selects a different set');

        // And the binding answer the command refuses on comes from the same
        // comparison: nothing is rendered that the projector calls unbound.
        foreach ($projector->unboundIncludeNamespaces($graph, $request) as $unbound) {
            foreach ($expected as $rendered) {
                self::assertStringStartsNotWith($unbound . '\\', $rendered);
            }
        }
    }

    /** @return iterable<string, array{list<string>|null, list<string>, list<string>}> */
    public static function provideBindingCases(): iterable
    {
        yield 'no include values' => [null, [], []];
        yield 'every value binds' => [['App\\Service', 'Other'], [], []];
        yield 'one value of two misses' => [['App\\Service', 'Zzz\\Nope'], [], ['Zzz\\Nope']];
        yield 'both values miss, order preserved' => [['Zzz\\Nope', 'Aaa\\None'], [], ['Zzz\\Nope', 'Aaa\\None']];
        yield 'a partial namespace name is not a binding' => [['App\\Serv'], [], ['App\\Serv']];
        yield 'a value emptied by an exclude still binds' => [['App\\Excluded'], ['App\\Excluded'], []];
    }

    /**
     * @param list<string>|null $include
     * @param list<string> $exclude
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideBindingCases')]
    public function itReportsOnlyIncludeValuesThatMatchNoClass(?array $include, array $exclude, array $expected): void
    {
        $unbound = (new DependencyGraphProjector())->unboundIncludeNamespaces(
            self::graph(),
            new GraphProjectionRequest(includeNamespaces: self::patterns($include), excludeNamespaces: self::patterns($exclude) ?? []),
        );

        self::assertSame(array_map(static fn(string $value): string => 'subtree:' . $value, $expected), $unbound);
    }

    /**
     * A miss on the excluding door is deliberately not a refusal, so it must
     * not be a binding report either: it leaves the graph as it would have
     * been.
     */
    #[Test]
    public function itIgnoresExcludeNamespacesThatMatchNothing(): void
    {
        $graph = self::graph();
        $projector = new DependencyGraphProjector();

        self::assertSame([], $projector->unboundIncludeNamespaces(
            $graph,
            new GraphProjectionRequest(excludeNamespaces: self::patterns(['Zzz\\Nope']) ?? []),
        ));
        self::assertSame(
            $projector->project($graph, new GraphProjectionRequest()),
            $projector->project($graph, new GraphProjectionRequest(excludeNamespaces: self::patterns(['Zzz\\Nope']) ?? [])),
        );
    }

    #[Test]
    public function itCombinesIncludesWithOrAndLetsARegexExclusionWin(): void
    {
        $filter = new NamespaceFilter(
            [
                NamespacePatternStub::exact('App\\Service'),
                NamespacePatternStub::regex('Other'),
            ],
            [NamespacePatternStub::regex('App\\\\Service')],
        );

        $admitted = array_map(
            static fn(SymbolPath $path): string => $path->toString(),
            $filter->apply(self::graph()->getAllClasses()),
        );

        self::assertSame(['Other\\Outsider'], $admitted);
    }

    /**
     * @param list<string>|null $values
     *
     * @return list<\Qualimetrix\Core\Pattern\NamespacePattern>|null
     */
    private static function patterns(?array $values): ?array
    {
        return $values === null
            ? null
            : array_map(NamespacePatternStub::subtree(...), $values);
    }

    /** @return list<string> */
    private static function dotNodes(string $dot): array
    {
        preg_match_all('/^\s+"([^"]+)" \[label=/m', $dot, $matches);
        $nodes = array_map(static fn(string $node): string => stripslashes($node), $matches[1]);
        sort($nodes);

        return $nodes;
    }

    private static function graph(): DependencyGraph
    {
        $producer = SymbolPath::fromClassFqn('App\\Service\\Producer');
        $consumer = SymbolPath::fromClassFqn('App\\Service\\Consumer');
        $ignored = SymbolPath::fromClassFqn('App\\Excluded\\Ignored');
        $outsider = SymbolPath::fromClassFqn('Other\\Outsider');

        return new DependencyGraph(
            dependencies: [
                new Dependency(
                    DeclarationPath::of($producer, RelativePath::fromString('Producer.php'), DeclarationOrdinal::fromRank(0)),
                    new LogicalClassPath($consumer),
                    DependencyType::TypeHint,
                    new Location(RelativePath::fromString('Producer.php'), 10),
                ),
                new Dependency(
                    DeclarationPath::of($consumer, RelativePath::fromString('Consumer.php'), DeclarationOrdinal::fromRank(0)),
                    new LogicalClassPath($ignored),
                    DependencyType::TypeHint,
                    new Location(RelativePath::fromString('Consumer.php'), 12),
                ),
            ],
            bySource: [],
            byTarget: [],
            classes: [$producer, $consumer, $ignored, $outsider],
            namespaces: [],
            namespaceCe: [],
            namespaceCa: [],
            classCe: [],
            classCa: [],
        );
    }
}
