<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\FailureClass;
use QmxFindingGate\RaiseSites;
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
     * @return iterable<string, array{list<string>, array<string, string>, list<string>, list<non-empty-string>}>
     */
    public static function provideRegistries(): iterable
    {
        yield 'every site observed' => [['a', 'b'], ['A::x' => 'a', 'B::y' => 'b'], ['A::x', 'B::y'], []];
        yield 'a second site of an observed class' => [
            ['a'],
            ['A::x#1' => 'a', 'A::x#2' => 'a'],
            ['A::x#1'],
            ['witness registry: a raised at A::x#2 has no witness'],
        ];
        yield 'a class raised nowhere' => [['a', 'b'], ['A::x' => 'a'], ['A::x'], ['witness registry: b is raised nowhere']];
    }

    /**
     * @param list<string> $classes
     * @param array<string, string> $sites
     * @param list<string> $observed
     * @param list<non-empty-string> $expectedPrefixes
     */
    #[Test]
    #[DataProvider('provideRegistries')]
    public function itNamesEverySiteWithoutAWitnessAndEveryClassWithoutAProducer(
        array $classes,
        array $sites,
        array $observed,
        array $expectedPrefixes,
    ): void {
        $problems = WitnessRegistry::problems($classes, $sites, $observed);

        self::assertCount(\count($expectedPrefixes), $problems, implode("\n", $problems));

        foreach ($expectedPrefixes as $index => $prefix) {
            self::assertStringStartsWith($prefix, $problems[$index]);
        }
    }

    #[Test]
    public function itAcceptsTheTrackedFailureClassesWhenEveryOneHasAProducer(): void
    {
        $sites = array_map(
            static fn(array $site): string => $site['class'],
            RaiseSites::of(\dirname(__DIR__), RaiseSites::DECLARED_NAMES)->sites,
        );

        self::assertSame([], WitnessRegistry::problems(FailureClass::ALL, $sites, array_keys($sites)));
    }
}
