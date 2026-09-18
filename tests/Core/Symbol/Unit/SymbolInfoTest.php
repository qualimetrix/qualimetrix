<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use Error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(SymbolInfo::class)]
final class SymbolInfoTest extends TestCase
{
    #[Test]
    public function itKeepsTheExactSubjectItWasConstructedFrom(): void
    {
        $subject = MetricSubject::aggregate(SymbolPath::forNamespace('App\Domain'));

        $symbolInfo = new SymbolInfo(
            symbolPath: $subject,
            file: RelativePath::fromString('src/Domain/User.php'),
            line: 10,
        );

        self::assertSame($subject, $symbolInfo->subject);
        self::assertEquals($subject->toSymbolPath(), $symbolInfo->symbolPath);
    }

    #[Test]
    public function itCarriesNoSubjectWhenConstructedFromALogicalPath(): void
    {
        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');

        $symbolInfo = new SymbolInfo(
            symbolPath: $symbolPath,
            file: RelativePath::fromString('src/Service/UserService.php'),
            line: 42,
        );

        self::assertNull($symbolInfo->subject);
        self::assertSame($symbolPath, $symbolInfo->symbolPath);
    }

    #[Test]
    public function itRefusesAWriteToAConstructedSymbolInfo(): void
    {
        $symbolInfo = new SymbolInfo(
            symbolPath: SymbolPath::forMethod('App', 'Test', 'method'),
            file: RelativePath::fromString('test.php'),
            line: 5,
        );

        self::expectException(Error::class);
        self::expectExceptionMessage('Cannot modify readonly property');

        // @phpstan-ignore assign.propertyProtectedSet
        $symbolInfo->line = 6;
    }
}
