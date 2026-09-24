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
     * The whole rendering, not two substrings of it: a statistic the summary
     * gains must show up here as a changed line.
     */
    #[Test]
    public function itRendersTheDurationAndTheCount(): void
    {
        $summary = new ProfileSummary(['analysis' => ['total' => 1500.0, 'count' => 2, 'unstopped' => 0]]);

        self::assertSame(
            "<comment>Profile summary:</comment>\n  <info>analysis</info>: 1.500s | 2x",
            (new ProfileSummaryRenderer())->render($summary),
        );
    }

    /** A span that never stopped itself has no time of its own, and the line says so. */
    #[Test]
    public function itNamesSpansThatWereNotTimed(): void
    {
        $summary = new ProfileSummary(['discovery' => ['total' => 0.0, 'count' => 0, 'unstopped' => 1]]);

        self::assertSame(
            "<comment>Profile summary:</comment>\n  <info>discovery</info>: 0.000s | 0x | 1 never stopped, not timed",
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
