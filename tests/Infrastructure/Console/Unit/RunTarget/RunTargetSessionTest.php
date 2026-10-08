<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\RunTarget;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(RunTargetSession::class)]
final class RunTargetSessionTest extends TestCase
{
    /** @return iterable<string, array{int, bool}> */
    public static function interruptionSignals(): iterable
    {
        yield 'sequential SIGTERM' => [\SIGTERM, false];
        yield 'sequential SIGINT' => [\SIGINT, false];
        yield 'Amp SIGTERM' => [\SIGTERM, true];
        yield 'Amp SIGINT' => [\SIGINT, true];
    }

    #[Test]
    #[DataProvider('interruptionSignals')]
    public function itRemovesAnUnpublishedReportOnSignal(int $signal, bool $await): void
    {
        if (!\function_exists('pcntl_signal')) {
            self::markTestSkipped('Signals are unavailable on this host.');
        }
        $directory = sys_get_temp_dir() . '/qmx-session-signal-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $target = $directory . '/report.json';
        $autoload = \dirname(__DIR__, 5) . '/vendor/autoload.php';
        $script = <<<'PHP'
require $argv[1];
$targets = new \Qualimetrix\Infrastructure\Console\RunTarget\RunTargets(
    new \Qualimetrix\Infrastructure\Logging\LoggerFactory(),
);
$session = new \Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession(
    $targets,
    new \Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter(
        new \Qualimetrix\Infrastructure\Console\ErrorStream(),
    ),
);
$exit = $session->run(new \Symfony\Component\Console\Output\BufferedOutput(), 'json', static function () use ($targets, $argv): int {
    $targets->judge('--output', $argv[2]);
    $targets->claim();
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    if ($argv[3] === 'amp') {
        \Amp\delay(10);
    } else {
        sleep(10);
    }

    $targets->write('--output', 'complete');

    return 0;
});
exit($exit);
PHP;
        $process = proc_open([\PHP_BINARY, '-r', $script, $autoload, $target, $await ? 'amp' : 'sequential'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 3);
        try {
            self::assertSame("READY\n", fgets($pipes[1]));
            self::assertTrue(proc_terminate($process, $signal));
            $exit = proc_close($process);
            self::assertContains($exit, [128 + $signal, $signal]);
            self::assertFileDoesNotExist($target);
            self::assertSame([], array_values(array_diff((array) scandir($directory), ['.', '..'])));
        } finally {
            if (\is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            if (\is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (\is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
            if (is_file($target)) {
                unlink($target);
            }
            foreach (array_diff((array) scandir($directory), ['.', '..']) as $entry) {
                unlink($directory . '/' . $entry);
            }
            rmdir($directory);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function workerSignals(): iterable
    {
        yield 'worker SIGTERM' => [\SIGTERM];
        yield 'worker SIGINT' => [\SIGINT];
    }

    #[Test]
    #[DataProvider('workerSignals')]
    public function itDoesNotPublishAfterARealAmpWorkerWasInterrupted(int $signal): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('Fork workers are unavailable on this host.');
        }
        $directory = sys_get_temp_dir() . '/qmx-session-worker-' . bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory . '/SourceA.php', '<?php final class SourceA {}');
        file_put_contents($directory . '/SourceB.php', '<?php final class SourceB {}');
        $target = $directory . '/report.json';
        $readyA = $directory . '/SourceA.php.ready';
        $readyB = $directory . '/SourceB.php.ready';
        file_put_contents($target, 'OLD');
        $autoload = \dirname(__DIR__, 5) . '/vendor/autoload.php';
        $script = <<<'PHP'
require $argv[1];
$factory = new \Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory(
    new \Qualimetrix\Analysis\Evidence\Cohesion\Runtime\LcomCollectionConfigurationStore(),
    new \Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDocumentForms(),
    \Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor::class,
    [\Qualimetrix\Tests\Infrastructure\Console\Unit\RunTarget\SignalWorkerCollector::class],
);
$strategy = new \Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy($factory);
$strategy->setMinFilesForParallel(1);
$strategy->setWorkerCount(2);
$strategy->setProjectRoot(\Qualimetrix\Core\Path\AbsolutePath::fromString($argv[3]));
$targets = new \Qualimetrix\Infrastructure\Console\RunTarget\RunTargets(new \Qualimetrix\Infrastructure\Logging\LoggerFactory());
$session = new \Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession(
    $targets,
    new \Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter(new \Qualimetrix\Infrastructure\Console\ErrorStream()),
);
$exit = $session->run(new \Symfony\Component\Console\Output\BufferedOutput(), 'json', static function () use ($targets, $strategy, $argv): int {
    $targets->judge('--output', $argv[2]);
    $targets->claim();
    $strategy->execute(
        [new \SplFileInfo($argv[3] . '/SourceA.php'), new \SplFileInfo($argv[3] . '/SourceB.php')],
        static fn(): never => throw new \LogicException('Parallel execution must not use the fallback.'),
    );
    $targets->write('--output', 'NEW');

    return 0;
});
exit($exit);
PHP;
        $process = proc_open([\PHP_BINARY, '-r', $script, $autoload, $target, $directory], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + 6;
            while ((!is_file($readyA) || !is_file($readyB)) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFileExists($readyA);
            self::assertFileExists($readyB);
            $workerA = (int) file_get_contents($readyA);
            $workerB = (int) file_get_contents($readyB);
            self::assertGreaterThan(0, $workerA);
            self::assertGreaterThan(0, $workerB);
            self::assertNotSame($workerA, $workerB);
            self::assertNotSame(proc_get_status($process)['pid'], $workerA);
            self::assertNotSame(proc_get_status($process)['pid'], $workerB);
            self::assertTrue(proc_terminate($process, $signal));
            $deadline = microtime(true) + 2;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::assertFalse($status['running'], 'The interrupted worker run waited for unreleased workers.');
            if (\function_exists('posix_kill')) {
                self::assertFalse(posix_kill($workerA, 0), 'The first interrupted worker remains alive.');
                self::assertFalse(posix_kill($workerB, 0), 'The second interrupted worker remains alive.');
            }
            self::assertSame('OLD', file_get_contents($target));
            self::assertSame(['SourceA.php', 'SourceA.php.ready', 'SourceB.php', 'SourceB.php.ready', 'report.json'], array_values(array_diff((array) scandir($directory), ['.', '..'])));
            self::assertSame(128 + $signal, $status['exitcode'], stream_get_contents($pipes[2]));
        } finally {
            file_put_contents($directory . '/release-workers', 'released');
            if (\is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            foreach (array_diff((array) scandir($directory), ['.', '..']) as $entry) {
                unlink($directory . '/' . $entry);
            }
            rmdir($directory);
        }
    }

    #[Test]
    public function itClassifiesAnUnexpectedCleanupArgumentFailureAsInternal(): void
    {
        $factory = self::createMock(LoggerFactoryInterface::class);
        $factory->expects(self::once())
            ->method('reset')
            ->willThrowException(new InvalidArgumentException('logger reset failed'));
        $session = new RunTargetSession(new RunTargets($factory), new RefusalPresenter(new ErrorStream()));
        $output = new BufferedOutput();

        $exit = $session->run($output, 'json', static fn(): int => 0);

        self::assertSame(5, $exit);
        /** @var array{error: string, exit_code: int} $envelope */
        $envelope = json_decode($output->fetch(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(5, $envelope['exit_code']);
        self::assertStringContainsString('Internal error:', $envelope['error']);
        self::assertStringContainsString('logger reset failed', $envelope['error']);
    }
}
