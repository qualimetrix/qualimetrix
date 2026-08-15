<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(LogicalClassPath::class)]
final class LogicalClassPathTest extends TestCase
{
    #[Test]
    public function itWrapsOnlyAClassSymbol(): void
    {
        $path = new LogicalClassPath(SymbolPath::forClass('App', 'Service'));

        self::assertSame('class:App\\Service', $path->toCanonical());
    }

    #[Test]
    public function itRejectsACallableSymbol(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LogicalClassPath(SymbolPath::forMethod('App', 'Service', 'handle'));
    }
}
