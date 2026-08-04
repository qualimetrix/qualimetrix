<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Observation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Observation\AxisObservation;
use Qualimetrix\Core\Observation\ContractReference;
use Qualimetrix\Core\Observation\DebtObservation;
use Qualimetrix\Core\Observation\ObservationKind;
use Qualimetrix\Core\Observation\OccurrenceKey;
use Qualimetrix\Core\Observation\WorseDirection;

#[CoversClass(DebtObservation::class)]
#[CoversClass(ObservationKind::class)]
final class DebtObservationTest extends TestCase
{
    #[Test]
    public function itBuildsAScalarObservation(): void
    {
        $observation = DebtObservation::scalar(
            new ContractReference('complexity.cyclomatic.method'),
            new AxisObservation('ccn', 25, 10),
        );

        self::assertSame(ObservationKind::Scalar, $observation->kind);
        self::assertSame(['ccn'], $observation->axisNames());
        self::assertSame(25, $observation->axis('ccn')?->rawValue);
        self::assertNull($observation->axis('missing'));
        self::assertFalse($observation->hasOccurrenceKey());
    }

    #[Test]
    public function itRejectsAScalarWithoutAnAxis(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least 1 axis');

        new DebtObservation(new ContractReference('c'), ObservationKind::Scalar);
    }

    #[Test]
    public function itRejectsAScalarWithTwoAxes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('allows at most 1 axis');

        new DebtObservation(
            new ContractReference('c'),
            ObservationKind::Scalar,
            [new AxisObservation('a', 1), new AxisObservation('b', 2)],
        );
    }

    #[Test]
    public function itRejectsAVectorWithASingleAxis(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least 2 axis');

        DebtObservation::vector(new ContractReference('c'), [new AxisObservation('a', 1)]);
    }

    #[Test]
    public function itBuildsAVectorObservation(): void
    {
        $observation = DebtObservation::vector(new ContractReference('design.data-class'), [
            new AxisObservation('woc', 0.2, 0.33, WorseDirection::Lower),
            new AxisObservation('wmc', 12, 20),
        ]);

        self::assertSame(ObservationKind::Vector, $observation->kind);
        self::assertSame(['wmc', 'woc'], $observation->axisNames());
    }

    /**
     * Two observations built from the same measurements in different orders
     * must be indistinguishable, or a file whose bytes must be stable will
     * churn on collection order alone.
     */
    #[Test]
    public function itCanonicalizesAxisOrder(): void
    {
        $contract = new ContractReference('design.data-class');
        $axes = [new AxisObservation('woc', 0.2), new AxisObservation('wmc', 12)];

        $forward = DebtObservation::vector($contract, $axes);
        $reversed = DebtObservation::vector($contract, array_reverse($axes));

        self::assertSame($forward->axisNames(), $reversed->axisNames());
        self::assertEquals($forward->axes, $reversed->axes);
    }

    #[Test]
    public function itRejectsADuplicateAxisName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declares axis "ccn" more than once');

        DebtObservation::vector(new ContractReference('c'), [
            new AxisObservation('ccn', 1),
            new AxisObservation('ccn', 2),
        ]);
    }

    #[Test]
    public function itBuildsAPresenceObservationWithoutAxes(): void
    {
        $observation = DebtObservation::presence(new ContractReference('code-smell.eval'));

        self::assertSame([], $observation->axisNames());
        self::assertFalse($observation->hasOccurrenceKey());
    }

    /**
     * `Presence` is capped at zero axes, matching its own docblock ("no
     * magnitude"): §7.3 defines no magnitude comparison for presence
     * findings, and the channel-trait inventory has no member that needs one.
     */
    #[Test]
    public function itRejectsAPresenceObservationWithAnAxis(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('allows at most 0 axis');

        new DebtObservation(
            new ContractReference('code-smell.eval'),
            ObservationKind::Presence,
            [new AxisObservation('a', 1)],
        );
    }

    /**
     * §7.3's presence comparison never consults an occurrence key, so a
     * `Presence` observation carrying one would suggest a role the type does
     * not have. Unlike `Occurrence` and `Graph`, the parameter is not merely
     * optional — it is forbidden.
     */
    #[Test]
    public function itRejectsAPresenceObservationWithAnOccurrenceKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not carry an occurrence key');

        new DebtObservation(
            new ContractReference('code-smell.eval'),
            ObservationKind::Presence,
            [],
            OccurrenceKey::of('x'),
        );
    }

    /**
     * Null here is the channel-level statement "no stable discriminator
     * exists", which is why an occurrence observation is valid without a key:
     * its findings collapse into a counted bucket under their symbol.
     */
    #[Test]
    public function itAllowsAnOccurrenceObservationWithoutAKey(): void
    {
        $observation = DebtObservation::occurrence(new ContractReference('code-smell.boolean-argument'));

        self::assertSame(ObservationKind::Occurrence, $observation->kind);
        self::assertNull($observation->occurrenceKey);
        self::assertFalse($observation->hasOccurrenceKey());
    }

    #[Test]
    public function itCarriesAMagnitudeAlongsideAnOccurrenceIdentity(): void
    {
        $observation = DebtObservation::occurrence(
            new ContractReference('duplication.code-duplication'),
            OccurrenceKey::of('9f2c'),
            [new AxisObservation('tokens', 180, 100)],
        );

        self::assertTrue($observation->hasOccurrenceKey());
        self::assertSame('9f2c', $observation->occurrenceKey?->value);
        self::assertSame(180, $observation->axis('tokens')?->rawValue);
    }

    /**
     * A graph finding's identity spans several symbols, so it is meaningless
     * without a canonical, traversal-order independent key.
     */
    #[Test]
    public function itRejectsAGraphObservationWithoutAKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires an occurrence key');

        new DebtObservation(
            new ContractReference('architecture.circular-dependency'),
            ObservationKind::Graph,
        );
    }

    #[Test]
    public function itBuildsAGraphObservation(): void
    {
        $observation = DebtObservation::graph(
            new ContractReference('architecture.circular-dependency'),
            OccurrenceKey::fromUnorderedParts('App\\A', 'App\\B'),
            [new AxisObservation('cycle_size', 2, 2)],
        );

        self::assertSame(ObservationKind::Graph, $observation->kind);
        self::assertSame('App\\A|App\\B', $observation->occurrenceKey?->value);
    }

    /**
     * @return iterable<string, array{ObservationKind, int, ?int, bool}>
     */
    public static function provideKindInvariants(): iterable
    {
        yield 'scalar' => [ObservationKind::Scalar, 1, 1, false];
        yield 'vector' => [ObservationKind::Vector, 2, null, false];
        yield 'occurrence' => [ObservationKind::Occurrence, 0, null, false];
        yield 'presence' => [ObservationKind::Presence, 0, 0, false];
        yield 'graph' => [ObservationKind::Graph, 0, null, true];
    }

    #[Test]
    #[DataProvider('provideKindInvariants')]
    public function itDeclaresItsConstructionInvariantsPerKind(
        ObservationKind $kind,
        int $minimumAxes,
        ?int $maximumAxes,
        bool $requiresKey,
    ): void {
        self::assertSame($minimumAxes, $kind->minimumAxes());
        self::assertSame($maximumAxes, $kind->maximumAxes());
        self::assertSame($requiresKey, $kind->requiresOccurrenceKey());
    }

    /**
     * @return iterable<string, array{ObservationKind, bool}>
     */
    public static function providePermitsOccurrenceKey(): iterable
    {
        yield 'scalar' => [ObservationKind::Scalar, true];
        yield 'vector' => [ObservationKind::Vector, true];
        yield 'occurrence' => [ObservationKind::Occurrence, true];
        yield 'presence' => [ObservationKind::Presence, false];
        yield 'graph' => [ObservationKind::Graph, true];
    }

    #[Test]
    #[DataProvider('providePermitsOccurrenceKey')]
    public function itDeclaresWhetherItPermitsAnOccurrenceKey(ObservationKind $kind, bool $permits): void
    {
        self::assertSame($permits, $kind->permitsOccurrenceKey());
    }
}
