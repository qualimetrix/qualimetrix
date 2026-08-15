<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\InstabilityOptions;

#[CoversClass(InstabilityOptions::class)]
final class InstabilityOptionsTest extends TestCase
{
    #[Test]
    public function fromArrayEnabledFalseDisablesAllLevels(): void
    {
        $options = InstabilityOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->class->isEnabled());
        self::assertFalse($options->namespace->isEnabled());
    }

    #[Test]
    public function itAppliesTheFlatThresholdShorthandUniformlyToBothLevels(): void
    {
        $options = InstabilityOptions::fromArray(['threshold' => 0.5]);

        self::assertSame(0.5, $options->class->maxWarning);
        self::assertSame(0.5, $options->class->maxError);
        self::assertSame(0.5, $options->namespace->maxWarning);
        self::assertSame(0.5, $options->namespace->maxError);
    }

    #[Test]
    public function itAdvertisesTheThresholdShorthandKey(): void
    {
        self::assertSame(['threshold'], InstabilityOptions::getShorthandOptionKeys());
    }

    #[Test]
    public function itStillSupportsTheNestedClassAndNamespaceForm(): void
    {
        $options = InstabilityOptions::fromArray([
            'class' => ['max_warning' => 0.6, 'max_error' => 0.8],
            'namespace' => ['max_warning' => 0.7, 'max_error' => 0.9],
        ]);

        self::assertSame(0.6, $options->class->maxWarning);
        self::assertSame(0.8, $options->class->maxError);
        self::assertSame(0.7, $options->namespace->maxWarning);
        self::assertSame(0.9, $options->namespace->maxError);
    }

    #[Test]
    public function itThrowsWhenTheFlatThresholdIsMixedWithBareMaxWarningInTheSameConfigArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot mix "threshold" with "max_warning"/"max_error"');

        InstabilityOptions::fromArray(['threshold' => 0.5, 'max_warning' => 0.6]);
    }

    #[Test]
    public function itLetsTheFlatThresholdWinOverAPreExistingNestedClassAndNamespaceConfigInTheSameArray(): void
    {
        // Same deliberate precedence choice as CboOptions — see its test of
        // the same name for the rationale.
        $options = InstabilityOptions::fromArray([
            'threshold' => 0.5,
            'class' => ['max_warning' => 0.6, 'max_error' => 0.8],
            'namespace' => ['max_warning' => 0.7, 'max_error' => 0.9],
        ]);

        self::assertSame(0.5, $options->class->maxWarning);
        self::assertSame(0.5, $options->class->maxError);
        self::assertSame(0.5, $options->namespace->maxWarning);
        self::assertSame(0.5, $options->namespace->maxError);
    }
}
