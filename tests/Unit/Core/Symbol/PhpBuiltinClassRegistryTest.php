<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;

#[CoversClass(PhpBuiltinClassRegistry::class)]
final class PhpBuiltinClassRegistryTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function commonBuiltinClassesProvider(): iterable
    {
        yield 'Exception' => ['Exception'];
        yield 'stdClass' => ['stdClass'];
        yield 'Iterator' => ['Iterator'];
        yield 'PDO' => ['PDO'];
        yield 'SplStack' => ['SplStack'];
        yield 'DateTime' => ['DateTime'];
        yield 'Closure' => ['Closure'];
        yield 'Throwable' => ['Throwable'];
        yield 'JsonSerializable' => ['JsonSerializable'];
        yield 'Random\\Randomizer' => ['Random\\Randomizer'];
        yield 'Dom\\Document' => ['Dom\\Document'];
    }

    #[DataProvider('commonBuiltinClassesProvider')]
    public function testCommonBuiltinClassesAreRecognized(string $className): void
    {
        self::assertTrue(PhpBuiltinClassRegistry::isBuiltin($className));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function userClassesProvider(): iterable
    {
        yield 'App\\Service' => ['App\\Service'];
        yield 'MyClass' => ['MyClass'];
        yield 'Vendor\\Package\\SomeClass' => ['Vendor\\Package\\SomeClass'];
        yield 'App\\Exception' => ['App\\Exception'];
    }

    #[DataProvider('userClassesProvider')]
    public function testUserClassesAreNotBuiltin(string $className): void
    {
        self::assertFalse(PhpBuiltinClassRegistry::isBuiltin($className));
    }

    public function testCaseSensitivity(): void
    {
        // PHP class names in the registry are case-sensitive
        self::assertFalse(PhpBuiltinClassRegistry::isBuiltin('exception'));
        self::assertFalse(PhpBuiltinClassRegistry::isBuiltin('EXCEPTION'));
        self::assertFalse(PhpBuiltinClassRegistry::isBuiltin('stdclass'));
        self::assertTrue(PhpBuiltinClassRegistry::isBuiltin('Exception'));
        self::assertTrue(PhpBuiltinClassRegistry::isBuiltin('stdClass'));
    }
}
