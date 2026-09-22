<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Parallel\Strategy;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Parallel\Strategy\WorkerCountDetector;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass(WorkerCountDetector::class)]
final class WorkerCountDetectorTest extends TestCase
{
    /** @var list<string> */
    private array $plantedRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->plantedRoots as $root) {
            $this->removeDirectory($root);
        }
        $this->plantedRoots = [];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    #[Test]
    public function itDetectsWorkerCountGreaterThanZero(): void
    {
        $detector = new WorkerCountDetector();

        $count = $detector->detect();

        self::assertGreaterThan(0, $count);
    }

    /**
     * The one branch a test can drive. The others read /proc, shell out, or
     * are the fallback that only a machine with none of them reaches, and the
     * detector takes no seam for any of them — so the fallback the old name
     * here promised was, and remains, unreachable from a test.
     */
    #[Test]
    public function itReadsTheProcessorCountFromTheWindowsEnvironmentVariable(): void
    {
        $before = getenv('NUMBER_OF_PROCESSORS');
        putenv('NUMBER_OF_PROCESSORS=7');

        try {
            self::assertSame(7, (new WorkerCountDetector())->detect());
        } finally {
            putenv($before === false ? 'NUMBER_OF_PROCESSORS' : 'NUMBER_OF_PROCESSORS=' . $before);
        }
    }

    /**
     * The processor count is a property of the host; the quota is what this
     * process may actually use. In the product's documented main setting — a
     * container in CI — they differ by an order of magnitude, and each worker
     * is a process with its own parser and its own cache.
     *
     * @param array<string, string> $files Control-group files, by path under the planted root
     */
    #[Test]
    #[DataProvider('provideQuotaCases')]
    public function itCapsTheWorkerCountByTheControlGroupQuota(array $files, int $expected): void
    {
        $root = $this->plant(['/proc/cpuinfo' => self::cpuinfo(16)] + $files);

        self::assertSame($expected, (new WorkerCountDetector($root))->detect());
    }

    /** @return iterable<string, array{array<string, string>, int}> */
    public static function provideQuotaCases(): iterable
    {
        yield 'no control group at all' => [[], 16];
        yield 'cgroup v2 unlimited' => [['/sys/fs/cgroup/cpu.max' => "max 100000\n"], 16];
        yield 'cgroup v2 two CPUs' => [['/sys/fs/cgroup/cpu.max' => "200000 100000\n"], 2];
        yield 'cgroup v2 half a CPU still runs one worker' => [['/sys/fs/cgroup/cpu.max' => "50000 100000\n"], 1];
        yield 'cgroup v2 quota above the host count does not raise it' => [
            ['/sys/fs/cgroup/cpu.max' => "6400000 100000\n"],
            16,
        ];
        yield 'cgroup v1 three CPUs' => [
            [
                '/sys/fs/cgroup/cpu/cpu.cfs_quota_us' => "300000\n",
                '/sys/fs/cgroup/cpu/cpu.cfs_period_us' => "100000\n",
            ],
            3,
        ];
        yield 'cgroup v1 unlimited' => [
            [
                '/sys/fs/cgroup/cpu/cpu.cfs_quota_us' => "-1\n",
                '/sys/fs/cgroup/cpu/cpu.cfs_period_us' => "100000\n",
            ],
            16,
        ];
    }

    #[Test]
    public function itReadsTheProcessorCountWithoutAControlGroup(): void
    {
        $root = $this->plant(['/proc/cpuinfo' => self::cpuinfo(5)]);

        self::assertSame(5, (new WorkerCountDetector($root))->detect());
    }

    /**
     * The Windows branch answers before anything the plant can say, so a root
     * planted while that variable is set would measure the variable.
     *
     * @param array<string, string> $files
     */
    private function plant(array $files): string
    {
        if (getenv('NUMBER_OF_PROCESSORS') !== false) {
            putenv('NUMBER_OF_PROCESSORS');
        }

        $root = sys_get_temp_dir() . '/qmx-cgroup-' . bin2hex(random_bytes(6));
        $this->plantedRoots[] = $root;

        foreach ($files as $relative => $contents) {
            $path = $root . $relative;
            $directory = \dirname($path);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            file_put_contents($path, $contents);
        }

        if (!is_dir($root)) {
            mkdir($root, 0755, true);
        }

        return $root;
    }

    private static function cpuinfo(int $processors): string
    {
        $lines = [];
        for ($i = 0; $i < $processors; ++$i) {
            $lines[] = 'processor\t: ' . $i;
        }

        return implode("\n\n", $lines) . "\n";
    }

    #[Test]
    public function itReturnsConsistentResults(): void
    {
        $detector = new WorkerCountDetector();

        $count1 = $detector->detect();
        $count2 = $detector->detect();

        // Two consecutive calls should return the same result
        self::assertSame($count1, $count2);
    }
}
