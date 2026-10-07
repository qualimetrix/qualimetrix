<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Unit;

use Generator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;

final class AdjacencyGraphBuilderTest extends TestCase
{
    #[Test]
    public function itPreservesDeclarationsFromASinglePassIterable(): void
    {
        $declaration = ClassLikeDeclaration::of(
            DeclarationPath::of(
                SymbolPath::fromClassFqn('Fixture\\Only'),
                RelativePath::fromString('src/Only.php'),
                DeclarationOrdinal::fromRank(0),
            ),
            ClassType::Class_,
            false,
            false,
        );
        $declarations = (static function () use ($declaration): Generator {
            yield $declaration;
        })();

        $graph = AdjacencyGraphBuilder::builder()->build([], $declarations);

        self::assertSame(['Fixture\\Only'], array_map(
            static fn(SymbolPath $class): string => $class->toString(),
            $graph->getAllClasses(),
        ));
        self::assertSame([$declaration], $graph->getClassLikeDeclarations());
    }
}
