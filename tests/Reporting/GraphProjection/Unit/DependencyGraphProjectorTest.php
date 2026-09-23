<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\GraphProjection\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\DependencyGraph;
use Qualimetrix\Analysis\Evidence\DependencyModel\NamespaceCouplings;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphDirection;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphExportFormat;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphProjectionRequest;
use Qualimetrix\Reporting\GraphProjection\DependencyGraphProjector;
use Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub;

final class DependencyGraphProjectorTest extends TestCase
{
    /** @return iterable<string, array{GraphProjectionRequest, string}> */
    public static function provideSupportedFormats(): iterable
    {
        yield 'dot' => [new GraphProjectionRequest(format: GraphExportFormat::Dot), 'digraph Dependencies'];
        yield 'json' => [new GraphProjectionRequest(format: GraphExportFormat::Json), '"statistics"'];
    }

    #[Test]
    #[DataProvider('provideSupportedFormats')]
    public function itProjectsSupportedFormats(GraphProjectionRequest $request, string $expectedFragment): void
    {
        $projection = (new DependencyGraphProjector())->project($this->graph(), $request);

        self::assertStringContainsString($expectedFragment, $projection);
    }

    #[Test]
    public function itForwardsEveryRequestFieldToDotProjection(): void
    {
        $projection = (new DependencyGraphProjector())->project($this->graph(), new GraphProjectionRequest(
            format: GraphExportFormat::Dot,
            direction: GraphDirection::TB,
            groupByNamespace: false,
            includeNamespaces: [NamespacePatternStub::subtree('App')],
            excludeNamespaces: [NamespacePatternStub::subtree('App\\Excluded')],
        ));

        self::assertStringContainsString('rankdir=TB', $projection);
        self::assertStringNotContainsString('subgraph cluster_', $projection);
        self::assertStringContainsString('App\\\\Service\\\\Producer', $projection);
        self::assertStringNotContainsString('App\\\\Excluded\\\\Ignored', $projection);
    }

    #[Test]
    public function itForwardsNamespaceFiltersToJsonProjection(): void
    {
        $projection = (new DependencyGraphProjector())->project($this->graph(), new GraphProjectionRequest(
            format: GraphExportFormat::Json,
            includeNamespaces: [NamespacePatternStub::subtree('App')],
            excludeNamespaces: [NamespacePatternStub::subtree('App\\Excluded')],
        ));
        /** @var array{nodes: list<array{fqn: string}>} $decoded */
        $decoded = json_decode($projection, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame([
            'App\\Service\\Consumer',
            'App\\Service\\Producer',
        ], array_column($decoded['nodes'], 'fqn'));
    }

    private function graph(): DependencyGraph
    {
        $producer = SymbolPath::fromClassFqn('App\\Service\\Producer');
        $consumer = SymbolPath::fromClassFqn('App\\Service\\Consumer');
        $ignored = SymbolPath::fromClassFqn('App\\Excluded\\Ignored');

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
            classes: [$producer, $consumer, $ignored],
            namespaces: [],
            namespaceCouplings: NamespaceCouplings::none(),
            classCe: [],
            classCa: [],
        );
    }
}
