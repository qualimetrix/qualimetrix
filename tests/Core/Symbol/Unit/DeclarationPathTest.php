<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(DeclarationPath::class)]
final class DeclarationPathTest extends TestCase
{
    #[Test]
    public function itBuildsAStableCanonicalIdentityAndAddsAnOrdinalOnlyForCollisions(): void
    {
        $logical = SymbolPath::forMethod('App', 'Service', 'handle');
        $file = RelativePath::fromString('src/Service.php');

        self::assertSame(
            'declaration:callable:App\\Service::handle@src/Service.php',
            (DeclarationPath::of($logical, $file, DeclarationOrdinal::fromRank(0)))->toCanonical(),
        );
        self::assertSame(
            'declaration:callable:App\\Service::handle@src/Service.php#1',
            (DeclarationPath::of($logical, $file, DeclarationOrdinal::fromRank(1)))->toCanonical(),
        );
    }

    #[Test]
    public function itRejectsANegativeOrdinalRank(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeclarationOrdinal::fromRank(-1);
    }

    #[Test]
    #[DataProvider('provideDeclarationSymbols')]
    public function itAcceptsEveryExistingDeclarationSymbolType(SymbolPath $logical): void
    {
        self::assertStringStartsWith(
            'declaration:',
            (DeclarationPath::of($logical, RelativePath::fromString('src/Declaration.php'), DeclarationOrdinal::fromRank(0)))->toCanonical(),
        );
    }

    #[Test]
    #[DataProvider('provideAggregateSymbols')]
    public function itRejectsAnAggregateLogicalSymbol(SymbolPath $logical): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Declaration logical symbol must identify a class, method, or function');

        DeclarationPath::of($logical, RelativePath::fromString('src/Aggregate.php'), DeclarationOrdinal::fromRank(0));
    }

    /**
     * @return array<string, array{SymbolPath}>
     */
    public static function provideDeclarationSymbols(): array
    {
        return [
            'class' => [SymbolPath::forClass('App', 'Service')],
            'method' => [SymbolPath::forMethod('App', 'Service', 'handle')],
            'function' => [SymbolPath::forGlobalFunction('App', 'handle')],
        ];
    }

    /**
     * @return array<string, array{SymbolPath}>
     */
    public static function provideAggregateSymbols(): array
    {
        return [
            'file' => [SymbolPath::forFile(RelativePath::fromString('src/File.php'))],
            'namespace' => [SymbolPath::forNamespace('App')],
            'project' => [SymbolPath::forProject()],
        ];
    }
}
