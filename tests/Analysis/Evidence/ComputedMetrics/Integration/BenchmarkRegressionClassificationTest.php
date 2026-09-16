<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * `scripts/benchmark-regression.php` classifies a project outcome into one of
 * three buckets, not two: an infrastructure failure (the analysis could not
 * run at all — this and only this blocks `--update-baselines` together with a
 * partial corpus), an expectation mismatch (a measured value outside its
 * recorded range — never blocks the write, since recalibration exists to fix
 * exactly those), and a metric the baseline expects but the analysis did not
 * measure (neither of the above — reported distinctly, does not block the
 * write, but the write cannot seed a value that was never produced).
 *
 * A project entry carrying only `path` (no `expectations` block) is accepted
 * and every canonical health.* metric is measured against it for seeding.
 *
 * Every case here drives a real subprocess run of the tracked script against
 * a fake `bin/qmx` (see {@see writeFakeQmx()}), the same isolation
 * `BenchmarkCoverageRefusalTest` already uses — no real analysis, no
 * touching the repository's own baseline file.
 */
final class BenchmarkRegressionClassificationTest extends TestCase
{
    /** @var list<string> */
    private array $fixtureRoots = [];

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        foreach ($this->fixtureRoots as $fixtureRoot) {
            $filesystem->remove($fixtureRoot);
        }
    }

    #[Test]
    public function itProducesFourDistinctOutcomesInOneUpdateRun(): void
    {
        $fixtureRoot = $this->createFixtureRoot();
        $this->copyScript($fixtureRoot);
        mkdir($fixtureRoot . '/fixtures/mismatch', recursive: true);
        mkdir($fixtureRoot . '/fixtures/nometric', recursive: true);
        mkdir($fixtureRoot . '/fixtures/fresh', recursive: true);
        // 'brokeninfra' deliberately has no directory: it must fail on "path not found".

        $baselinePath = $this->writeBaseline($fixtureRoot, [
            'brokeninfra' => ['path' => 'fixtures/missing', 'expectations' => ['health.overall' => [0, 100]]],
            'mismatch' => ['path' => 'fixtures/mismatch', 'expectations' => ['health.overall' => [80, 100]]],
            'nometric' => [
                'path' => 'fixtures/nometric',
                'expectations' => ['health.overall' => [0, 100], 'health.typing' => [0, 100]],
            ],
            // No 'expectations' key at all — the corpus-seeding case.
            'fresh' => ['path' => 'fixtures/fresh'],
        ]);
        $originalBaseline = (string) file_get_contents($baselinePath);

        $this->writeFakeQmx($fixtureRoot, <<<'PHP'
$base = basename($path);
if ($base === 'mismatch') {
    $symbols = [['type' => 'project', 'name' => 'p', 'metrics' => ['health.overall' => 50]]];
} elseif ($base === 'nometric') {
    // health.typing is expected but never produced.
    $symbols = [['type' => 'project', 'name' => 'p', 'metrics' => ['health.overall' => 70]]];
} elseif ($base === 'fresh') {
    $symbols = [['type' => 'project', 'name' => 'p', 'metrics' => [
        'health.complexity' => 60, 'health.cohesion' => 60, 'health.coupling' => 60,
        'health.maintainability' => 60, 'health.typing' => 60, 'health.overall' => 60,
    ]]];
} else {
    $symbols = [];
}
PHP);

        $process = new Process([\PHP_BINARY, 'scripts/benchmark-regression.php', '--update-baselines'], $fixtureRoot);
        $process->run();

        // An infrastructure failure ('brokeninfra') is present, so the whole write is
        // blocked (partial corpus) even though 'fresh' and part of 'nometric' succeeded.
        self::assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame($originalBaseline, file_get_contents($baselinePath));

        $errorOutput = $process->getErrorOutput();

        // Outcome 1: infrastructure failure.
        self::assertStringContainsString('brokeninfra: benchmark path not found', $errorOutput);
        // Outcome 2: expectation mismatch.
        self::assertStringContainsString('mismatch: health.overall = 50.0, expected [80, 100]', $errorOutput);
        // Outcome 3: metric expected but not measured — distinct wording from both above.
        self::assertStringContainsString('nometric: metric health.typing not found', $errorOutput);
        self::assertStringNotContainsString('nometric: health.overall', $errorOutput);
        // Outcome 4: no expectations at all — measured cleanly, no failure line for it.
        self::assertStringNotContainsString('fresh:', $errorOutput);
    }

    #[Test]
    public function itWritesWhenTheCorpusIsCompleteAndExpectationsDisagree(): void
    {
        $fixtureRoot = $this->createFixtureRoot();
        $this->copyScript($fixtureRoot);
        mkdir($fixtureRoot . '/fixtures/project', recursive: true);

        // The recorded range is deliberately far from the measured value (50): this is a
        // recalibration case, and it must not block the write.
        $baselinePath = $this->writeBaseline($fixtureRoot, [
            'project' => ['path' => 'fixtures/project', 'expectations' => ['health.overall' => [0, 10]]],
        ]);

        $this->writeFakeQmx(
            $fixtureRoot,
            "\$symbols = [['type' => 'project', 'name' => 'p', 'metrics' => ['health.overall' => 50]]];",
        );

        $process = new Process([\PHP_BINARY, 'scripts/benchmark-regression.php', '--update-baselines'], $fixtureRoot);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $written = json_decode((string) file_get_contents($baselinePath), true);
        self::assertIsArray($written);
        self::assertSame([40, 60], $written['projects']['project']['expectations']['health.overall']);
    }

    #[Test]
    public function itRefusesToWriteWhenAProjectPathIsMissing(): void
    {
        $fixtureRoot = $this->createFixtureRoot();
        $this->copyScript($fixtureRoot);
        // No fixtures directory created at all — the configured path never exists.

        $baselinePath = $this->writeBaseline($fixtureRoot, [
            'onlybroken' => ['path' => 'fixtures/missing', 'expectations' => ['health.overall' => [0, 100]]],
        ]);
        $originalBaseline = (string) file_get_contents($baselinePath);

        $this->writeFakeQmx($fixtureRoot, '$symbols = [];');

        $process = new Process([\PHP_BINARY, 'scripts/benchmark-regression.php', '--update-baselines'], $fixtureRoot);
        $process->run();

        self::assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('onlybroken: benchmark path not found', $process->getErrorOutput());
        self::assertSame($originalBaseline, file_get_contents($baselinePath));
    }

    #[Test]
    public function itSeedsAProjectEntryThatHasOnlyAPathInsteadOfFailing(): void
    {
        $fixtureRoot = $this->createFixtureRoot();
        $this->copyScript($fixtureRoot);
        mkdir($fixtureRoot . '/fixtures/fresh', recursive: true);

        $baselinePath = $this->writeBaseline($fixtureRoot, [
            // No 'expectations' key: must not be fatal, must be measured and seeded.
            'fresh' => ['path' => 'fixtures/fresh'],
        ]);

        $this->writeFakeQmx($fixtureRoot, <<<'PHP'
$symbols = [['type' => 'project', 'name' => 'p', 'metrics' => [
    'health.complexity' => 61, 'health.cohesion' => 62, 'health.coupling' => 63,
    'health.maintainability' => 64, 'health.typing' => 65, 'health.overall' => 66,
]]];
PHP);

        $process = new Process([\PHP_BINARY, 'scripts/benchmark-regression.php', '--update-baselines'], $fixtureRoot);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $written = json_decode((string) file_get_contents($baselinePath), true);
        self::assertIsArray($written);
        $expectations = $written['projects']['fresh']['expectations'];
        self::assertSame([51, 71], $expectations['health.complexity']);
        self::assertSame([55, 75], $expectations['health.typing']);
        self::assertSame([56, 76], $expectations['health.overall']);
    }

    #[Test]
    public function itPrintsAndSeedsHealthTyping(): void
    {
        $fixtureRoot = $this->createFixtureRoot();
        $this->copyScript($fixtureRoot);
        mkdir($fixtureRoot . '/fixtures/project', recursive: true);

        $baselinePath = $this->writeBaseline($fixtureRoot, [
            'project' => ['path' => 'fixtures/project', 'expectations' => ['health.typing' => [0, 100]]],
        ]);

        $this->writeFakeQmx(
            $fixtureRoot,
            "\$symbols = [['type' => 'project', 'name' => 'p', 'metrics' => ['health.typing' => 77]]];",
        );

        $process = new Process([\PHP_BINARY, 'scripts/benchmark-regression.php', '--update-baselines'], $fixtureRoot);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('typng', $process->getErrorOutput());

        $written = json_decode((string) file_get_contents($baselinePath), true);
        self::assertIsArray($written);
        self::assertSame([67, 87], $written['projects']['project']['expectations']['health.typing']);
    }

    /**
     * A project's `expectations` block predates `health.typing` (the real corpus's
     * `docs/internal/benchmark-baselines.json` at the time of this test). The analysis
     * still measures it — the table must print that measured value, not silently
     * substitute 0 because no expectation exists to key the table off of. A genuinely
     * unmeasured, undeclared metric (`health.security` here, standing in for a metric
     * this fixture's fake qmx never produces) must print a distinct absence marker
     * instead of a value, so the two situations are never visually confused.
     */
    #[Test]
    public function itPrintsTheMeasuredValueOfAMetricAbsentFromExpectations(): void
    {
        $fixtureRoot = $this->createFixtureRoot();
        $this->copyScript($fixtureRoot);
        mkdir($fixtureRoot . '/fixtures/project', recursive: true);

        // Only health.overall is declared — health.typing is measured but has no
        // expectation yet, exactly like the pre-migration real baseline file.
        $this->writeBaseline($fixtureRoot, [
            'project' => ['path' => 'fixtures/project', 'expectations' => ['health.overall' => [0, 100]]],
        ]);

        $this->writeFakeQmx($fixtureRoot, <<<'PHP'
$symbols = [['type' => 'project', 'name' => 'p', 'metrics' => [
    'health.overall' => 55,
    'health.typing' => 97,
]]];
PHP);

        $process = new Process([\PHP_BINARY, 'scripts/benchmark-regression.php'], $fixtureRoot);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $errorOutput = $process->getErrorOutput();

        // The measured value (97.0), not a substituted zero.
        self::assertMatchesRegularExpression('/\bproject\s+.*\b97\.0\b/', $errorOutput, $errorOutput);
        self::assertStringNotContainsString(
            "0.0    0.0\n",
            $errorOutput,
            'health.typing must not read back as a substituted 0.0',
        );

        // Nothing in HEALTH_METRICS was left entirely unmeasured here, so there is no
        // failure line for health.typing at all — it is a clean, undeclared measurement.
        self::assertStringNotContainsString('metric health.typing not found', $errorOutput);
    }

    #[Test]
    public function itMarksAGenuinelyUnmeasuredUndeclaredMetricAsAbsentRatherThanZero(): void
    {
        $fixtureRoot = $this->createFixtureRoot();
        $this->copyScript($fixtureRoot);
        mkdir($fixtureRoot . '/fixtures/project', recursive: true);

        // Declares (and the fake qmx measures) only health.overall; health.complexity is
        // canonical but this analysis run never produced it and nothing declared it.
        $this->writeBaseline($fixtureRoot, [
            'project' => ['path' => 'fixtures/project', 'expectations' => ['health.overall' => [0, 100]]],
        ]);

        $this->writeFakeQmx(
            $fixtureRoot,
            "\$symbols = [['type' => 'project', 'name' => 'p', 'metrics' => ['health.overall' => 55]]];",
        );

        $process = new Process([\PHP_BINARY, 'scripts/benchmark-regression.php'], $fixtureRoot);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $errorOutput = $process->getErrorOutput();

        // Not measured, not declared: an absence marker, not a value, and not a failure.
        self::assertMatchesRegularExpression('/\bproject\s+\s*n\/a/', $errorOutput, $errorOutput);
        self::assertStringNotContainsString('metric health.complexity not found', $errorOutput);
    }

    #[Test]
    public function itWritesNamespaceAndClassDistributionsSeparatelyFromBaselines(): void
    {
        $fixtureRoot = $this->createFixtureRoot();
        $this->copyScript($fixtureRoot);
        mkdir($fixtureRoot . '/fixtures/project', recursive: true);

        $this->writeBaseline($fixtureRoot, [
            'project' => ['path' => 'fixtures/project', 'expectations' => ['health.overall' => [0, 100]]],
        ]);

        $this->writeFakeQmx($fixtureRoot, <<<'PHP'
$symbols = [
    ['type' => 'project', 'name' => 'p', 'metrics' => ['health.overall' => 60]],
    ['type' => 'namespace', 'name' => 'N1', 'metrics' => ['health.overall' => 40]],
    ['type' => 'namespace', 'name' => 'N2', 'metrics' => ['health.overall' => 80]],
    ['type' => 'class', 'name' => 'C1', 'metrics' => ['health.overall' => 90]],
];
PHP);

        $process = new Process([\PHP_BINARY, 'scripts/benchmark-regression.php', '--update-baselines'], $fixtureRoot);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $distributionPath = $fixtureRoot . '/docs/internal/benchmark-namespace-class-distribution.json';
        self::assertFileExists($distributionPath);
        $distribution = json_decode((string) file_get_contents($distributionPath), true);
        self::assertIsArray($distribution);

        $namespaceOverall = $distribution['projects']['project']['namespace']['health.overall'];
        self::assertSame(2, $namespaceOverall['count']);
        self::assertEqualsWithDelta(60.0, $namespaceOverall['median'], 0.001);

        $classOverall = $distribution['projects']['project']['class']['health.overall'];
        self::assertSame(1, $classOverall['count']);
        self::assertEqualsWithDelta(90.0, $classOverall['median'], 0.001);
        self::assertEqualsWithDelta(0.0, $classOverall['iqr'], 0.001);

        // The distribution artifact is a separate document from the baseline ratchet: it
        // carries no `expectations` and the baseline carries no `namespace`/`class` keys.
        $baseline = json_decode(
            (string) file_get_contents($fixtureRoot . '/docs/internal/benchmark-baselines.json'),
            true,
        );
        self::assertIsArray($baseline);
        self::assertArrayNotHasKey('expectations', $distribution['projects']['project']);
        self::assertArrayNotHasKey('namespace', $baseline['projects']['project']);
    }

    /**
     * The write succeeds, and the run still exits 1.
     *
     * `health.typing` is expected and never produced: there is no value to seed it
     * from, so the write leaves the recorded range standing. A zero exit here would
     * hand the operator a baseline carrying an expectation this analysis cannot
     * satisfy — `benchmark:check` reddens on it at the next step, and no further
     * `--update-baselines` can clean it, because the update path only ever merges.
     */
    #[Test]
    public function itExitsNonZeroWhenTheWriteLeavesAnUnmeasuredExpectationStanding(): void
    {
        $fixtureRoot = $this->createFixtureRoot();
        $this->copyScript($fixtureRoot);
        mkdir($fixtureRoot . '/fixtures/project', recursive: true);

        $baselinePath = $this->writeBaseline($fixtureRoot, [
            'project' => [
                'path' => 'fixtures/project',
                'expectations' => ['health.overall' => [0, 100], 'health.typing' => [0, 100]],
            ],
        ]);

        $this->writeFakeQmx(
            $fixtureRoot,
            "\$symbols = [['type' => 'project', 'name' => 'p', 'metrics' => ['health.overall' => 70]]];",
        );

        $process = new Process([\PHP_BINARY, 'scripts/benchmark-regression.php', '--update-baselines'], $fixtureRoot);
        $process->run();

        $errorOutput = $process->getErrorOutput();

        self::assertSame(1, $process->getExitCode(), $errorOutput);
        self::assertStringContainsString('EXPECTED BUT NOT MEASURED (1)', $errorOutput);
        self::assertStringContainsString('project: metric health.typing not found', $errorOutput);
        // Distinct from a mismatch at the project's own line too, not only in the block.
        self::assertStringContainsString('UNMEASURED', $errorOutput);

        // The write itself is not blocked: what was measured is re-seeded, and the
        // expectation nothing could seed is left exactly as it was.
        $written = json_decode((string) file_get_contents($baselinePath), true);
        self::assertIsArray($written);
        self::assertSame([60, 80], $written['projects']['project']['expectations']['health.overall']);
        self::assertSame([0, 100], $written['projects']['project']['expectations']['health.typing']);
    }

    /**
     * A corpus entry with no `path` used to interpolate to the empty string, making
     * the analysed directory the repository root: it exists, it analyses (vendor/
     * included), and under `--update-baselines` the result is seeded as that
     * project's reference. An entry that does not say what to measure is an
     * infrastructure refusal, not a measurement of everything.
     */
    #[Test]
    public function itRefusesABaselineEntryThatDeclaresNoPath(): void
    {
        $fixtureRoot = $this->createFixtureRoot();
        $this->copyScript($fixtureRoot);
        mkdir($fixtureRoot . '/fixtures/project', recursive: true);

        $baselinePath = $this->writeBaseline($fixtureRoot, [
            'pathless' => ['expectations' => ['health.overall' => [0, 100]]],
            'project' => ['path' => 'fixtures/project', 'expectations' => ['health.overall' => [0, 100]]],
        ]);
        $originalBaseline = (string) file_get_contents($baselinePath);

        $this->writeFakeQmx(
            $fixtureRoot,
            "\$symbols = [['type' => 'project', 'name' => 'p', 'metrics' => ['health.overall' => 70]]];",
        );

        $process = new Process([\PHP_BINARY, 'scripts/benchmark-regression.php', '--update-baselines'], $fixtureRoot);
        $process->run();

        $errorOutput = $process->getErrorOutput();

        self::assertSame(1, $process->getExitCode(), $errorOutput);
        self::assertStringContainsString('pathless: baseline entry declares no `path`', $errorOutput);
        // An infrastructure refusal blocks the write for the whole corpus.
        self::assertSame($originalBaseline, file_get_contents($baselinePath));
    }

    private function createFixtureRoot(): string
    {
        $fixtureRoot = sys_get_temp_dir() . '/qmx_benchmark_classification_' . bin2hex(random_bytes(6));
        if (!mkdir($fixtureRoot . '/scripts', recursive: true)) {
            throw new RuntimeException('Failed to create benchmark classification fixture');
        }
        $this->fixtureRoots[] = $fixtureRoot;

        return $fixtureRoot;
    }

    private function copyScript(string $fixtureRoot): void
    {
        $source = \dirname(__DIR__, 5) . '/scripts/benchmark-regression.php';
        if (!copy($source, $fixtureRoot . '/scripts/benchmark-regression.php')) {
            throw new RuntimeException('Failed to copy benchmark-regression.php');
        }
    }

    /** @param array<string, array{path?: string, expectations?: array<string, array{0: int, 1: int}>}> $projects */
    private function writeBaseline(string $fixtureRoot, array $projects): string
    {
        $baselinePath = $fixtureRoot . '/docs/internal/benchmark-baselines.json';
        mkdir(\dirname($baselinePath), recursive: true);
        $encoded = json_encode(
            ['updated_at' => '2000-01-01', 'projects' => $projects],
            \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR,
        ) . "\n";
        file_put_contents($baselinePath, $encoded);

        return $baselinePath;
    }

    /**
     * Writes a fake `bin/qmx` whose `check` output is driven by the given PHP snippet.
     * The snippet receives `$path` (the analysed directory, extracted from argv) and
     * must assign a `$symbols` list; coverage is always reported complete.
     */
    private function writeFakeQmx(string $fixtureRoot, string $symbolsSnippet): void
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
$path = null;
foreach ($argv as $arg) {
    if (str_contains($arg, 'fixtures/')) {
        $path = $arg;
    }
}
SYMBOLS_SNIPPET
echo json_encode(['symbols' => $symbols, 'coverage' => ['complete' => true]]);
PHP;
        $script = str_replace('SYMBOLS_SNIPPET', $symbolsSnippet, $script);
        $qmxPath = $fixtureRoot . '/bin/qmx';
        file_put_contents($qmxPath, $script);
        chmod($qmxPath, 0755);
    }
}
