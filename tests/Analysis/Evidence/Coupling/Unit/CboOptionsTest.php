<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Coupling\CboOptions;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(CboOptions::class)]
final class CboOptionsTest extends TestCase
{
    #[Test]
    public function itDisablesBothLevelsWhenEnabledIsFalse(): void
    {
        $options = CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, ['enabled' => false]));

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->class->isEnabled());
        self::assertFalse($options->namespace->isEnabled());
    }

    #[Test]
    public function itFallsBackToSubDefaultsWhenEnabledIsNotSetToFalse(): void
    {
        $options = CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, []));

        // Empty sub-configs: class defaults enabled, namespace defaults disabled
        self::assertTrue($options->class->isEnabled());
    }

    #[Test]
    public function itAppliesTheFlatThresholdShorthandUniformlyToBothLevels(): void
    {
        $options = CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, ['threshold' => 30]));

        self::assertSame(30, $options->class->warning);
        self::assertSame(30, $options->class->error);
        self::assertSame(30, $options->namespace->warning);
        self::assertSame(30, $options->namespace->error);
    }

    #[Test]
    public function itAdvertisesTheThresholdShorthandKey(): void
    {
        self::assertTrue(CboOptions::acceptedOptionKeys()->accepts('threshold'));
    }

    #[Test]
    public function itStillSupportsTheNestedClassAndNamespaceForm(): void
    {
        $options = CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, [
            'class' => ['warning' => 10, 'error' => 15],
            'namespace' => ['warning' => 5, 'error' => 8],
        ]));

        self::assertSame(10, $options->class->warning);
        self::assertSame(15, $options->class->error);
        self::assertSame(5, $options->namespace->warning);
        self::assertSame(8, $options->namespace->error);
    }

    #[Test]
    public function itThrowsWhenTheFlatThresholdIsMixedWithBareWarningInTheSameConfigArray(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('"rules.fixture" in configuration file "/project/qmx.yaml" writes both "warning" and "threshold" in one layer; "warning" is shorthand for "class.warning" and "namespace.warning" — write either the shorthand or the full keys in one layer.');

        CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, ['threshold' => 30, 'warning' => 10]));
    }

    #[Test]
    public function itRefusesTheFlatThresholdOverlappingNestedClassAndNamespaceBandsInOneLayer(): void
    {
        foreach ([
            ['threshold' => 30, 'class' => ['warning' => 10, 'error' => 15]],
            ['threshold' => 30, 'namespace' => ['warning' => 10, 'error' => 15]],
        ] as $index => $input) {
            try {
                CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, $input));
                self::fail('A shorthand overlapped its authored leaf.');
            } catch (ConfigurationRefusal $error) {
                self::assertSame([
                    '"rules.fixture" in configuration file "/project/qmx.yaml" writes both "threshold" and "class.warning" in one layer; "threshold" is shorthand for "class.threshold" and "namespace.threshold" — write either the shorthand or the full keys in one layer.',
                    '"rules.fixture" in configuration file "/project/qmx.yaml" writes both "threshold" and "namespace.warning" in one layer; "threshold" is shorthand for "class.threshold" and "namespace.threshold" — write either the shorthand or the full keys in one layer.',
                ][$index], $error->getMessage());
            }
        }
    }

    #[Test]
    public function itStillHonorsTheTopLevelScopeAlongsideTheFlatThreshold(): void
    {
        $options = CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, ['threshold' => 30, 'scope' => 'application']));

        self::assertSame('application', $options->class->scope);
    }

    /**
     * The documented equivalence "a key written with `~` means what omitting it
     * means" holds for the branch as well as for the value: `warning: ~` is no
     * flat threshold, so it selects no flat form and the level block beside it
     * is read exactly as if the key had not been written at all.
     *
     * This used to discard the block, silently and with exit 0 — the widest
     * reach of that being `--rule-opt 'coupling.cbo:warning='`, which hands
     * this array a null through the CLI door.
     */
    #[Test]
    public function itLeavesTheLevelBlocksAloneWhenTheTopLevelThresholdKeyIsWrittenNull(): void
    {
        $options = CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, [
            'warning' => null,
            'class' => ['warning' => 0, 'error' => 0],
        ]));

        self::assertSame(0, $options->class->warning);
        self::assertSame(0, $options->class->error);
        self::assertEquals(
            CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, ['class' => ['warning' => 0, 'error' => 0]])),
            $options,
            'the `~` beside the block is worth exactly what leaving it out is worth',
        );
    }

    #[Test]
    public function itReadsTheLevelBlocksWhenNoThresholdKeyIsWrittenAtTheTopLevelAtAll(): void
    {
        $options = CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, ['class' => ['warning' => 0, 'error' => 0]]));

        self::assertSame(0, $options->class->warning);
    }

    /**
     * Regression: the mixing guard used to fire on key presence, so a
     * `threshold: ~` that wrote no value at all was reported as a second mode
     * clashing with `warning:` — a refusal naming a mix with nothing.
     */
    #[Test]
    public function itDoesNotCallItAMixWhenTheThresholdKeyBesideWarningIsWrittenNull(): void
    {
        $options = CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, ['threshold' => null, 'warning' => 5]));

        self::assertSame(5, $options->class->warning);
        self::assertEquals(CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, ['warning' => 5])), $options);
    }

    #[Test]
    public function itStillCallsItAMixWhenBothModesCarryAValue(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('"rules.fixture" in configuration file "/project/qmx.yaml" writes both "warning" and "threshold" in one layer; "warning" is shorthand for "class.warning" and "namespace.warning" — write either the shorthand or the full keys in one layer.');

        CboOptions::fromResolved(ResolvedOptionsFixture::values(CboOptions::class, ['threshold' => 30, 'warning' => 5]));
    }
}
