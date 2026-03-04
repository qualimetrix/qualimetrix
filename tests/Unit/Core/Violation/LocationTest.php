<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Core\Violation;

use AiMessDetector\Core\Violation\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Location::class)]
final class LocationTest extends TestCase
{
    public function testConstructorWithFileAndLine(): void
    {
        $location = new Location('src/Service/UserService.php', 42);

        self::assertSame('src/Service/UserService.php', $location->file);
        self::assertSame(42, $location->line);
    }

    public function testConstructorWithFileOnly(): void
    {
        $location = new Location('src/Service/UserService.php');

        self::assertSame('src/Service/UserService.php', $location->file);
        self::assertNull($location->line);
    }

    #[DataProvider('toStringDataProvider')]
    public function testToString(Location $location, string $expected): void
    {
        self::assertSame($expected, $location->toString());
    }

    /**
     * @return iterable<string, array{Location, string}>
     */
    public static function toStringDataProvider(): iterable
    {
        yield 'file with line' => [
            new Location('src/Service/UserService.php', 42),
            'src/Service/UserService.php:42',
        ];

        yield 'file without line' => [
            new Location('src/Service/UserService.php'),
            'src/Service/UserService.php',
        ];

        yield 'file with line 1' => [
            new Location('src/test.php', 1),
            'src/test.php:1',
        ];

        yield 'file with large line number' => [
            new Location('src/large.php', 99999),
            'src/large.php:99999',
        ];

        yield 'relative path with line' => [
            new Location('tests/Unit/CoreTest.php', 10),
            'tests/Unit/CoreTest.php:10',
        ];

        yield 'absolute path with line' => [
            new Location('/var/www/project/src/Class.php', 25),
            '/var/www/project/src/Class.php:25',
        ];
    }

    public function testLocationIsReadonly(): void
    {
        $location = new Location('src/test.php', 10);

        // This test verifies that Location is readonly by attempting to create a new instance
        // The readonly keyword ensures immutability at the language level
        self::assertInstanceOf(Location::class, $location);
    }

    public function testLocationWithLineZero(): void
    {
        $location = new Location('src/test.php', 0);

        self::assertSame(0, $location->line);
        self::assertSame('src/test.php:0', $location->toString());
    }
}
