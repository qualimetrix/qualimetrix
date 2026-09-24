<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 5) . '/scripts/subprocess/ChildProcess.php';

final class DuplicationMemoryLimitProcessTest extends TestCase
{
    private const string MEMORY_LIMIT = '24M';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/qmx-duplication-memory-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/src', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    #[Test]
    public function itBuildsTheExactCandidateIndexWithinABoundedMemoryLimit(): void
    {
        $this->createCorpus();
        $probePath = $this->createHashIndexProbe();
        $projectRoot = $this->projectRoot();

        [$exitCode, $stdout, $stderr] = $this->runProcess([
            \PHP_BINARY,
            '-d',
            'memory_limit=' . self::MEMORY_LIMIT,
            '-d',
            'xdebug.mode=off',
            $probePath,
            $projectRoot . '/vendor/autoload.php',
            $this->tmpDir . '/src',
        ]);

        self::assertSame(0, $exitCode, $stderr . "\n" . $stdout);

        /** @var array{candidateBuckets?: int, peakBytes?: int} $result */
        $result = json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
        self::assertGreaterThan(0, $result['candidateBuckets'] ?? 0, $stdout);
        self::assertLessThanOrEqual(24 * 1024 * 1024, $result['peakBytes'] ?? \PHP_INT_MAX, $stdout);
    }

    #[Test]
    public function itFindsADuplicateWithCompleteCoverageUnderTheMemoryLimit(): void
    {
        $this->createCorpus();
        $configPath = $this->tmpDir . '/qmx.yaml';
        file_put_contents($configPath, <<<'YAML'
onlyRules: ['duplication.clone']
failOn: none
rules:
  duplication.clone:
    min_tokens: 20
    min_lines: 3
YAML);

        [$exitCode, $stdout, $stderr] = $this->runQmx($configPath);

        self::assertSame(0, $exitCode, $stderr . "\n" . $stdout);

        /** @var array{coverage?: array{complete?: bool}, findings?: list<array{rule?: string}>} $report */
        $report = json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
        self::assertTrue($report['coverage']['complete'] ?? false, $stdout);
        self::assertContains(
            'duplication.clone',
            array_column($report['violations'] ?? [], 'rule'),
            $stdout,
        );
    }

    /**
     * 99 and 100 copies of one class exhausted a 128M limit while blocks
     * were kept per pair of copies, and 101 copies were skipped without a
     * trace; every one of these runs must now complete and report the copies.
     *
     * @return iterable<string, array{int}>
     */
    public static function provideCopyCountsAroundTheFormerBucketLimit(): iterable
    {
        yield '99 copies' => [99];
        yield '100 copies' => [100];
        yield '101 copies' => [101];
    }

    #[Test]
    #[DataProvider('provideCopyCountsAroundTheFormerBucketLimit')]
    public function itReportsEveryCopyOfABlockUnderTheDefaultMemoryLimit(int $copies): void
    {
        for ($copy = 0; $copy < $copies; $copy++) {
            file_put_contents($this->tmpDir . "/src/Copy{$copy}.php", $this->copiedClass("Copy{$copy}"));
        }
        $configPath = $this->tmpDir . '/qmx.yaml';
        file_put_contents($configPath, "onlyRules: ['duplication.clone']\nfailOn: none\n");

        [$exitCode, $stdout, $stderr] = $this->runQmx($configPath, '128M');

        self::assertSame(0, $exitCode, $stderr . "\n" . $stdout);

        /** @var array{coverage?: array{complete?: bool}, violations?: list<array{rule?: string, message?: string}>} $report */
        $report = json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
        self::assertTrue($report['coverage']['complete'] ?? false, $stdout);
        $findings = $report['violations'] ?? [];
        self::assertCount(1, $findings, $stdout);
        self::assertSame('duplication.clone', $findings[0]['rule'] ?? null);
        self::assertStringContainsString("{$copies} occurrences", $findings[0]['message'] ?? '');
    }

    private function copiedClass(string $className): string
    {
        return <<<PHP
<?php

final class {$className}
{
    public function run(array \$rows, int \$limit): array
    {
        \$out = [];
        foreach (\$rows as \$key => \$row) {
            if (\$row['status'] === 'active' && \$row['score'] > \$limit) {
                \$out[\$key] = [
                    'name' => strtoupper(\$row['name']),
                    'score' => \$row['score'] * 2 + \$limit,
                    'tags' => array_values(array_filter(\$row['tags'])),
                ];
            } elseif (\$row['status'] === 'pending') {
                \$out[\$key] = null;
            }
        }
        ksort(\$out);
        return array_filter(\$out, static fn (\$v) => \$v !== null);
    }
}

PHP;
    }

    private function createHashIndexProbe(): string
    {
        $probePath = $this->tmpDir . '/hash-index-probe.php';
        file_put_contents($probePath, <<<'PHP'
<?php

declare(strict_types=1);

require $argv[1];

use Qualimetrix\Analysis\Evidence\Duplication\HashIndexBuilder;
use Qualimetrix\Core\Path\AbsolutePath;

$sourceDirectory = $argv[2];
$files = [];
foreach (new DirectoryIterator($sourceDirectory) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = new SplFileInfo($file->getPathname());
    }
}

usort($files, static fn(SplFileInfo $left, SplFileInfo $right): int => $left->getPathname() <=> $right->getPathname());

$result = (new HashIndexBuilder())->build(
    $files,
    AbsolutePath::fromString(dirname($sourceDirectory)),
    20,
);

echo json_encode([
    'candidateBuckets' => count($result->hashIndex),
    'peakBytes' => memory_get_peak_usage(true),
], JSON_THROW_ON_ERROR);
PHP);

        return $probePath;
    }

    private function createCorpus(): void
    {
        $duplicate = <<<'PHP'
<?php

function repeatedBlock(array $items): array
{
    $result = [];
    foreach ($items as $item) {
        $result[] = $item->transform();
    }

    return $result;
}
PHP;
        file_put_contents($this->tmpDir . '/src/RepeatedOne.php', $duplicate);
        file_put_contents($this->tmpDir . '/src/RepeatedTwo.php', $duplicate);

        for ($file = 0; $file < 80; $file++) {
            $functions = ["<?php\n"];
            for ($function = 0; $function < 80; $function++) {
                $functions[] = "function noise{$file}_{$function}(): int { return {$file} + {$function}; }";
            }
            file_put_contents($this->tmpDir . "/src/Noise{$file}.php", implode("\n", $functions));
        }
    }

    /**
     * @return array{int, string, string}
     */
    private function runQmx(string $configPath, string $memoryLimit = '128M'): array
    {
        $projectRoot = $this->projectRoot();

        return $this->runProcess([
            \PHP_BINARY,
            '-d',
            'memory_limit=' . $memoryLimit,
            '-d',
            'xdebug.mode=off',
            $projectRoot . '/bin/qmx',
            'check',
            $this->tmpDir . '/src',
            '--config=' . $configPath,
            '--format=json',
            '--no-progress',
            '--no-cache',
            '--workers=0',
            '--memory-limit=' . $memoryLimit,
        ]);
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string, string}
     */
    private function runProcess(array $command): array
    {
        $result = ChildProcess::run($command, $this->tmpDir);

        return [$result['exitCode'], $result['stdout'], $result['stderr']];
    }

    private function projectRoot(): string
    {
        $directory = __DIR__;

        while ($directory !== \dirname($directory)) {
            if (is_file($directory . '/composer.json')) {
                self::assertFileExists($directory . '/vendor/autoload.php');
                self::assertFileExists($directory . '/bin/qmx');

                return $directory;
            }

            $directory = \dirname($directory);
        }

        self::fail('Cannot locate the project root from the Duplication test directory.');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
