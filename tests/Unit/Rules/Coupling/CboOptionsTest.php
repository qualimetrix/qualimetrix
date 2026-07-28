<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Coupling;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Rules\Coupling\CboOptions;

#[CoversClass(CboOptions::class)]
final class CboOptionsTest extends TestCase
{
    #[Test]
    public function fromArrayEnabledFalseDisablesAllLevels(): void
    {
        $options = CboOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->class->isEnabled());
        self::assertFalse($options->namespace->isEnabled());
    }

    #[Test]
    public function fromArrayWithoutEnabledFalseUsesSubDefaults(): void
    {
        $options = CboOptions::fromArray([]);

        // Empty sub-configs: class defaults enabled, namespace defaults disabled
        self::assertTrue($options->class->isEnabled());
    }

    #[Test]
    public function itAppliesTheFlatThresholdShorthandUniformlyToBothLevels(): void
    {
        $options = CboOptions::fromArray(['threshold' => 30]);

        self::assertSame(30, $options->class->warning);
        self::assertSame(30, $options->class->error);
        self::assertSame(30, $options->namespace->warning);
        self::assertSame(30, $options->namespace->error);
    }

    #[Test]
    public function itAdvertisesTheThresholdShorthandKey(): void
    {
        self::assertSame(['threshold'], CboOptions::getShorthandOptionKeys());
    }

    #[Test]
    public function itStillSupportsTheNestedClassAndNamespaceForm(): void
    {
        $options = CboOptions::fromArray([
            'class' => ['warning' => 10, 'error' => 15],
            'namespace' => ['warning' => 5, 'error' => 8],
        ]);

        self::assertSame(10, $options->class->warning);
        self::assertSame(15, $options->class->error);
        self::assertSame(5, $options->namespace->warning);
        self::assertSame(8, $options->namespace->error);
    }

    #[Test]
    public function itThrowsWhenTheFlatThresholdIsMixedWithBareWarningInTheSameConfigArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot mix "threshold" with "warning"/"error"');

        CboOptions::fromArray(['threshold' => 30, 'warning' => 10]);
    }

    #[Test]
    public function itLetsTheFlatThresholdWinOverAPreExistingNestedClassAndNamespaceConfigInTheSameArray(): void
    {
        // Deliberate precedence choice (mirrors ComplexityOptions/
        // CognitiveComplexityOptions/NpathComplexityOptions's own top-level
        // legacy-flat branch): when a single merged config array carries
        // BOTH a bare top-level `threshold` and nested `class:`/`namespace:`
        // sub-configs — regardless of which configuration layer contributed
        // which key, information fromArray() cannot recover — the flat
        // shorthand takes full precedence.
        $options = CboOptions::fromArray([
            'threshold' => 30,
            'class' => ['warning' => 10, 'error' => 15],
            'namespace' => ['warning' => 10, 'error' => 15],
        ]);

        self::assertSame(30, $options->class->warning);
        self::assertSame(30, $options->class->error);
        self::assertSame(30, $options->namespace->warning);
        self::assertSame(30, $options->namespace->error);
    }

    #[Test]
    public function itStillHonorsTheTopLevelScopeAlongsideTheFlatThreshold(): void
    {
        $options = CboOptions::fromArray(['threshold' => 30, 'scope' => 'application']);

        self::assertSame('application', $options->class->scope);
    }
}
