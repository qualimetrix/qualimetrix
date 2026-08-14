<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The peak-RSS check is deliberately a verification command, not a PHPUnit
 * test: host memory accounting and the benchmark corpus make a runtime value
 * unsuitable for a deterministic CI assertion. This fixture pins the portable
 * command and the 2 GiB ceiling it must be measured against.
 */
final class MemoryCeilingManifestTest extends TestCase
{
    #[Test]
    public function itPinsThePortableTwoGiBBenchmarkCommand(): void
    {
        $path = \dirname(__DIR__) . '/Fixtures/BaselineV10/memory-ceiling.json';

        /**
         * @var array{
         *     package: string,
         *     version: string,
         *     lockReference: string,
         *     project: string,
         *     selectionMethod: string,
         *     analyzedFiles: int,
         *     baselineEntries: int,
         *     memoryLimitBytes: int,
         *     commandTemplate: string,
         *     generateExitStatus: int,
         *     checkExitStatus: int,
         *     observedPeakRssBytes: int,
         *     measuredDate: string
         * } $manifest
         */
        $manifest = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('laravel/framework', $manifest['package']);
        self::assertSame('benchmarks/vendor/laravel/framework/src', $manifest['project']);
        self::assertNotSame('', $manifest['version']);
        self::assertNotSame('', $manifest['lockReference']);
        self::assertSame('largest direct benchmark dependency by installed disk usage', $manifest['selectionMethod']);
        self::assertGreaterThan(0, $manifest['analyzedFiles']);
        self::assertGreaterThan(0, $manifest['baselineEntries']);
        self::assertSame(2 * 1024 * 1024 * 1024, $manifest['memoryLimitBytes']);
        self::assertStringContainsString('--baseline=<baseline>', $manifest['commandTemplate']);
        self::assertSame(0, $manifest['generateExitStatus']);
        self::assertSame(0, $manifest['checkExitStatus']);
        self::assertLessThan($manifest['memoryLimitBytes'], $manifest['observedPeakRssBytes']);
        self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}$/', $manifest['measuredDate']);

        /** @var array{packages: list<array{name: string, version: string, source?: array{reference?: string}}> } $lock */
        $lock = json_decode((string) file_get_contents(\dirname(__DIR__, 5) . '/benchmarks/composer.lock'), true, flags: \JSON_THROW_ON_ERROR);
        $package = array_find($lock['packages'], static fn(array $package): bool => $package['name'] === $manifest['package']);

        self::assertNotNull($package);
        self::assertSame($package['version'], $manifest['version']);
        self::assertSame($package['source']['reference'] ?? null, $manifest['lockReference']);
    }
}
