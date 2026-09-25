<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\FailureClass;
use QmxFindingGate\WitnessRegistry;

/**
 * The registry's arithmetic on inputs whose answer is known. Whether the real
 * classes are witnessed is the self-test's question, answered by whole runs.
 */
final class WitnessRegistryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, list<string>, array<string, array{0: string, 1: string}>, list<non-empty-string>}>
     */
    public static function provideRegistries(): iterable
    {
        yield 'every class witnessed once' => [['a', 'b'], ['a'], ['b'], [], []];
        yield 'a class nobody witnesses' => [['a', 'b'], ['a'], [], [], ['witness registry: b has no witness']];
        yield 'a pending class' => [['a', 'b'], ['a'], [], ['b' => ['pending: S01b/P5', 'because']], []];
        yield 'a pending class that is witnessed' => [
            ['a'],
            [],
            ['a'],
            ['a' => ['pending: S01b/P5', 'because']],
            ['witness registry: a is witnessed and still stands'],
        ];
        yield 'a pending row naming no S01b package' => [
            ['a'],
            [],
            [],
            ['a' => ['pending: S02', 'because']],
            ['witness registry: a is pending as "pending: S02"'],
        ];
        yield 'a pending row without a reason' => [
            ['a'],
            [],
            [],
            ['a' => ['pending: S01b/P5', ' ']],
            ['witness registry: a is pending as "pending: S01b/P5"'],
        ];
        yield 'a pending row naming no class' => [
            ['a'],
            ['a'],
            [],
            ['z' => ['pending: S01b/P5', 'because']],
            ['witness registry: the pending row names "z"'],
        ];
    }

    /**
     * @param list<string> $classes
     * @param list<string> $observed
     * @param list<string> $required
     * @param array<string, array{0: string, 1: string}> $pending
     * @param list<non-empty-string> $expectedPrefixes
     */
    #[Test]
    #[DataProvider('provideRegistries')]
    public function itNamesEveryClassWithoutAWitnessAndEveryStalePendingRow(
        array $classes,
        array $observed,
        array $required,
        array $pending,
        array $expectedPrefixes,
    ): void {
        $problems = WitnessRegistry::problems($classes, $observed, $required, $pending);

        self::assertCount(\count($expectedPrefixes), $problems, implode("\n", $problems));

        foreach ($expectedPrefixes as $index => $prefix) {
            self::assertStringStartsWith($prefix, $problems[$index]);
        }
    }

    #[Test]
    public function itAcceptsTheTrackedPendingRowsWhileTheirClassesAreUnwitnessed(): void
    {
        $witnessed = array_values(array_diff(FailureClass::ALL, array_keys(WitnessRegistry::PENDING)));

        self::assertSame([], WitnessRegistry::problems(FailureClass::ALL, $witnessed, [], WitnessRegistry::PENDING));
    }
}
