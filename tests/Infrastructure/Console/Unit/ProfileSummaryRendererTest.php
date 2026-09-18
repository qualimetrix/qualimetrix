<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\ProfileSummaryRenderer;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileSummary;

#[CoversClass(ProfileSummaryRenderer::class)]
final class ProfileSummaryRendererTest extends TestCase
{
    /**
     * The whole rendering, not two substrings of it. A span statistic carries
     * five numbers and the summary prints two of them; asserting that the
     * name and the duration appear says nothing about which of the other
     * three joined them or left.
     */
    #[Test]
    public function itRendersTheDurationAndTheCountAndNoOtherStatistic(): void
    {
        $summary = new ProfileSummary(['analysis' => [
            'total' => 1500.0,
            'count' => 2,
            'avg' => 750.0,
            'memory' => 128,
            'peak_memory' => 256,
        ]]);

        self::assertSame(
            "<comment>Profile summary:</comment>\n  <info>analysis</info>: 1.500s | 2x",
            (new ProfileSummaryRenderer())->render($summary),
        );
    }

    #[Test]
    public function itSaysSoWhenThereIsNothingToRender(): void
    {
        self::assertSame(
            '<comment>No profiling data available</comment>',
            (new ProfileSummaryRenderer())->render(new ProfileSummary()),
        );
    }
}
