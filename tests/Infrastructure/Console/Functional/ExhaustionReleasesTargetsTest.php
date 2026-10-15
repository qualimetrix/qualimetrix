<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargetClaimLifecycle;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(RunTargetClaimLifecycle::class)]
final class ExhaustionReleasesTargetsTest extends TestCase
{
    #[Test]
    public function itReleasesUnpublishedReportAndProfileTargetsWhenTheCoordinatorRunsOutOfMemory(): void
    {
        $directory = sys_get_temp_dir() . '/qmx-oom-targets-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $report = $directory . '/report.json';
        $profile = $directory . '/profile.json';
        self::assertSame(3, file_put_contents($report, 'OLD'));

        try {
            $run = ChildProcess::run([
                \PHP_BINARY,
                'bin/qmx',
                'check',
                'src',
                '--workers=0',
                '--no-cache',
                '--no-progress',
                '--memory-limit=64M',
                '--format=json',
                '--output=' . $report,
                '--profile=' . $profile,
            ], \dirname(__DIR__, 4));

            self::assertSame(4, $run['exitCode'], $run['stdout'] . $run['stderr']);
            /** @var array{error: string, exit_code: int} $answer */
            $answer = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame(4, $answer['exit_code']);
            self::assertStringContainsString('ran out of memory', $answer['error']);
            self::assertSame('', $run['stderr']);
            self::assertSame('OLD', file_get_contents($report));
            self::assertFileDoesNotExist($profile);
            self::assertSame(['report.json'], array_values(array_diff((array) scandir($directory), ['.', '..'])));
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
            if (is_file($profile)) {
                unlink($profile);
            }
            foreach (array_diff((array) scandir($directory), ['.', '..']) as $entry) {
                unlink($directory . '/' . $entry);
            }
            rmdir($directory);
        }
    }
}
