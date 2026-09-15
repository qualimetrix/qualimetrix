<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The published coverage is the run's own `.count`, not a number of its own.
 *
 * Two outputs of one analysis are compared: the health section of `--format=json`
 * against the project metric bag of `--format=metrics`. A coverage that
 * disappears, that stops naming its basis, or that drifts from the count the
 * same run published, fails here — which is the only way to tell "computed over
 * a third of the classes" from a plausible-looking number.
 */
final class HealthCoverageAgreesWithCountsTest extends TestCase
{
    private string $fixtureDirectory;

    protected function setUp(): void
    {
        $this->fixtureDirectory = sys_get_temp_dir() . '/qmx_health_coverage_' . bin2hex(random_bytes(6));

        if (!mkdir($this->fixtureDirectory) && !is_dir($this->fixtureDirectory)) {
            throw new RuntimeException('Failed to create the coverage fixture directory');
        }

        // Cohesion is undefined below two methods, so the one-method classes
        // are measured by LCOM and CBO and skipped by TCC. That gap is what
        // the coverage number has to report.
        $written = file_put_contents($this->fixtureDirectory . '/Fixture.php', <<<'PHP'
            <?php

            namespace CoverageFixture;

            class Paired
            {
                private int $shared = 0;

                public function first(): int
                {
                    return $this->shared;
                }

                public function second(): int
                {
                    return $this->shared + 1;
                }
            }

            class Lonely
            {
                public function only(): int
                {
                    return 1;
                }
            }

            class AlsoLonely
            {
                public function only(): int
                {
                    return 2;
                }
            }
            PHP);

        if ($written === false) {
            throw new RuntimeException('Failed to write the coverage fixture');
        }
    }

    protected function tearDown(): void
    {
        $file = $this->fixtureDirectory . '/Fixture.php';

        if (is_file($file)) {
            unlink($file);
        }

        if (is_dir($this->fixtureDirectory)) {
            rmdir($this->fixtureDirectory);
        }
    }

    #[Test]
    public function itPublishesTheCountTheSameRunMeasured(): void
    {
        $health = $this->analyze('json')['health'] ?? null;
        self::assertIsArray($health, 'The run published no health section');

        $projectMetrics = $this->projectMetrics($this->analyze('metrics'));
        $measuredDimensions = 0;

        foreach ($health as $dimension => $score) {
            self::assertArrayHasKey('coverage', $score, "$dimension publishes no coverage");
            $coverage = $score['coverage'];
            self::assertArrayHasKey('state', $coverage, "$dimension publishes no coverage state");

            if ($coverage['state'] !== 'measured') {
                self::assertNull($coverage['measured'], "$dimension reports a number it calls inapplicable");
                self::assertNotNull($coverage['reason'], "$dimension is silent about why it has no coverage");
                continue;
            }

            $measuredDimensions++;
            $basis = $coverage['basis'];

            // An aggregate that never reached the project publishes no key and
            // is a measured zero, exactly as the score's own `?? 0` read it —
            // so a missing key is only wrong when a number was reported for it.
            if ($coverage['measured'] > 0) {
                self::assertArrayHasKey($basis, $projectMetrics, "$dimension names a basis the run does not publish");
            }

            self::assertSame(
                (int) ($projectMetrics[$basis] ?? 0),
                $coverage['measured'],
                "$dimension diverges from the $basis the same run measured",
            );
            self::assertGreaterThan(0, $coverage['eligible'], "$dimension divides by an empty population");
            self::assertLessThanOrEqual(
                $coverage['eligible'],
                $coverage['measured'],
                "$dimension counts symbols its population does not",
            );
            self::assertSame(
                $coverage['measured'] / $coverage['eligible'],
                $coverage['ratio'],
                "$dimension publishes a ratio that is not its own fraction",
            );
        }

        self::assertGreaterThan(0, $measuredDimensions, 'No dimension published a measured coverage');

        // The fixture's two one-method classes carry no TCC, so a cohesion
        // score that claimed the whole fixture would be claiming too much.
        $cohesion = $health['cohesion']['coverage'];
        self::assertSame('measured', $cohesion['state']);
        self::assertSame('cohesion.tcc.count', $cohesion['basis']);
        self::assertSame((int) $projectMetrics['size.symbol-class-count'], $cohesion['eligible']);
        self::assertLessThan(1.0, $cohesion['ratio']);
    }

    /**
     * @return array<string, mixed>
     */
    private function analyze(string $format): array
    {
        $process = new Process([
            \PHP_BINARY,
            'bin/qmx',
            'check',
            $this->fixtureDirectory,
            '--workers=0',
            '--no-cache',
            '--no-progress',
            '--format=' . $format,
        ], \dirname(__DIR__, 5));
        $process->run();

        $decoded = json_decode($process->getOutput(), true);

        if (!\is_array($decoded)) {
            throw new RuntimeException('The ' . $format . ' run produced no JSON: ' . $process->getErrorOutput());
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $metricsReport
     *
     * @return array<string, int|float>
     */
    private function projectMetrics(array $metricsReport): array
    {
        /** @var list<array{type: string, metrics: array<string, int|float>}> $symbols */
        $symbols = $metricsReport['symbols'] ?? [];

        foreach ($symbols as $symbol) {
            if ($symbol['type'] === 'project') {
                return $symbol['metrics'];
            }
        }

        throw new RuntimeException('The metrics run published no project symbol');
    }
}
