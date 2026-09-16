<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SymbolVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolLevelProjection;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Split off from `SymbolLevelProjectionTest` (which keeps the per-kind
 * projection cases and their shared data provider): the case list is the
 * enum's own, so a seventh {@see SymbolType} fails here rather than
 * silently acquiring whichever level a `default` arm happened to give it.
 *
 * `provideDeclarationKinds()` is duplicated from `SymbolLevelProjectionTest`
 * rather than shared, per this stage's move rules.
 */
#[CoversClass(SymbolLevelProjection::class)]
final class SymbolTypeProjectionCensusTest extends TestCase
{
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
    private static function provideDeclarationKinds(): iterable
    {
        yield 'a method is a callable' => [SymbolType::Method, SymbolLevel::Callable];
        yield 'a function is the same callable level' => [SymbolType::Function_, SymbolLevel::Callable];
        yield 'a class' => [SymbolType::Class_, SymbolLevel::Class_];
        yield 'a file' => [SymbolType::File, SymbolLevel::File];
        yield 'a namespace' => [SymbolType::Namespace_, SymbolLevel::Namespace_];
        yield 'the project' => [SymbolType::Project, SymbolLevel::Project];
    }
}
