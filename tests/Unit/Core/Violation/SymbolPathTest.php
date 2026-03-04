<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Core\Violation;

use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\SymbolPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolPath::class)]
final class SymbolPathTest extends TestCase
{
    #[DataProvider('canonicalDataProvider')]
    public function testToCanonical(SymbolPath $symbolPath, string $expected): void
    {
        self::assertSame($expected, $symbolPath->toCanonical());
    }

    /**
     * @return iterable<string, array{SymbolPath, string}>
     */
    public static function canonicalDataProvider(): iterable
    {
        yield 'method with namespace' => [
            SymbolPath::forMethod('App\Service', 'UserService', 'calculateTotal'),
            'method:App\Service\UserService::calculateTotal',
        ];

        yield 'method without namespace' => [
            SymbolPath::forMethod('', 'UserService', 'calculate'),
            'method:UserService::calculate',
        ];

        yield 'class with namespace' => [
            SymbolPath::forClass('App\Service', 'UserService'),
            'class:App\Service\UserService',
        ];

        yield 'class without namespace' => [
            SymbolPath::forClass('', 'UserService'),
            'class:UserService',
        ];

        yield 'namespace' => [
            SymbolPath::forNamespace('App\Service'),
            'ns:App\Service',
        ];

        yield 'empty namespace' => [
            SymbolPath::forNamespace(''),
            'ns:',
        ];

        yield 'file' => [
            SymbolPath::forFile('src/Service/UserService.php'),
            'file:src/Service/UserService.php',
        ];

        yield 'global function' => [
            SymbolPath::forGlobalFunction('', 'globalFunction'),
            'func::globalFunction',
        ];

        yield 'namespaced function' => [
            SymbolPath::forGlobalFunction('App\Utils', 'helper'),
            'func:App\Utils::helper',
        ];
    }

    public function testForMethodCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');

        self::assertSame('App\Service', $symbolPath->namespace);
        self::assertSame('UserService', $symbolPath->type);
        self::assertSame('calculate', $symbolPath->member);
    }

    public function testForClassCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');

        self::assertSame('App\Service', $symbolPath->namespace);
        self::assertSame('UserService', $symbolPath->type);
        self::assertNull($symbolPath->member);
    }

    public function testForNamespaceCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forNamespace('App\Service');

        self::assertSame('App\Service', $symbolPath->namespace);
        self::assertNull($symbolPath->type);
        self::assertNull($symbolPath->member);
    }

    public function testForFileCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forFile('src/test.php');

        self::assertNull($symbolPath->namespace);
        self::assertNull($symbolPath->type);
        self::assertNull($symbolPath->member);
    }

    public function testForGlobalFunctionCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forGlobalFunction('', 'myFunction');

        self::assertNull($symbolPath->namespace);
        self::assertNull($symbolPath->type);
        self::assertSame('myFunction', $symbolPath->member);
    }

    #[DataProvider('typeDataProvider')]
    public function testGetType(SymbolPath $symbolPath, SymbolType $expected): void
    {
        self::assertSame($expected, $symbolPath->getType());
    }

    /**
     * @return iterable<string, array{SymbolPath, SymbolType}>
     */
    public static function typeDataProvider(): iterable
    {
        yield 'method' => [
            SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            SymbolType::Method,
        ];

        yield 'method without namespace' => [
            SymbolPath::forMethod('', 'UserService', 'calculate'),
            SymbolType::Method,
        ];

        yield 'class' => [
            SymbolPath::forClass('App\Service', 'UserService'),
            SymbolType::Class_,
        ];

        yield 'class without namespace' => [
            SymbolPath::forClass('', 'UserService'),
            SymbolType::Class_,
        ];

        yield 'namespace' => [
            SymbolPath::forNamespace('App\Service'),
            SymbolType::Namespace_,
        ];

        yield 'empty namespace' => [
            SymbolPath::forNamespace(''),
            SymbolType::Namespace_,
        ];

        yield 'file' => [
            SymbolPath::forFile('src/test.php'),
            SymbolType::File,
        ];

        yield 'global function' => [
            SymbolPath::forGlobalFunction('', 'strlen'),
            SymbolType::Function_,
        ];

        yield 'namespaced function' => [
            SymbolPath::forGlobalFunction('App\Utils', 'helper'),
            SymbolType::Function_,
        ];
    }

    #[DataProvider('symbolNameDataProvider')]
    public function testGetSymbolName(SymbolPath $symbolPath, ?string $expected): void
    {
        self::assertSame($expected, $symbolPath->getSymbolName());
    }

    /**
     * @return iterable<string, array{SymbolPath, ?string}>
     */
    public static function symbolNameDataProvider(): iterable
    {
        yield 'method with namespace' => [
            SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            'UserService::calculate',
        ];

        yield 'method without namespace' => [
            SymbolPath::forMethod('', 'UserService', 'calculate'),
            'UserService::calculate',
        ];

        yield 'class with namespace' => [
            SymbolPath::forClass('App\Service', 'UserService'),
            'UserService',
        ];

        yield 'class without namespace' => [
            SymbolPath::forClass('', 'UserService'),
            'UserService',
        ];

        yield 'namespace' => [
            SymbolPath::forNamespace('App\Service'),
            null,
        ];

        yield 'file' => [
            SymbolPath::forFile('src/test.php'),
            null,
        ];

        yield 'global function' => [
            SymbolPath::forGlobalFunction('', 'strlen'),
            'strlen',
        ];

        yield 'namespaced function' => [
            SymbolPath::forGlobalFunction('App\Utils', 'helper'),
            'helper',
        ];
    }
}
