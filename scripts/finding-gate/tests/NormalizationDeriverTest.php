<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\GateError;
use QmxFindingGate\Normalization;
use QmxFindingGate\NormalizationDeriver;
use QmxFindingGate\NormalizationRule;

final class NormalizationDeriverTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itDerivesTheWholeWarningClockAcrossMidnight(): void
    {
        $left = "[23:59:59] [WARNING] Parent chain could not be read.\n";
        $right = "[00:00:00] [WARNING] Parent chain could not be read.\n";
        $rules = self::derive('stderr:format:json', $left, $right);

        self::assertCount(1, $rules);
        self::assertSame('stderr', $rules[0]->surface);
        self::assertSame(Normalization::WARNING_TIME_PATTERN, $rules[0]->locator);
        self::assertSame(NormalizationRule::KIND_LINE_REGEX, $rules[0]->kind);

        $normalization = Normalization::fromRules($rules);
        $expected = "[<normalized>] [WARNING] Parent chain could not be read.\n";
        foreach ([$left, $right, "[12:34:56] [WARNING] Parent chain could not be read.\n"] as $content) {
            self::assertSame($expected, $normalization->normalize('stderr', $content));
        }
    }

    #[Test]
    public function itRefusesAChangedWarningMessageBesideTheClock(): void
    {
        $this->expectException(GateError::class);
        self::derive('stderr:format:json', "[23:59:59] [WARNING] Parent unreadable.\n", "[00:00:00] [WARNING] Parent read.\n");
    }

    #[Test]
    public function itRefusesAChangedDiagnosticLevelBesideTheClock(): void
    {
        $this->expectException(GateError::class);
        self::derive('stderr:format:json', "[23:59:59] [WARNING] Parent unreadable.\n", "[00:00:00] [ERROR] Parent unreadable.\n");
    }

    #[Test]
    #[DataProvider('invalidWarningClocks')]
    public function itRefusesSomethingOtherThanAWarningClock(string $other): void
    {
        $this->expectException(GateError::class);
        self::derive('stderr:format:json', "[23:59:59] [WARNING] Parent unreadable.\n", $other . " Parent unreadable.\n");
    }

    /** @return iterable<string, array{string}> */
    public static function invalidWarningClocks(): iterable
    {
        yield 'hour out of range' => ['[24:00:00] [WARNING]'];
        yield 'minute out of range' => ['[00:60:00] [WARNING]'];
        yield 'second out of range' => ['[00:00:60] [WARNING]'];
        yield 'unpadded hour' => ['[7:01:02] [WARNING]'];
        yield 'fractional seconds' => ['[01:02:03.4] [WARNING]'];
        yield 'other level' => ['[01:02:03] [INFO]'];
    }

    #[Test]
    public function itRefusesAnUnclockedStderrChange(): void
    {
        $this->expectException(GateError::class);
        self::derive('stderr:format:json', "Warning: Parent unreadable.\n", "Warning: Parent read.\n");
    }

    #[Test]
    public function itMeasuresTheOutputDestinationAndWarningClockSeparately(): void
    {
        $left = "[23:59:59] [WARNING] Parent unreadable.\nReport written to /one.json\nKept diagnostic\n";
        $right = "[00:00:00] [WARNING] Parent unreadable.\nReport written to /two.json\nKept diagnostic\n";
        $rules = self::derive('stderr:check:output', $left, $right);

        self::assertCount(2, $rules);
        self::assertSame(['stderr:check:output'], array_values(array_unique(array_column($rules, 'surface'))));
        self::assertContains(Normalization::WARNING_TIME_PATTERN, array_column($rules, 'locator'));
        $normalization = Normalization::fromRules($rules);
        $expected = "[<normalized>] [WARNING] Parent unreadable.\nReport written to <normalized>\nKept diagnostic\n";
        self::assertSame($expected, $normalization->normalize('stderr:check:output', $left));
        self::assertSame($expected, $normalization->normalize('stderr:check:output', $right));
        self::assertSame($left, $normalization->normalize('stderr', $left));
        self::assertSame("Report written to relative.json\n", $normalization->normalize('stderr:check:output', "Report written to relative.json\n"));
    }

    #[Test]
    public function itMeasuresOnlyTheClockWhenTheOutputDestinationStaysEqual(): void
    {
        $rules = self::derive(
            'stderr:check:output',
            "[23:59:59] [WARNING] Parent unreadable.\nReport written to /same.json\n",
            "[00:00:00] [WARNING] Parent unreadable.\nReport written to /same.json\n",
        );

        self::assertCount(1, $rules);
        self::assertSame(Normalization::WARNING_TIME_PATTERN, $rules[0]->locator);
    }

    #[Test]
    public function itMeasuresOnlyTheDestinationWhenTheWarningClockStaysEqual(): void
    {
        $left = "[23:59:59] [WARNING] Parent unreadable.\nReport written to /one.json\n";
        $right = "[23:59:59] [WARNING] Parent unreadable.\nReport written to /two.json\n";
        $rules = self::derive('stderr:check:output', $left, $right);

        self::assertCount(1, $rules);
        self::assertNotSame(Normalization::WARNING_TIME_PATTERN, $rules[0]->locator);
        self::assertSame(
            "[23:59:59] [WARNING] Parent unreadable.\nReport written to <normalized>\n",
            Normalization::fromRules($rules)->normalize('stderr:check:output', $left),
        );
    }

    #[Test]
    public function itRefusesAnOutputBodyChangeBesideBothMeasuredFields(): void
    {
        $this->expectException(GateError::class);
        self::derive(
            'stderr:check:output',
            "[23:59:59] [WARNING] Parent unreadable.\nReport written to /one.json\nKept diagnostic\n",
            "[00:00:00] [WARNING] Parent unreadable.\nReport written to /two.json\nChanged diagnostic\n",
        );
    }

    #[Test]
    public function itRefusesMoreThanOneOutputDestination(): void
    {
        $this->expectException(GateError::class);
        self::derive(
            'stderr:check:output',
            "Report written to /one.json\nReport written to /other.json\n",
            "Report written to /two.json\nReport written to /other.json\n",
        );
    }

    #[Test]
    public function itRefusesAnOutputCaptureWithNoDestination(): void
    {
        $this->expectException(GateError::class);
        self::derive('stderr:check:output', "[23:59:59] [WARNING] Parent unreadable.\n", "[00:00:00] [WARNING] Parent unreadable.\n");
    }

    /** @return list<NormalizationRule> */
    private static function derive(string $surface, string $left, string $right): array
    {
        return NormalizationDeriver::derive([
            ['case:warning|' . $surface => $left],
            ['case:warning|' . $surface => $right],
        ]);
    }
}
