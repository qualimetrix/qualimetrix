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
     * @return iterable<string, array{list<string>, array<string, string>, list<string>, list<non-empty-string>, list<string>|null, list<string>}>
     */
    public static function provideRegistries(): iterable
    {
        require_once \dirname(__DIR__) . '/classes.php';

        yield 'every site observed' => [['a', 'b'], ['A::x' => 'a', 'B::y' => 'b'], ['A::x', 'B::y'], [], null, []];
        yield 'a second site of an observed class' => [
            ['a'],
            ['A::x#1' => 'a', 'A::x#2' => 'a'],
            ['A::x#1'],
            ['witness registry: a raised at A::x#2 has no witness'],
            null,
            [],
        ];
        yield 'a class raised nowhere' => [['a', 'b'], ['A::x' => 'a'], ['A::x'], ['witness registry: b is raised nowhere'], null, []];
        yield 'scope-only run-failed keeps other classes exact' => [
            [FailureClass::RUN_FAILED, 'a'],
            ['A::x' => FailureClass::RUN_FAILED, 'B::y' => FailureClass::RUN_FAILED, 'C::z' => 'a'],
            ['A::x'],
            ['witness registry: a raised at C::z has no witness'],
            [FailureClass::RUN_FAILED],
            [],
        ];
        yield 'narrowed class needs a real scoped observation' => [
            [FailureClass::RUN_FAILED],
            ['A::x' => FailureClass::RUN_FAILED],
            ['A::x'],
            ['witness registry: run-failed has no observed class/side/scope witness'],
            [],
            [],
        ];
        yield 'retired helper keeps vocabulary without native site credit' => [
            [FailureClass::FINGERPRINT_MISMATCH, 'a'],
            ['FingerprintCheck::x' => FailureClass::FINGERPRINT_MISMATCH, 'A::y' => 'a'],
            ['A::y'],
            [],
            null,
            [FailureClass::FINGERPRINT_MISMATCH],
        ];
        yield 'retired helper identity cannot be credited' => [
            [FailureClass::FINGERPRINT_OPAQUE],
            ['FingerprintCheck::x' => FailureClass::FINGERPRINT_OPAQUE],
            ['FingerprintCheck::x'],
            ['witness registry: retired native class fingerprint-opaque claims exact site FingerprintCheck::x'],
            null,
            [FailureClass::FINGERPRINT_OPAQUE],
        ];
        yield 'retirement does not exempt another active class' => [
            [FailureClass::FINGERPRINT_MISMATCH, 'a'],
            ['FingerprintCheck::x' => FailureClass::FINGERPRINT_MISMATCH, 'A::y' => 'a'],
            [],
            ['witness registry: a raised at A::y has no witness'],
            null,
            [FailureClass::FINGERPRINT_MISMATCH],
        ];
    }

    /**
     * @param list<string> $classes
     * @param array<string, string> $sites
     * @param list<string> $observed
     * @param list<non-empty-string> $expectedPrefixes
     * @param list<string>|null $scoped
     * @param list<string> $retired
     */
    #[Test]
    #[DataProvider('provideRegistries')]
    public function itNamesEverySiteWithoutAWitnessAndEveryClassWithoutAProducer(
        array $classes,
        array $sites,
        array $observed,
        array $expectedPrefixes,
        ?array $scoped,
        array $retired,
    ): void {
        $problems = WitnessRegistry::problems($classes, $sites, $observed, $scoped, $retired);

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

        $retired = array_keys(FailureClass::NATIVE_WITNESS_RETIREMENTS);
        $observed = array_values(array_diff(array_keys($sites), array_keys(array_filter(
            $sites,
            static fn(string $class): bool => \in_array($class, $retired, true),
        ))));

        self::assertSame([], WitnessRegistry::problems(FailureClass::ALL, $sites, $observed, null, $retired));
    }
}
