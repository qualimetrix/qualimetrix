<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\RepositoryEntrypoints;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BenchmarkScriptEntrypointCoverageTest extends TestCase
{
    #[Test]
    public function itKeepsTrackedBenchmarkConsumersOnTheCheckCommand(): void
    {
        $projectRoot = \dirname(__DIR__, 2);
        $consumers = [
            'scripts/benchmark-comparison.sh',
            'scripts/compare-metrics.py',
        ];
        $process = new Process([
            'git',
            'grep',
            '--files-with-matches',
            '--fixed-strings',
            'bin/qmx',
            '--',
            ...$consumers,
        ], $projectRoot);
        $process->mustRun();

        $trackedConsumers = array_filter(
            explode("\n", trim($process->getOutput())),
            static fn(string $path): bool => $path !== '',
        );
        sort($trackedConsumers);
        self::assertSame($consumers, $trackedConsumers);

        foreach ($trackedConsumers as $consumer) {
            $contents = file_get_contents($projectRoot . '/' . $consumer);
            self::assertIsString($contents);
            self::assertDoesNotMatchRegularExpression('/\banalyze\b/', $contents, $consumer);
            self::assertMatchesRegularExpression('/\bcheck\b/', $contents, $consumer);
        }
    }
}
