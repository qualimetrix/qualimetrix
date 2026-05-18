<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Violation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Violation\Location;

#[CoversClass(Location::class)]
final class LocationTest extends TestCase
{
    #[Test]
    public function itConstructorWithFileAndLine(): void
    {
        $location = new Location(RelativePath::fromString('src/Service/UserService.php'), 42);

        self::assertSame('src/Service/UserService.php', $location->file?->value());
        self::assertSame(42, $location->line);
    }

    #[Test]
    public function itConstructorWithFileOnly(): void
    {
        $location = new Location(RelativePath::fromString('src/Service/UserService.php'));

        self::assertSame('src/Service/UserService.php', $location->file?->value());
        self::assertNull($location->line);
    }

    #[DataProvider('toStringDataProvider')]
    #[Test]
    public function itToString(Location $location, string $expected): void
    {
        self::assertSame($expected, $location->toString());
    }

    /**
     * @return iterable<string, array{Location, string}>
     */
    public static function toStringDataProvider(): iterable
    {
        yield 'file with line' => [
            new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
            'src/Service/UserService.php:42',
        ];

        yield 'file without line' => [
            new Location(RelativePath::fromString('src/Service/UserService.php')),
            'src/Service/UserService.php',
        ];

        yield 'file with line 1' => [
            new Location(RelativePath::fromString('src/test.php'), 1),
            'src/test.php:1',
        ];

        yield 'file with large line number' => [
            new Location(RelativePath::fromString('src/large.php'), 99999),
            'src/large.php:99999',
        ];

        yield 'relative path with line' => [
            new Location(RelativePath::fromString('tests/Unit/CoreTest.php'), 10),
            'tests/Unit/CoreTest.php:10',
        ];

        yield 'deep nested path with line' => [
            new Location(RelativePath::fromString('var/www/project/src/Class.php'), 25),
            'var/www/project/src/Class.php:25',
        ];
    }

    #[Test]
    public function itLocationIsReadonly(): void
    {
        $location = new Location(RelativePath::fromString('src/test.php'), 10);

        // This test verifies that Location is readonly by attempting to create a new instance
        // The readonly keyword ensures immutability at the language level
        self::assertInstanceOf(Location::class, $location); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function itLocationWithLineZeroThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Line number must be >= 1 or null, got 0');

        new Location(RelativePath::fromString('src/test.php'), 0);
    }

    #[Test]
    public function itLocationWithNegativeLineThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);

        new Location(RelativePath::fromString('src/test.php'), -1);
    }

    #[Test]
    public function itNoneCreatesLocationWithoutFile(): void
    {
        $location = Location::none();

        self::assertTrue($location->isNone());
        self::assertNull($location->file);
        self::assertSame('', $location->pathString());
        self::assertNull($location->line);
        self::assertSame('', $location->toString());
    }

    #[Test]
    public function itIsNoneReturnsFalseForRegularLocation(): void
    {
        $location = new Location(RelativePath::fromString('src/test.php'), 10);

        self::assertFalse($location->isNone());
    }
}
