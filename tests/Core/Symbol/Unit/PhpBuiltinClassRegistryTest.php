<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;
use ReflectionClass;

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

        // One per extension family the list did not cover at all until the
        // census control compared it against a PHP. `SessionHandler` is the
        // one that was found first: `NativeFileSessionHandler extends
        // \SessionHandler` left the inheritance walk unable to resolve a
        // parent PHP had had all along.
        yield 'SessionHandler' => ['SessionHandler'];
        yield 'SessionHandlerInterface' => ['SessionHandlerInterface'];
        yield 'SessionIdInterface' => ['SessionIdInterface'];
        yield 'SessionUpdateTimestampHandlerInterface' => ['SessionUpdateTimestampHandlerInterface'];
        yield 'SQLite3' => ['SQLite3'];
        yield 'Phar' => ['Phar'];
        yield 'SoapClient' => ['SoapClient'];
        yield 'finfo' => ['finfo'];
        yield 'PhpToken' => ['PhpToken'];
        yield 'HashContext' => ['HashContext'];
        yield 'OpenSSLCertificate' => ['OpenSSLCertificate'];
        yield 'Socket' => ['Socket'];
        yield 'FFI\\CData' => ['FFI\\CData'];
        yield 'Uri\\Rfc3986\\Uri' => ['Uri\\Rfc3986\\Uri'];
        yield 'XSLTProcessor' => ['XSLTProcessor'];
        yield 'EnchantBroker' => ['EnchantBroker'];
        yield 'Pcntl\\QosClass' => ['Pcntl\\QosClass'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function absentNamesProvider(): iterable
    {
        // php-src declares no such class on any branch; it sat in the list
        // until the census control compared the list against a PHP.
        yield 'Pcntl\\QueuedSignalInfo' => ['Pcntl\\QueuedSignalInfo'];

        // PECL, so deliberately out of scope -- see the registry docblock.
        yield 'APCUIterator' => ['APCUIterator'];
        yield 'Redis' => ['Redis'];
    }

    #[DataProvider('absentNamesProvider')]
    #[Test]
    public function itDoesNotRecogniseNamesOutsideItsScope(string $className): void
    {
        self::assertFalse(PhpBuiltinClassRegistry::isBuiltin($className));
    }

    #[DataProvider('commonBuiltinClassesProvider')]
    #[Test]
    public function itCommonBuiltinClassesAreRecognized(string $className): void
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
    #[Test]
    public function itUserClassesAreNotBuiltin(string $className): void
    {
        self::assertFalse(PhpBuiltinClassRegistry::isBuiltin($className));
    }

    /**
     * PHP folds class names by ASCII case, so `\arrayobject` names the same
     * class as `\ArrayObject`. Every registered name has to be recognised in
     * any spelling, and answered with the one spelling the registry keeps.
     *
     * @return iterable<string, array{string}>
     */
    public static function registeredNamesProvider(): iterable
    {
        /** @var array<string, true> $classes */
        $classes = (new ReflectionClass(PhpBuiltinClassRegistry::class))->getConstant('BUILTIN_CLASSES');

        foreach (array_keys($classes) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('registeredNamesProvider')]
    #[Test]
    public function itRecognisesARegisteredNameInAnyCaseSpelling(string $className): void
    {
        foreach ([$className, strtolower($className), strtoupper($className)] as $spelling) {
            self::assertTrue(PhpBuiltinClassRegistry::isBuiltin($spelling), $spelling);
            self::assertSame($className, PhpBuiltinClassRegistry::canonicalName($spelling), $spelling);
        }
    }

    #[Test]
    public function itFoldsCaseTheWayPhpDoesAndNoFurther(): void
    {
        self::assertSame('Exception', PhpBuiltinClassRegistry::canonicalName('eXcEpTiOn'));
        self::assertSame('FFI\\CData', PhpBuiltinClassRegistry::canonicalName('ffi\\cdata'));
        self::assertNull(PhpBuiltinClassRegistry::canonicalName('App\\Exception'));
        self::assertNull(PhpBuiltinClassRegistry::canonicalName('\\Exception'), 'a leading separator is the caller\'s to strip');
    }
}
