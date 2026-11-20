<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Parallel\WorkerContextFactory;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

/**
 * A launcher can leave qmx holding a descriptor numbered above the soft
 * open-files limit. The limits are process-global, so each case runs as a
 * separate `qmx` under a shell that opens the descriptor and then lowers them.
 * Workers are requested explicitly: an automatic request would hide a startup
 * failure behind the in-process fallback.
 */
#[CoversClass(WorkerContextFactory::class)]
final class InheritedDescriptorLimitTest extends TestCase
{
    private const int DESCRIPTOR = 300;

    private ?string $directory = null;

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !is_executable('/bin/bash') || !\function_exists('posix_getrlimit')) {
            self::markTestSkipped('Requires a POSIX shell that redirects descriptors above 9.');
        }
        $limits = posix_getrlimit(\POSIX_RLIMIT_NOFILE);
        if ($limits === false || (\is_int($limits[0]) && $limits[0] <= self::DESCRIPTOR)) {
            self::markTestSkipped('The test process cannot open descriptor ' . self::DESCRIPTOR . '.');
        }
        $this->directory = sys_get_temp_dir() . '/qmx-inherited-descriptor-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        for ($index = 0; $index < 100; $index++) {
            file_put_contents(
                \sprintf('%s/Small%d.php', $this->directory, $index),
                \sprintf("<?php\nfinal class Small%d { public function a(): int { return %d; } }\n", $index, $index),
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->directory === null) {
            return;
        }
        $paths = glob($this->directory . '/*.php');
        foreach ($paths === false ? [] : $paths as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function itStartsWorkersWhenAnInheritedDescriptorExceedsTheSoftLimit(): void
    {
        $report = $this->runUnder('ulimit -Sn 256');

        self::assertArrayNotHasKey('error', $report);
        self::assertSame(100, $report['summary']['filesAnalyzed'] ?? null);
    }

    #[Test]
    public function itNamesTheDescriptorWhenTheHardLimitForbidsRaising(): void
    {
        $report = $this->runUnder('ulimit -n 256');

        self::assertIsString($report['error'] ?? null);
        self::assertStringContainsString('an inherited file descriptor is numbered ' . self::DESCRIPTOR . ', beyond the open-files limit 256 that the hard limit 256 does not let qmx raise', $report['error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runUnder(string $limit): array
    {
        $directory = $this->directory;
        self::assertNotNull($directory);
        $run = ChildProcess::run(
            [
                '/bin/bash',
                '-c',
                'exec ' . self::DESCRIPTOR . '</dev/null && ' . $limit . ' && exec "$@"',
                'bash',
                \PHP_BINARY,
                \dirname(__DIR__, 4) . '/bin/qmx',
                'check',
                $directory,
                '--workers=2',
                '--no-cache',
                '--disable-rule=duplication.clone',
                '--format=json',
            ],
            $directory,
        );

        $report = json_decode($run['stdout'], true);
        self::assertIsArray($report, $run['stderr']);

        return $report;
    }
}
