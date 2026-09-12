<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Coupling\CboOptions;

#[CoversClass(CboOptions::class)]
final class CboOptionsTest extends TestCase
{
    #[Test]
    public function itDisablesBothLevelsWhenEnabledIsFalse(): void
    {
        $options = CboOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->class->isEnabled());
        self::assertFalse($options->namespace->isEnabled());
    }

    #[Test]
    public function itFallsBackToSubDefaultsWhenEnabledIsNotSetToFalse(): void
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
        self::assertTrue(CboOptions::acceptedOptionKeys()->accepts('threshold'));
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
        $this->expectException(ConfigurationRefusal::class);
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

    /**
     * The documented equivalence "a key written with `~` means what omitting it
     * means" is about the *value*; the entry survives normalization inside
     * `rules:`, and the branch above is chosen by the key being written at all.
     * So `warning: ~` still selects the flat form and still discards the level
     * blocks beside it — the website says so, and this is what it says it about.
     */
    #[Test]
    public function itLetsATopLevelNullThresholdKeyOpenTheFlatFormAndDiscardTheLevelBlocks(): void
    {
        $options = CboOptions::fromArray([
            'warning' => null,
            'class' => ['warning' => 0, 'error' => 0],
        ]);

        self::assertSame(14, $options->class->warning);
        self::assertSame(20, $options->class->error);
        self::assertSame(
            $options->class->warning,
            CboOptions::fromArray([])->class->warning,
            'the level block is not merely overridden, it is gone: the result is the unconfigured one',
        );
    }

    #[Test]
    public function itReadsTheLevelBlocksWhenNoThresholdKeyIsWrittenAtTheTopLevelAtAll(): void
    {
        $options = CboOptions::fromArray(['class' => ['warning' => 0, 'error' => 0]]);

        self::assertSame(0, $options->class->warning);
    }
}
