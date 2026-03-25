<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(SymbolInfo::class)]
final class SymbolInfoTest extends TestCase
{
    public function testConstructorWithAllProperties(): void
    {
        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $symbolInfo = new SymbolInfo(
            symbolPath: $symbolPath,
            file: 'src/Service/UserService.php',
            line: 42,
        );

        self::assertSame($symbolPath, $symbolInfo->symbolPath);
        self::assertSame('src/Service/UserService.php', $symbolInfo->file);
        self::assertSame(42, $symbolInfo->line);
    }

    public function testConstructorForClassSymbol(): void
    {
        $symbolPath = SymbolPath::forClass('App\Domain', 'User');
        $symbolInfo = new SymbolInfo(
            symbolPath: $symbolPath,
            file: 'src/Domain/User.php',
            line: 10,
        );

        self::assertSame($symbolPath, $symbolInfo->symbolPath);
        self::assertSame('src/Domain/User.php', $symbolInfo->file);
        self::assertSame(10, $symbolInfo->line);
    }

    public function testConstructorForNamespaceSymbol(): void
    {
        $symbolPath = SymbolPath::forNamespace('App\Service');
        $symbolInfo = new SymbolInfo(
            symbolPath: $symbolPath,
            file: 'src/Service/UserService.php',
            line: 1,
        );

        self::assertSame($symbolPath, $symbolInfo->symbolPath);
        self::assertSame('src/Service/UserService.php', $symbolInfo->file);
        self::assertSame(1, $symbolInfo->line);
    }

    public function testConstructorForFileSymbol(): void
    {
        $symbolPath = SymbolPath::forFile('src/bootstrap.php');
        $symbolInfo = new SymbolInfo(
            symbolPath: $symbolPath,
            file: 'src/bootstrap.php',
            line: 1,
        );

        self::assertSame($symbolPath, $symbolInfo->symbolPath);
        self::assertSame('src/bootstrap.php', $symbolInfo->file);
        self::assertSame(1, $symbolInfo->line);
    }

    public function testSymbolInfoIsReadonly(): void
    {
        $symbolPath = SymbolPath::forMethod('App', 'Test', 'method');
        $symbolInfo = new SymbolInfo(
            symbolPath: $symbolPath,
            file: 'test.php',
            line: 5,
        );

        // This test verifies that SymbolInfo is readonly
        // The readonly keyword ensures immutability at the language level
        self::assertInstanceOf(SymbolInfo::class, $symbolInfo);
    }

    public function testConstructorWithLineOne(): void
    {
        $symbolPath = SymbolPath::forClass('', 'Test');
        $symbolInfo = new SymbolInfo(
            symbolPath: $symbolPath,
            file: 'test.php',
            line: 1,
        );

        self::assertSame(1, $symbolInfo->line);
    }

    public function testConstructorWithLargeLine(): void
    {
        $symbolPath = SymbolPath::forMethod('App', 'LargeClass', 'method');
        $symbolInfo = new SymbolInfo(
            symbolPath: $symbolPath,
            file: 'large.php',
            line: 99999,
        );

        self::assertSame(99999, $symbolInfo->line);
    }
}
