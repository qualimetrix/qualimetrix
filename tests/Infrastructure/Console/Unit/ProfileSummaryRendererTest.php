<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\ProfileSummaryRenderer;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileSummary;

final class ProfileSummaryRendererTest extends TestCase
{
    #[Test]
    public function itRendersTypedProfileStatistics(): void
    {
        $summary = new ProfileSummary(['analysis' => [
            'total' => 1500.0,
            'count' => 2,
            'avg' => 750.0,
            'memory' => 128,
            'peak_memory' => 256,
        ]]);

        self::assertStringContainsString('analysis', (new ProfileSummaryRenderer())->render($summary));
        self::assertStringContainsString('1.500s', (new ProfileSummaryRenderer())->render($summary));
    }
}
