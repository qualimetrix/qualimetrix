<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Parallel\WorkerContextFactory;
use Qualimetrix\Infrastructure\Parallel\WorkerNotes;
use Qualimetrix\Infrastructure\Parallel\WorkerRequest;
use ReflectionProperty;

#[CoversClass(WorkerContextFactory::class)]
final class WorkerContextFactoryTest extends TestCase
{
    #[Test]
    public function itStartsOnlyAProcessWithMergedEnvironmentParseIniAndCapturedStreams(): void
    {
        $script = sys_get_temp_dir() . '/qmx-context-script-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($script, <<<'PHP'
<?php
return static function (): array {
    echo "payload poison\n";
    fwrite(STDERR, "QMX-WORKER-NOTE foreign untrusted\nraw line two\n");
    return [getenv('PATH'), getenv('QMX_CONTEXT_TEST_MARKER'), getenv('QMX_WORKER_NOTE_NONCE'), ini_get('short_open_tag'), ini_get('zend.multibyte'), ini_get('zend.script_encoding'), AMP_CONTEXT, getenv('PHP_INI_SCAN_DIR')];
};
PHP);
        $coordinator = <<<'PHP'
require $argv[1];
$owner = new \Qualimetrix\Infrastructure\Console\ErrorStream();
$owner->writer(new \Symfony\Component\Console\Output\ConsoleOutput());
$notes = new \Qualimetrix\Infrastructure\Parallel\WorkerNotes($owner);
$factory = new \Qualimetrix\Infrastructure\Parallel\WorkerContextFactory(new \Qualimetrix\Infrastructure\Parallel\WorkerRequest(2), $notes, [PHP_BINARY, '-d', 'short_open_tag=0']);
$directory = (new \ReflectionProperty($factory, 'directory'))->getValue($factory);
try {
    $context = $factory->start($argv[2]);
    $result = $context->join();
} finally {
    $factory->close();
}
echo json_encode(['worker' => $result, 'expected' => [getenv('PATH'), getenv('QMX_CONTEXT_TEST_MARKER'), ini_get('short_open_tag'), ini_get('zend.multibyte'), ini_get('zend.script_encoding')], 'closed' => !is_dir($directory)], JSON_THROW_ON_ERROR);
PHP;
        $scanDirectory = getenv('PHP_INI_SCAN_DIR');
        $scanDirectory = ($scanDirectory === false ? \PHP_CONFIG_FILE_SCAN_DIR : $scanDirectory) . \PATH_SEPARATOR . '/absent-qmx-ini-scan';
        try {
            $process = new \Symfony\Component\Process\Process([\PHP_BINARY, '-d', 'short_open_tag=1', '-r', $coordinator, \dirname(__DIR__, 4) . '/vendor/autoload.php', $script], env: ['QMX_CONTEXT_TEST_MARKER' => 'carried-value', 'PHP_INI_SCAN_DIR' => $scanDirectory]);
            $process->mustRun();
            $record = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame($record['expected'][0], $record['worker'][0]);
            self::assertSame('carried-value', $record['worker'][1]);
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $record['worker'][2]);
            self::assertSame('1', $record['worker'][3]);
            self::assertSame($record['expected'][3], $record['worker'][4]);
            self::assertSame($record['expected'][4], $record['worker'][5]);
            self::assertSame('process', $record['worker'][6]);
            self::assertSame($scanDirectory, $record['worker'][7]);
            self::assertTrue($record['closed']);
            $text = $process->getErrorOutput();
            self::assertStringContainsString('worker: payload poison', $text);
            self::assertStringContainsString('worker: QMX-WORKER-NOTE foreign untrusted', $text);
            self::assertStringContainsString('worker: raw line two', $text);
        } finally {
            unlink($script);
        }
    }

    #[Test]
    public function itBoundsStartupByTheBindingDeadlineAndCapturesTheOwnedPhpLog(): void
    {
        $prepend = sys_get_temp_dir() . '/qmx-start-fatal-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($prepend, '<?php missing_worker_startup_function();');
        $notes = new WorkerNotes(new ErrorStream());
        $factory = new WorkerContextFactory(new WorkerRequest(2, '--workers=2'), $notes, [\PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend]);
        $directory = (new ReflectionProperty($factory, 'directory'))->getValue($factory);
        try {
            $factory->start(\Amp\Parallel\Worker\ContextWorkerFactory::SCRIPT_PATH);
            self::fail('A process that died before connecting must refuse startup.');
        } catch (\Qualimetrix\Infrastructure\Parallel\WorkerStartFailure $failure) {
            self::assertStringContainsString('did not answer within 10 s', $failure->getMessage());
            self::assertStringContainsString('missing_worker_startup_function', $failure->getMessage());
            self::assertStringContainsString('--workers=2', $failure->getMessage());
        } finally {
            $factory->close();
            unlink($prepend);
        }
        self::assertDirectoryDoesNotExist($directory);
    }

    #[Test]
    public function itClassifiesAnUnavailableLocalIpcDirectoryAsWorkerStartupFailure(): void
    {
        if (!file_exists('/dev/null') || is_dir('/dev/null')) {
            self::markTestSkipped('This case requires a Unix device path as an invalid temp directory.');
        }

        $coordinator = <<<'PHP'
require $argv[1];
$request = new \Qualimetrix\Infrastructure\Parallel\WorkerRequest(2, '--workers=2');
$notes = new \Qualimetrix\Infrastructure\Parallel\WorkerNotes(new \Qualimetrix\Infrastructure\Console\ErrorStream());
try {
    new \Qualimetrix\Infrastructure\Parallel\WorkerContextFactory($request, $notes);
} catch (\Qualimetrix\Infrastructure\Parallel\WorkerStartFailure $failure) {
    echo $failure->getMessage();
    exit(3);
}
exit(0);
PHP;
        $process = new \Symfony\Component\Process\Process([\PHP_BINARY, '-d', 'sys_temp_dir=/dev/null', '-r', $coordinator, \dirname(__DIR__, 4) . '/vendor/autoload.php']);
        $process->run();
        self::assertSame(3, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('cannot open local worker IPC', $process->getOutput());
        self::assertStringContainsString('--workers=2', $process->getOutput());
        self::assertStringContainsString('--workers=0', $process->getOutput());
        self::assertSame('', $process->getErrorOutput());
    }

    #[Test]
    public function itDoesNotInheritTheCoordinatorsHeldTargetDescriptor(): void
    {
        if (!is_dir('/proc/self/fd')) {
            self::markTestSkipped('Kernel descriptor inspection requires /proc/self/fd.');
        }
        $directory = sys_get_temp_dir() . '/qmx-context-held-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $target = $directory . '/held-report.json';
        $held = \Qualimetrix\Core\FileTarget\HeldTarget::claim(\Qualimetrix\Core\FileTarget\TargetPath::resolve($target));
        $script = $directory . '/inspect.php';
        file_put_contents($script, <<<'PHP'
<?php
return static function (): array {
    $paths = glob('/proc/self/fd/*');
    $result = [];
    foreach ($paths === false ? [] : $paths as $path) {
        $name = @readlink($path);
        if ($name !== false) $result[] = $name;
    }
    return $result;
};
PHP);
        $notes = new WorkerNotes(new ErrorStream());
        $factory = new WorkerContextFactory(new WorkerRequest(2), $notes);
        try {
            $context = $factory->start($script);
            $descriptors = $context->join();
            self::assertNotEmpty($descriptors);
            self::assertNotContains($target, $descriptors);
        } finally {
            $factory->close();
            $held->release();
            unlink($script);
            if (is_file($target)) {
                unlink($target);
            }
            rmdir($directory);
        }
    }

}
