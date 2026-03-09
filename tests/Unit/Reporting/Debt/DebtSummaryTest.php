<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Debt;

use AiMessDetector\Reporting\Debt\DebtSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DebtSummary::class)]
final class DebtSummaryTest extends TestCase
{
    public function testConstructorAndProperties(): void
    {
        $summary = new DebtSummary(
            totalMinutes: 120,
            perFile: ['src/Foo.php' => 60, 'src/Bar.php' => 60],
            perRule: ['complexity.cyclomatic' => 120],
        );

        self::assertSame(120, $summary->totalMinutes);
        self::assertSame(['src/Foo.php' => 60, 'src/Bar.php' => 60], $summary->perFile);
        self::assertSame(['complexity.cyclomatic' => 120], $summary->perRule);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function formatMinutesProvider(): iterable
    {
        yield '0 minutes' => [0, '0min'];
        yield 'negative' => [-5, '0min'];
        yield '45 minutes' => [45, '45min'];
        yield '1 hour' => [60, '1h'];
        yield '1h 30min' => [90, '1h 30min'];
        yield '2 hours' => [120, '2h'];
        yield '7h 59min (just below 1 day)' => [479, '7h 59min'];
        yield '8 hours = 1 day' => [480, '1d'];
        yield '1d 2h 15min' => [615, '1d 2h 15min'];
        yield '2d' => [960, '2d'];
        yield '2d 4h' => [1200, '2d 4h'];
        yield '3d 7h 59min' => [1919, '3d 7h 59min'];
    }

    #[DataProvider('formatMinutesProvider')]
    public function testFormatMinutesStatic(int $minutes, string $expected): void
    {
        self::assertSame($expected, DebtSummary::formatMinutes($minutes));
    }

    public function testFormatTotalDelegatesToFormatMinutes(): void
    {
        $summary = new DebtSummary(90, [], []);

        self::assertSame('1h 30min', $summary->formatTotal());
    }

    public function testFormatTotalZero(): void
    {
        $summary = new DebtSummary(0, [], []);

        self::assertSame('0min', $summary->formatTotal());
    }
}
