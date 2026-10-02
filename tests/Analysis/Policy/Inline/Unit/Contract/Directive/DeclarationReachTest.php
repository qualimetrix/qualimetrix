<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Contract\Directive;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationReach;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(DeclarationReach::class)]
final class DeclarationReachTest extends TestCase
{
    #[Test]
    public function itCoversTheWholeDeclarationOrOnlyTheCarriedLineRange(): void
    {
        $whole = DeclarationReach::whole(40, 'class Example');
        $renamedWhole = DeclarationReach::whole(40, 'class Renamed');
        $lines = DeclarationReach::lines(12, 14, 'method Example::run');

        self::assertTrue($whole->covers(self::finding(null)));
        self::assertTrue($whole->covers(self::finding(100)));
        self::assertSame($whole->key(), $renamedWhole->key());
        self::assertSame('whole:40', $whole->key());
        self::assertSame('class Example', $whole->describe());

        self::assertTrue($lines->covers(self::finding(12)));
        self::assertTrue($lines->covers(self::finding(14)));
        self::assertFalse($lines->covers(self::finding(11)));
        self::assertFalse($lines->covers(self::finding(null)));
        self::assertSame('lines:12:14', $lines->key());
        self::assertSame('method Example::run, lines 12–14', $lines->describe());
    }

    #[Test]
    #[DataProvider('provideInvalidLineRanges')]
    public function itRejectsInvalidLineRanges(int $start, int $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A declaration line reach requires a positive, ordered range');

        DeclarationReach::lines($start, $end, 'method Example::run');
    }

    /** @return iterable<string, array{int, int}> */
    public static function provideInvalidLineRanges(): iterable
    {
        yield 'non-positive start' => [0, 2];
        yield 'reversed range' => [3, 2];
    }

    private static function finding(?int $line): Finding
    {
        $file = RelativePath::fromString('src/Example.php');
        $subject = MetricSubject::aggregate(SymbolPath::forFile($file));

        return new Finding(
            location: new Location($file, $line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: 'complexity.ccn',
            code: 'complexity.cyclomatic.callable',
            message: 'Complexity is too high',
            severity: Severity::Warning,
        );
    }
}
