<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Symbol\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolLevelProjection;
use Qualimetrix\Core\Symbol\SymbolType;

#[CoversClass(SymbolLevelProjection::class)]
final class SymbolLevelProjectionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideDeclarationKinds')]
    public function itProjectsEveryDeclarationKindOntoItsAggregationLevel(SymbolType $type, SymbolLevel $expected): void
    {
        self::assertSame($expected, SymbolLevelProjection::ofDeclaration($type));
    }

    /**
     * @return iterable<string, array{SymbolType, SymbolLevel}>
     */
    public static function provideDeclarationKinds(): iterable
    {
        yield 'a method is a callable' => [SymbolType::Method, SymbolLevel::Callable];
        yield 'a function is the same callable level' => [SymbolType::Function_, SymbolLevel::Callable];
        yield 'a class' => [SymbolType::Class_, SymbolLevel::Class_];
        yield 'a file' => [SymbolType::File, SymbolLevel::File];
        yield 'a namespace' => [SymbolType::Namespace_, SymbolLevel::Namespace_];
        yield 'the project' => [SymbolType::Project, SymbolLevel::Project];
    }
}
