<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevelProjection;
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
     * The case list is the enum's own, so a seventh {@see SymbolType} fails
     * here rather than silently acquiring whichever level a `default` arm
     * happened to give it.
     */
    #[Test]
    public function itCoversEverySymbolType(): void
    {
        self::assertSame(
            array_map(static fn(SymbolType $type): string => $type->value, SymbolType::cases()),
            array_map(static fn(array $case): string => $case[0]->value, iterator_to_array(self::provideDeclarationKinds(), false)),
        );
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
