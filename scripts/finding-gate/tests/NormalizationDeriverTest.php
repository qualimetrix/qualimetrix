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

    #[Test]
    public function itKeepsTheNormalizationBudgetLocalToEachPublication(): void
    {
        $rules = NormalizationDeriver::derive([
            ['case:x|format:json' => '{"meta":{"timestamp":"a","version":"1"}}'],
            ['case:x|format:json' => '{"meta":{"timestamp":"b","version":"1"}}'],
        ]);
        self::assertCount(1, $rules, 'the deriver emits one row per diverging field');
        self::assertSame('meta.timestamp', $rules[0]->locator);
        self::assertSame(NormalizationRule::KIND_JSON_PATH, $rules[0]->kind);

        $many = [[], []];
        for ($index = 0; $index < 6; ++$index) {
            $many[0]['case:x|report:' . $index] = '{"clock":"a","duration":1}';
            $many[1]['case:x|report:' . $index] = '{"clock":"b","duration":2}';
        }
        self::assertCount(12, NormalizationDeriver::derive($many), 'independent metadata copies across six publications do not share a global row budget');

        $fields = array_fill_keys(array_map(static fn(int $index): string => 'clock' . $index, range(0, 10)), 1);
        $changed = array_fill_keys(array_keys($fields), 2);
        self::assertTrue(self::refused(static fn(): array => NormalizationDeriver::derive([
            ['case:x|format:json' => json_encode($fields, \JSON_THROW_ON_ERROR)],
            ['case:x|format:json' => json_encode($changed, \JSON_THROW_ON_ERROR)],
        ])), 'eleven varying fields in one published surface remain refused');

        $lines = NormalizationDeriver::derive([
            ['case:x|format:summary' => "Analysed in 1.2s\n"],
            ['case:x|format:summary' => "Analysed in 1.3s\n"],
        ]);
        self::assertCount(1, $lines, 'a text surface derives one row per diverging line');
        self::assertSame(1, preg_match($lines[0]->locator, 'Analysed in 9.9s'), 'the derived locator matches the same line with another value');
        self::assertNotSame(1, preg_match($lines[0]->locator, 'Elapsed 1.2s'), 'the derived locator is anchored on the field label, not on a substring');

        self::assertTrue(self::refused(static fn(): array => NormalizationDeriver::derive([
            ['case:x|format:json' => '{"violations":[{"a":1}]}'],
            ['case:x|format:json' => '{"violations":[{"a":1},{"a":2}]}'],
        ])), 'a structural divergence is refused rather than normalized away');
    }

    /** @param callable(): mixed $callback */
    private static function refused(callable $callback): bool
    {
        try {
            $callback();

            return false;
        } catch (GateError) {
            return true;
        }
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
