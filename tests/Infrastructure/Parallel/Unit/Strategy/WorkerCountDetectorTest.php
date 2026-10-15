<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit\Strategy;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Parallel\Strategy\CgroupCpuQuota;
use Qualimetrix\Infrastructure\Parallel\Strategy\CgroupLocation;
use Qualimetrix\Infrastructure\Parallel\Strategy\WorkerCountDetector;
use Qualimetrix\Subprocess\ChildProcess;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once \dirname(__DIR__, 5) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(WorkerCountDetector::class)]
#[CoversClass(CgroupCpuQuota::class)]
#[CoversClass(CgroupLocation::class)]
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

    #[Test]
    public function itReadsTheProcessorCountFromTheWindowsEnvironmentVariable(): void
    {
        $root = $this->plant([]);
        self::assertSame(7, (new WorkerCountDetector($root, 'Windows', ['NUMBER_OF_PROCESSORS' => '7']))->detect());
    }

    #[Test]
    #[DataProvider('invalidWindowsCounts')]
    public function itRefusesInvalidWindowsProcessorCounts(string $value): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('NUMBER_OF_PROCESSORS must be a positive decimal integer; received ' . $value . '.');
        (new WorkerCountDetector($this->plant([]), 'Windows', ['NUMBER_OF_PROCESSORS' => $value]))->detect();
    }

    /** @return iterable<string, array{string}> */
    public static function invalidWindowsCounts(): iterable
    {
        foreach (['abc', '0', '-2', '0x10', '2.9', '1e2', '1e9', '', ' 8', '08', '99999999999999999999'] as $value) {
            yield 'value ' . $value => [$value];
        }
    }

    #[Test]
    public function itIgnoresTheWindowsEnvironmentOnLinux(): void
    {
        $root = $this->plant(['/proc/cpuinfo' => self::cpuinfo(10)]);
        self::assertSame(10, (new WorkerCountDetector($root, 'Linux', ['NUMBER_OF_PROCESSORS' => '1e9']))->detect());
    }

    #[Test]
    public function itCountsOnlyTheAllowedProcessors(): void
    {
        $root = $this->plant(['/proc/cpuinfo' => self::cpuinfo(10), '/proc/self/status' => "Cpus_allowed_list:\t0\n"]);
        self::assertSame(1, (new WorkerCountDetector($root, 'Linux', []))->detect());
    }

    #[Test]
    public function itCountsAllowedRangesWithoutDoubleCountingOverlaps(): void
    {
        $root = $this->plant(['/proc/cpuinfo' => self::cpuinfo(16), '/proc/self/status' => "Cpus_allowed_list:\t0-2,2-4,7\n"]);
        self::assertSame(6, (new WorkerCountDetector($root, 'Linux', []))->detect());
    }

    #[Test]
    public function itCountsNoMoreThanTheHostWhenTheAllowedListIsLarger(): void
    {
        $root = $this->plant(['/proc/cpuinfo' => self::cpuinfo(2), '/proc/self/status' => "Cpus_allowed_list:\t0-15\n"]);
        self::assertSame(2, (new WorkerCountDetector($root, 'Linux', []))->detect());
    }

    #[Test]
    public function itSkipsAnUnavailableShellProbe(): void
    {
        $root = \dirname(__DIR__, 5);
        $probe = 'require $argv[1]; $detector = new ' . WorkerCountDetector::class . '(); '
            . '$method = new ReflectionMethod($detector, "executeCommand"); '
            . 'echo json_encode($method->invoke($detector, "echo 8"));';
        $result = ChildProcess::run([\PHP_BINARY, '-d', 'disable_functions=shell_exec', '-r', $probe, $root . '/vendor/autoload.php']);

        self::assertSame(0, $result['exitCode']);
        self::assertSame('null', $result['stdout']);
        self::assertSame('', $result['stderr']);
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

        self::assertSame($expected, (new WorkerCountDetector($root, 'Linux', []))->detect());
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
        yield 'unlimited v2 overrides v1' => [[
            '/sys/fs/cgroup/cpu.max' => "max 100000\n",
            '/sys/fs/cgroup/cpu/cpu.cfs_quota_us' => '100000',
            '/sys/fs/cgroup/cpu/cpu.cfs_period_us' => '100000',
        ], 16];
        yield 'malformed v2 overrides v1' => [[
            '/sys/fs/cgroup/cpu.max' => "garbage\n",
            '/sys/fs/cgroup/cpu/cpu.cfs_quota_us' => '100000',
            '/sys/fs/cgroup/cpu/cpu.cfs_period_us' => '100000',
        ], 16];
        yield 'own v2 quota' => [[
            '/proc/self/cgroup' => "0::/docker/example\n",
            '/sys/fs/cgroup/docker/example/cpu.max' => '100000 100000',
        ], 1];
        yield 'unlimited own v2 and limited parent' => [[
            '/proc/self/cgroup' => "0::/docker/example\n",
            '/sys/fs/cgroup/docker/example/cpu.max' => 'max 100000',
            '/sys/fs/cgroup/docker/cpu.max' => '50000 100000',
        ], 1];
        yield 'stricter v2 ancestor' => [[
            '/proc/self/cgroup' => "0::/docker/example\n",
            '/sys/fs/cgroup/docker/example/cpu.max' => '200000 100000',
            '/sys/fs/cgroup/docker/cpu.max' => '100000 100000',
        ], 1];
        yield 'cgroup namespace inaccessible own path uses mount root' => [[
            '/proc/self/cgroup' => "0::/docker/unmounted\n",
            '/sys/fs/cgroup/cpu.max' => '100000 100000',
        ], 1];
        yield 'v1 inherited parent quota' => [[
            '/proc/self/cgroup' => "2:cpu,cpuacct:/docker/example\n",
            '/sys/fs/cgroup/cpu/docker/example/cpu.cfs_quota_us' => '-1',
            '/sys/fs/cgroup/cpu/docker/example/cpu.cfs_period_us' => '100000',
            '/sys/fs/cgroup/cpu/docker/cpu.cfs_quota_us' => '100000',
            '/sys/fs/cgroup/cpu/docker/cpu.cfs_period_us' => '100000',
        ], 1];
        yield 'v2 mount root prefix' => [[
            '/proc/self/cgroup' => "0::/docker/example/child\n",
            '/proc/self/mountinfo' => "30 25 0:28 /docker/example /sys/fs/cgroup rw - cgroup2 cgroup rw\n",
            '/sys/fs/cgroup/child/cpu.max' => '200000 100000',
            '/sys/fs/cgroup/cpu.max' => '100000 100000',
        ], 1];
        yield 'v1 alternate mount point' => [[
            '/proc/self/cgroup' => "2:cpu,cpuacct:/docker/example\n",
            '/proc/self/mountinfo' => "30 25 0:28 / /sys/fs/cgroup/cpu,cpuacct rw - cgroup cgroup rw,cpu,cpuacct\n",
            '/sys/fs/cgroup/cpu,cpuacct/docker/example/cpu.cfs_quota_us' => '200000',
            '/sys/fs/cgroup/cpu,cpuacct/docker/example/cpu.cfs_period_us' => '100000',
            '/sys/fs/cgroup/cpu,cpuacct/docker/cpu.cfs_quota_us' => '100000',
            '/sys/fs/cgroup/cpu,cpuacct/docker/cpu.cfs_period_us' => '100000',
        ], 1];
    }

    #[Test]
    public function itReadsTheProcessorCountWithoutAControlGroup(): void
    {
        $root = $this->plant(['/proc/cpuinfo' => self::cpuinfo(5)]);

        self::assertSame(5, (new WorkerCountDetector($root, 'Linux', []))->detect());
    }

    /** @param array<string, string> $files */
    private function plant(array $files): string
    {
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
            $lines[] = "processor\t: " . $i;
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
