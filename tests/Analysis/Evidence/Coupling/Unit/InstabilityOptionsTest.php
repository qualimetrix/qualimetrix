<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Coupling\InstabilityOptions;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(InstabilityOptions::class)]
final class InstabilityOptionsTest extends TestCase
{
    #[Test]
    public function itDisablesAllLevelsWhenTheTopLevelEnabledFlagIsFalse(): void
    {
        $options = InstabilityOptions::fromResolved(ResolvedOptionsFixture::values(InstabilityOptions::class, ['enabled' => false]));

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->class->isEnabled());
        self::assertFalse($options->namespace->isEnabled());
    }

    #[Test]
    public function itAppliesTheFlatThresholdShorthandUniformlyToBothLevels(): void
    {
        $options = InstabilityOptions::fromResolved(ResolvedOptionsFixture::values(InstabilityOptions::class, ['threshold' => 0.5]));

        self::assertSame(0.5, $options->class->maxWarning);
        self::assertSame(0.5, $options->class->maxError);
        self::assertSame(0.5, $options->namespace->maxWarning);
        self::assertSame(0.5, $options->namespace->maxError);
    }

    #[Test]
    public function itAdvertisesTheThresholdShorthandKey(): void
    {
        self::assertTrue(InstabilityOptions::acceptedOptionKeys()->accepts('threshold'));
    }

    #[Test]
    public function itStillSupportsTheNestedClassAndNamespaceForm(): void
    {
        $options = InstabilityOptions::fromResolved(ResolvedOptionsFixture::values(InstabilityOptions::class, [
            'class' => ['max_warning' => 0.6, 'max_error' => 0.8],
            'namespace' => ['max_warning' => 0.7, 'max_error' => 0.9],
        ]));

        self::assertSame(0.6, $options->class->maxWarning);
        self::assertSame(0.8, $options->class->maxError);
        self::assertSame(0.7, $options->namespace->maxWarning);
        self::assertSame(0.9, $options->namespace->maxError);
    }

    #[Test]
    public function itThrowsWhenTheFlatThresholdIsMixedWithBareMaxWarningInTheSameConfigArray(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('"rules.fixture" in configuration file "/project/qmx.yaml" writes both "max_warning" and "threshold" in one layer; "max-warning" is shorthand for "class.max-warning" and "namespace.max-warning" — write either the shorthand or the full keys in one layer.');

        InstabilityOptions::fromResolved(ResolvedOptionsFixture::values(InstabilityOptions::class, ['threshold' => 0.5, 'max_warning' => 0.6]));
    }

    #[Test]
    public function itRefusesTheFlatThresholdOverlappingNestedBands(): void
    {
        self::expectException(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.fixture" in configuration file "/project/qmx.yaml" writes both "threshold" and "class.max_warning" in one layer; "threshold" is shorthand for "class.threshold" and "namespace.threshold" — write either the shorthand or the full keys in one layer.');
        InstabilityOptions::fromResolved(ResolvedOptionsFixture::values(InstabilityOptions::class, [
            'threshold' => 0.5,
            'class' => ['max_warning' => 0.6, 'max_error' => 0.8],
            'namespace' => ['max_warning' => 0.7, 'max_error' => 0.9],
        ]));
    }

    #[Test]
    public function itReadsTheLevelBlocksWhenTheFlatThresholdBesideThemIsWrittenNull(): void
    {
        $blocks = [
            'class' => ['max_warning' => 0.6, 'max_error' => 0.8],
            'namespace' => ['max_warning' => 0.7, 'max_error' => 0.9],
        ];

        $options = InstabilityOptions::fromResolved(ResolvedOptionsFixture::values(InstabilityOptions::class, ['threshold' => null] + $blocks));

        self::assertSame(0.6, $options->class->maxWarning);
        self::assertSame(0.7, $options->namespace->maxWarning);
        self::assertEquals(
            InstabilityOptions::fromResolved(ResolvedOptionsFixture::values(InstabilityOptions::class, $blocks)),
            $options,
            'the `~` beside the blocks is worth exactly what leaving it out is worth',
        );
    }

    /**
     * Regression: the mixing guard used to fire on key presence, so a
     * `max_warning: ~` that wrote no value at all was reported as a second
     * mode clashing with the first.
     */
    #[Test]
    public function itDoesNotCallItAMixWhenTheGraduatedKeyBesideTheFlatThresholdIsWrittenNull(): void
    {
        $options = InstabilityOptions::fromResolved(ResolvedOptionsFixture::values(InstabilityOptions::class, ['threshold' => 0.5, 'max_warning' => null]));

        self::assertSame(0.5, $options->class->maxWarning);
        self::assertSame(0.5, $options->class->maxError);
    }
}
