<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DuplicationMemoryLimitProcessTest extends TestCase
{
    private const string MEMORY_LIMIT = '24M';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/qmx-duplication-memory-' . uniqid('', true);
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
onlyRules: ['duplication.code-duplication']
failOn: none
rules:
  duplication.code-duplication:
    min_tokens: 20
    min_lines: 3
YAML);

        [$exitCode, $stdout, $stderr] = $this->runQmx($configPath);

        self::assertSame(0, $exitCode, $stderr . "\n" . $stdout);

        /** @var array{coverage?: array{complete?: bool}, findings?: list<array{rule?: string}>} $report */
        $report = json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
        self::assertTrue($report['coverage']['complete'] ?? false, $stdout);
        self::assertContains(
            'duplication.code-duplication',
            array_column($report['violations'] ?? [], 'rule'),
            $stdout,
        );
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
    private function runQmx(string $configPath): array
    {
        $projectRoot = $this->projectRoot();

        return $this->runProcess([
            \PHP_BINARY,
            '-d',
            'memory_limit=128M',
            $projectRoot . '/bin/qmx',
            'check',
            $this->tmpDir . '/src',
            '--config=' . $configPath,
            '--format=json',
            '--no-progress',
            '--no-cache',
            '--workers=0',
            '--memory-limit=128M',
        ]);
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string, string}
     */
    private function runProcess(array $command): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmpDir,
        );

        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
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
