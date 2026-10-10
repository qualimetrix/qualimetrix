<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\{FailureClass, RaiseSites, WitnessRegistry};

final class WitnessRegistryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itRequiresAProducerAndObservedClassWithoutSourceIdentity(): void
    {
        self::assertSame([], WitnessRegistry::problems(['a'], ['first' => 'a', 'second' => 'a'], ['a']));
        self::assertSame([
            'witness registry: b is raised nowhere in the gate\'s source.',
            'witness registry: b has no observed class/side/scope witness.',
        ], WitnessRegistry::problems(['a', 'b'], ['first' => 'a'], ['a']));
        self::assertSame(['witness registry: unknown failure class unknown.'], WitnessRegistry::problems(['a'], ['first' => 'a'], ['a', 'unknown']));
    }

    #[Test]
    public function itAcceptsTheTrackedFailureClassesWhenEveryOneHasAProducer(): void
    {
        $source = RaiseSites::of(\dirname(__DIR__));
        self::assertSame([], $source->problems);
        $producers = array_map(static fn(array $site): string => $site['class'], $source->sites);
        self::assertSame([], WitnessRegistry::problems(FailureClass::ALL, $producers, FailureClass::ALL));
    }
}
