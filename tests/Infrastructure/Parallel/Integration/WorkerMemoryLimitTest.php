<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Cohesion\Runtime\LcomCollectionConfigurationStore;
use Qualimetrix\Analysis\Evidence\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\Size\LocCollector;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTask;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use Qualimetrix\Infrastructure\Parallel\WorkerPool;
use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;
use SplFileInfo;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

/**
 * The limit a run resolves governs the workers, where collection happens, and
 * not only the coordinator that resolved it. Both directions are asserted: a
 * limit too small for a file fails that file in the worker, and a limit larger
 * than `php.ini`'s lets the same file through.
 */
#[CoversClass(AmphpParallelStrategy::class)]
#[CoversClass(FileProcessingTask::class)]
#[CoversClass(WorkerPool::class)]
final class WorkerMemoryLimitTest extends TestCase
{
    private const string LARGE_FILE = 'Large.php';

    private string $directory;

    private string $previousLimit;

    protected function setUp(): void
    {
        $this->previousLimit = (string) \ini_get('memory_limit');
        $this->directory = sys_get_temp_dir() . '/qmx-worker-limit-' . bin2hex(random_bytes(8));
        mkdir($this->directory);

        for ($index = 0; $index < 100; $index++) {
            file_put_contents(
                \sprintf('%s/Small%d.php', $this->directory, $index),
                \sprintf("<?php\nfinal class Small%d { public function a(): int { return %d; } }\n", $index, $index),
            );
        }

        $methods = '';
        for ($index = 0; $index < 3000; $index++) {
            $methods .= \sprintf(
                "    public function m%d(int \$a, int \$b): int { if (\$a > \$b) { return \$a * %d + \$b; } foreach ([\$a, \$b] as \$v) { \$a += \$v; } return \$a - \$b; }\n",
                $index,
                $index,
            );
        }
        file_put_contents($this->directory . '/' . self::LARGE_FILE, "<?php\nfinal class Large {\n" . $methods . "}\n");
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->previousLimit);

        $paths = glob($this->directory . '/*.php');
        foreach ($paths === false ? [] : $paths as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    /**
     * Run as a separate `qmx` process: the limit has to be applied by a
     * coordinator whose own usage is below it, which a test process that has
     * already run other tests cannot promise. Duplication is off because it
     * reads every file in the coordinator, and the claim is about the workers.
     */
    #[Test]
    public function itFailsTheFileInTheWorkerWhenTheResolvedLimitIsTooSmallAndNamesTheLimit(): void
    {
        $run = ChildProcess::run(
            [
                \PHP_BINARY,
                \dirname(__DIR__, 4) . '/bin/qmx',
                'check',
                $this->directory,
                '--workers=2',
                '--no-cache',
                '--memory-limit=48M',
                '--disable-rule=duplication.clone',
                '--format=json',
            ],
            $this->directory,
        );
        $stdout = $run['stdout'];
        $stderr = $run['stderr'];
        $exitCode = $run['exitCode'];

        self::assertSame(4, $exitCode, $stdout);
        /** @var array{coverage: array{failures: list<array{path: string, message: string}>}} $report */
        $report = json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([self::LARGE_FILE], array_map(
            static fn(array $failure): string => basename($failure['path']),
            $report['coverage']['failures'],
        ), 'The worker ran the large file under a limit other than the resolved one');
        self::assertStringContainsString('Workers run with memory_limit "48M"', $report['coverage']['failures'][0]['message']);
        // The worker's own fatal error is the witness that the limit reached
        // it: the coordinator's message would name 48M either way.
        self::assertStringContainsString('Allowed memory size of 50331648 bytes exhausted', $stderr);
    }

    #[Test]
    public function itLetsTheWorkerUseALimitLargerThanPhpIni(): void
    {
        ini_set('memory_limit', '1G');

        self::assertTrue($this->largeFileResult()->isSuccessful());
    }

    private function largeFileResult(): FileProcessingResult
    {
        $strategy = new AmphpParallelStrategy(new FileProcessingTaskFactory(
            new LcomCollectionConfigurationStore(),
            DependencyVisitor::class,
            [LocCollector::class, CyclomaticComplexityCollector::class],
        ));
        if (!$strategy->isParallelAvailable()) {
            self::markTestSkipped('Requires amphp workers (ext-parallel or pcntl).');
        }
        $strategy->setWorkerCount(2);
        $root = realpath($this->directory);
        self::assertIsString($root);
        $strategy->setProjectRoot(AbsolutePath::fromString($root));

        $paths = glob($root . '/*.php');
        self::assertIsArray($paths);
        $results = $strategy->execute(
            array_map(static fn(string $path): SplFileInfo => new SplFileInfo($path), $paths),
            static fn(): never => throw new RuntimeException('Sequential fallback executed'),
        );

        foreach ($results as $result) {
            if ($result instanceof FileProcessingResult && $result->filePath->value() === self::LARGE_FILE) {
                return $result;
            }
        }

        self::fail('The large file produced no result');
    }
}
