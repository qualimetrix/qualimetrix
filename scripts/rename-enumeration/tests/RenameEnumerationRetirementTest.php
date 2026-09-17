<?php

declare(strict_types=1);

namespace Qualimetrix\RenameEnumeration\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `scripts/generate-rename-enumeration.php` retires a decision only when its
 * targets are fully represented by the current measurement. These tests pin
 * fail-closed handling for missing, pre-existing, wildcard, wrong-kind, and
 * stale targets. The script is include-safe, so the predicate can be tested
 * directly.
 */
final class RenameEnumerationRetirementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 3) . '/scripts/generate-rename-enumeration.php';
    }

    /**
     * The shape that must retire: the identity is gone and every name it
     * promised is measured now and was not measured before.
     */
    #[Test]
    public function itRetiresARenameWhoseTargetsAppeared(): void
    {
        $retired = retireExecutedRows(
            ['old.name' . "\t" . 'producer' => ['new' => 'new.name', 'step' => 'rename-a']],
            [self::measured('new.name', 'producer')],
            [],
            ['producer' => ['old.name' => true]],
        );

        self::assertSame(
            [['old' => 'old.name', 'kind' => 'producer', 'new' => 'new.name', 'step' => 'rename-a']],
            $retired,
        );
    }

    /**
     * A split retires only when *every* promised name appeared.
     */
    #[Test]
    public function itRetiresASplitOnlyWhenAllThreeTargetsAppeared(): void
    {
        $decision = ['old.name' . "\t" . 'producer' => ['new' => 'a.one|a.two', 'step' => 'rename-a']];
        $previous = ['producer' => ['old.name' => true]];

        $retired = retireExecutedRows(
            $decision,
            [self::measured('a.one', 'producer'), self::measured('a.two', 'producer')],
            [],
            $previous,
        );
        self::assertCount(1, $retired);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not measured: a\.two/');

        retireExecutedRows($decision, [self::measured('a.one', 'producer')], [], $previous);
    }

    /**
     * A target that existed *before* cannot have appeared from this rename,
     * so the row is a lost identity rather than an executed one. When two
     * identities converge on one target, both rows must be merged explicitly.
     */
    #[Test]
    public function itRefusesToRetireWhenATargetExistedBeforeTheMeasurement(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not appear from this rename: survivor\.name/');

        retireExecutedRows(
            ['lost.name' . "\t" . 'producer' => ['new' => 'survivor.name', 'step' => 'rename-b']],
            [self::measured('survivor.name', 'producer')],
            [],
            ['producer' => ['lost.name' => true, 'survivor.name' => true]],
        );
    }

    #[Test]
    public function itRefusesToRetireADecisionWhoseTargetIsAWildcard(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not measured: computed\.\*/');

        retireExecutedRows(
            ['computed.health' . "\t" . 'producer' => ['new' => 'health.one|computed.*', 'step' => 'rename-b']],
            [self::measured('health.one', 'producer')],
            [],
            ['producer' => ['computed.health' => true]],
        );
    }

    /**
     * A target measured as another kind is not this row's target: a producer
     * decision is satisfied only by a producer identity.
     */
    #[Test]
    public function itRefusesToRetireWhenTheTargetIsMeasuredUnderAnotherKind(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not measured: new\.name/');

        retireExecutedRows(
            ['old.name' . "\t" . 'producer' => ['new' => 'new.name', 'step' => 'rename-a']],
            [self::measured('new.name', 'channel')],
            [],
            ['producer' => ['old.name' => true]],
        );
    }

    /**
     * History is held to its own claims in both directions, because it cannot
     * be re-derived: an identity that came back, and a promised name that
     * stopped being measured, are different mistakes and both are refused.
     */
    #[Test]
    public function itRefusesExecutedHistoryThatNoLongerDescribesTheMeasurement(): void
    {
        $executed = [['old' => 'old.name', 'kind' => 'producer', 'new' => 'new.name', 'step' => 'rename-a']];

        try {
            retireExecutedRows([], [self::measured('old.name', 'producer'), self::measured('new.name', 'producer')], $executed, []);
            self::fail('an identity recorded as renamed away came back and nothing objected');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('is measured again', $error->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is not measured any more/');

        retireExecutedRows([], [self::measured('unrelated', 'producer')], $executed, []);
    }

    /** A wildcard target in history is exempt: it was never a measurable name. */
    #[Test]
    public function itLeavesAWildcardTargetInHistoryAlone(): void
    {
        $executed = [['old' => 'computed.health', 'kind' => 'producer', 'new' => 'health.one|computed.*', 'step' => 'rename-b']];

        self::assertSame(
            $executed,
            retireExecutedRows([], [self::measured('health.one', 'producer')], $executed, []),
        );
    }

    #[Test]
    public function itRefusesAnUnknownRenameStep(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown rename batch.*unregistered-batch/');

        emitMapsForStep([], 'unregistered-batch');
    }

    /**
     * @return array{old: string, kind: string, search: string, counts: array<string, int>}
     */
    private static function measured(string $old, string $kind): array
    {
        return ['old' => $old, 'kind' => $kind, 'search' => $old, 'counts' => []];
    }
}
