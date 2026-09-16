<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SymbolVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolLevelProjection;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Tests\Core\Symbol\Unit\SymbolLevelProjectionTest;

/**
 * Split off from `SymbolLevelProjectionTest` (which keeps the per-kind
 * projection cases): the case list is the enum's own, so a seventh
 * {@see SymbolType} fails here rather than silently acquiring whichever
 * level a `default` arm happened to give it.
 *
 * Reads `SymbolLevelProjectionTest::provideDeclarationKinds()` directly
 * rather than a copy — the provider IS the behavioural test's case list, so
 * a census over its own copy would only prove the copy covers the enum,
 * never the list the behavioural test actually executes.
 */
#[CoversClass(SymbolLevelProjection::class)]
final class SymbolTypeProjectionCensusTest extends TestCase
{
    #[Test]
    public function itCoversEverySymbolType(): void
    {
        self::assertSame(
            array_map(static fn(SymbolType $type): string => $type->value, SymbolType::cases()),
            array_map(
                static fn(array $case): string => $case[0]->value,
                iterator_to_array(SymbolLevelProjectionTest::provideDeclarationKinds(), false),
            ),
        );
    }
}
