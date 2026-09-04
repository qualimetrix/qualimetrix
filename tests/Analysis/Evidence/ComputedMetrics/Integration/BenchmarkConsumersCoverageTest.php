<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class BenchmarkConsumersCoverageTest extends TestCase
{
    /** @var list<string> */
    private array $fixtureRoots = [];

    #[Test]
    public function itKeepsTrackedBenchmarkConsumersOnTheCheckCommand(): void
    {
        $projectRoot = \dirname(__DIR__, 5);
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

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        foreach ($this->fixtureRoots as $fixtureRoot) {
            $filesystem->remove($fixtureRoot);
        }
    }

    #[TestWith(['partial'])]
    #[TestWith(['missing'])]
    #[TestWith(['malformed'])]
    #[Test]
    public function itDoesNotUpdateBaselinesFromANonAuthoritativeArtifact(string $coverageState): void
    {
        $fixtureRoot = $this->createFixtureRoot();
        $this->copyScript('benchmark-regression.php', $fixtureRoot);
        mkdir($fixtureRoot . '/fixtures/project', recursive: true);

        $baselinePath = $fixtureRoot . '/docs/internal/benchmark-baselines.json';
        mkdir(\dirname($baselinePath), recursive: true);
        $originalBaseline = json_encode([
            'updated_at' => '2000-01-01',
            'projects' => [
                'fixture' => [
                    'path' => 'fixtures/project',
                    'expectations' => ['health.overall' => [40, 60]],
                ],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($baselinePath, $originalBaseline);
        $this->writeFakeQmx($fixtureRoot, $this->metricsArtifact($coverageState));

        $process = new Process([\PHP_BINARY, 'scripts/benchmark-regression.php', '--update-baselines'], $fixtureRoot);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('analysis coverage is not complete', $process->getErrorOutput());
        self::assertSame($originalBaseline, file_get_contents($baselinePath));
    }

    #[Test]
    public function itDoesNotWriteCollectedDataWhenAnyProjectIsIncomplete(): void
    {
        $fixtureRoot = $this->createFixtureRoot();
        $this->copyScript('collect-benchmark-data.php', $fixtureRoot);
        $this->createCollectorProjectDirectories($fixtureRoot);
        $this->writeFakeQmx($fixtureRoot, $this->metricsArtifact('partial'));

        $outputPath = $fixtureRoot . '/benchmark-output.json';
        $sentinel = "existing authoritative artifact\n";
        file_put_contents($outputPath, $sentinel);

        $process = new Process([\PHP_BINARY, 'scripts/collect-benchmark-data.php', $outputPath], $fixtureRoot);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Collection aborted:', $process->getErrorOutput());
        self::assertSame($sentinel, file_get_contents($outputPath));
    }

    #[Test]
    public function itDoesNotPartiallyRatchetWhenAConfiguredProjectIsSkipped(): void
    {
        $fixtureRoot = $this->createFixtureRoot();
        $this->copyScript('benchmark-regression.php', $fixtureRoot);
        mkdir($fixtureRoot . '/fixtures/available', recursive: true);

        $baselinePath = $fixtureRoot . '/docs/internal/benchmark-baselines.json';
        mkdir(\dirname($baselinePath), recursive: true);
        $originalBaseline = json_encode([
            'updated_at' => '2000-01-01',
            'projects' => [
                'available' => [
                    'path' => 'fixtures/available',
                    'expectations' => ['health.overall' => [40, 60]],
                ],
                'missing' => [
                    'path' => 'fixtures/missing',
                    'expectations' => ['health.overall' => [40, 60]],
                ],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($baselinePath, $originalBaseline);
        $this->writeFakeQmx($fixtureRoot, $this->metricsArtifact('complete'));

        $process = new Process([\PHP_BINARY, 'scripts/benchmark-regression.php', '--update-baselines'], $fixtureRoot);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('missing: benchmark path not found', $process->getErrorOutput());
        self::assertSame($originalBaseline, file_get_contents($baselinePath));
    }

    private function createFixtureRoot(): string
    {
        $fixtureRoot = sys_get_temp_dir() . '/qmx_benchmark_consumer_' . bin2hex(random_bytes(6));
        if (!mkdir($fixtureRoot . '/scripts', recursive: true)) {
            throw new RuntimeException('Failed to create benchmark consumer fixture');
        }
        $this->fixtureRoots[] = $fixtureRoot;

        return $fixtureRoot;
    }

    private function copyScript(string $script, string $fixtureRoot): void
    {
        $source = \dirname(__DIR__, 5) . '/scripts/' . $script;
        if (!copy($source, $fixtureRoot . '/scripts/' . $script)) {
            throw new RuntimeException('Failed to copy benchmark script');
        }
    }

    private function writeFakeQmx(string $fixtureRoot, string $artifact): void
    {
        if (!mkdir($fixtureRoot . '/bin', recursive: true)) {
            throw new RuntimeException('Failed to create fake qmx directory');
        }

        $script = <<<'PHP'
#!/usr/bin/env php
<?php
if (in_array('--version', $argv, true)) {
    echo "Qualimetrix fixture\n";
    exit(0);
}
echo <<<'JSON'
ARTIFACT
JSON;
PHP;
        $script = str_replace('ARTIFACT', $artifact, $script);
        $qmxPath = $fixtureRoot . '/bin/qmx';
        file_put_contents($qmxPath, $script);
        chmod($qmxPath, 0755);
    }

    private function metricsArtifact(string $coverageState): string
    {
        $artifact = [
            'symbols' => [
                [
                    'type' => 'project',
                    'name' => 'project',
                    'metrics' => ['health.overall' => 50],
                ],
            ],
        ];
        $artifact['coverage'] = match ($coverageState) {
            'complete' => ['complete' => true, 'discovered' => 1, 'analyzed' => 1, 'failed' => 0],
            'partial' => ['complete' => false, 'discovered' => 2, 'analyzed' => 1, 'failed' => 1],
            'missing' => null,
            'malformed' => ['complete' => 'true'],
            default => throw new RuntimeException('Unknown coverage fixture state'),
        };
        if ($coverageState === 'missing') {
            unset($artifact['coverage']);
        }

        return json_encode($artifact, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    private function createCollectorProjectDirectories(string $fixtureRoot): void
    {
        $paths = [
            'benchmarks/vendor/symfony/console',
            'benchmarks/vendor/symfony/dependency-injection',
            'benchmarks/vendor/symfony/http-foundation',
            'benchmarks/vendor/symfony/http-kernel',
            'benchmarks/vendor/symfony/routing',
            'benchmarks/vendor/phpunit/phpunit/src',
            'benchmarks/vendor/nikic/php-parser/lib',
            'benchmarks/vendor/doctrine/orm/src',
            'benchmarks/vendor/doctrine/dbal/src',
            'benchmarks/vendor/league/flysystem/src',
            'benchmarks/vendor/composer/composer/src',
            'benchmarks/vendor/monolog/monolog/src',
            'benchmarks/vendor/guzzlehttp/guzzle/src',
            'benchmarks/vendor/laravel/framework/src',
            'src',
        ];
        foreach ($paths as $path) {
            mkdir($fixtureRoot . '/' . $path, recursive: true);
        }
    }
}
