<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\AnalysisPreflightProfile;

final class AnalysisPreflightProfileTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function provideGraphIngress(): iterable
    {
        foreach (['config', 'preset', 'exclude', 'include-generated', 'include-autoload-dev', 'no-cache', 'workers', 'memory-limit'] as $option) {
            yield $option => [$option];
        }
    }

    #[Test]
    #[DataProvider('provideGraphIngress')]
    public function itMapsOnlyDeclaredGraphIngress(string $option): void
    {
        self::assertTrue(AnalysisPreflightProfile::graph()->mapsOption($option));
    }

    #[Test]
    public function itDoesNotMapFindingOrReportingIngressForGraph(): void
    {
        $profile = AnalysisPreflightProfile::graph();

        self::assertFalse($profile->requiresFindingConfiguration);
        self::assertFalse($profile->requiresReportingFormat);
        self::assertFalse($profile->mapsOption('format'));
        self::assertFalse($profile->mapsOption('fail-on'));
        self::assertFalse($profile->mapsOption('disable-rule'));
        self::assertFalse($profile->mapsOption('cache-dir'));
    }

    #[Test]
    public function itKeepsTheAnalysisProfileOpenToItsExistingIngress(): void
    {
        $profile = AnalysisPreflightProfile::analysis();

        self::assertTrue($profile->requiresFindingConfiguration);
        self::assertTrue($profile->requiresReportingFormat);
        self::assertTrue($profile->mapsOption('format'));
        self::assertTrue($profile->mapsOption('a-future-analysis-option'));
    }
}
