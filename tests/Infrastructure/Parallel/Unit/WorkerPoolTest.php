<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit;

use Amp\Parallel\Worker\TaskFailureException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qualimetrix\Infrastructure\Console\Terminal\ErrorStream;
use Qualimetrix\Infrastructure\Parallel\WorkerContextFactory;
use Qualimetrix\Infrastructure\Parallel\WorkerNotes;
use Qualimetrix\Infrastructure\Parallel\WorkerPool;
use Qualimetrix\Infrastructure\Parallel\WorkerRequest;
use RuntimeException;
use Symfony\Component\Process\Process;

#[CoversClass(WorkerPool::class)]
#[CoversClass(WorkerContextFactory::class)]
final class WorkerPoolTest extends TestCase
{
    #[Test]
    public function itPublishesTheOriginalTaskMessageWithoutTheTransportTrace(): void
    {
        $notes = new WorkerNotes(new ErrorStream());
        $contexts = new WorkerContextFactory(new WorkerRequest(2), $notes);
        $pool = WorkerPool::open(2, new NullLogger(), $contexts, $notes);
        try {
            $failure = new TaskFailureException(RuntimeException::class, 'task-original-cause', 0, __FILE__, 1, []);
            self::assertSame('task-original-cause', $pool->failureMessage($failure, __FILE__));
        } finally {
            $pool->close();
        }
    }

    #[Test]
    public function itOverlapsTheFactoryStartupDeadlineWithTheCancelledProbeDrain(): void
    {
        $factoryPrepend = sys_get_temp_dir() . '/qmx-start-fatal-' . bin2hex(random_bytes(6)) . '.php';
        $poolPrepend = sys_get_temp_dir() . '/qmx-probe-fatal-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($factoryPrepend, '<?php missing_worker_startup_function();');
        file_put_contents($poolPrepend, '<?php probe_startup_missing_function();');
        $factoryScript = <<<'PHP'
require $argv[1];
$request = new \Qualimetrix\Infrastructure\Parallel\WorkerRequest(2, '--workers=2');
$notes = new \Qualimetrix\Infrastructure\Parallel\WorkerNotes(new \Qualimetrix\Infrastructure\Console\Terminal\ErrorStream());
$factory = new \Qualimetrix\Infrastructure\Parallel\WorkerContextFactory($request, $notes, [PHP_BINARY, '-d', 'auto_prepend_file=' . $argv[2]]);
$directory = (new \ReflectionProperty($factory, 'directory'))->getValue($factory);
$record = ['code' => 0, 'message' => '', 'closed' => false];
try {
    $factory->start(\Amp\Parallel\Worker\ContextWorkerFactory::SCRIPT_PATH);
} catch (\Qualimetrix\Infrastructure\Parallel\WorkerStartFailure $failure) {
    $record['code'] = 3;
    $record['message'] = $failure->getMessage();
} finally {
    $factory->close();
    $record['closed'] = !is_dir($directory);
}
echo json_encode($record, JSON_THROW_ON_ERROR);
exit($record['code']);
PHP;
        $poolScript = <<<'PHP'
require $argv[1];
$request = new \Qualimetrix\Infrastructure\Parallel\WorkerRequest(2, '--workers=2');
$notes = new \Qualimetrix\Infrastructure\Parallel\WorkerNotes(new \Qualimetrix\Infrastructure\Console\Terminal\ErrorStream());
$contexts = new \Qualimetrix\Infrastructure\Parallel\WorkerContextFactory($request, $notes, [PHP_BINARY, '-d', 'auto_prepend_file=' . $argv[2]]);
$pool = \Qualimetrix\Infrastructure\Parallel\WorkerPool::open(2, new \Psr\Log\NullLogger(), $contexts, $notes);
$factory = new \Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory(new \Qualimetrix\Analysis\Evidence\Cohesion\Runtime\LcomCollectionConfigurationStore(),new \Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDocumentForms(),\Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor::class,[\Qualimetrix\Analysis\Evidence\Size\LocCollector::class]);
$directory = (new \ReflectionProperty($contexts, 'directory'))->getValue($contexts);
$record = ['code' => 0, 'message' => '', 'closed' => false];
try {
    $pool->probe($factory->probe(\Qualimetrix\Core\Path\AbsolutePath::fromString(dirname($argv[2])),null),$request);
} catch (\Qualimetrix\Infrastructure\Parallel\WorkerStartFailure $failure) {
    $record['code'] = 3;
    $record['message'] = $failure->getMessage();
} finally {
    $pool->close();
    $record['closed'] = !is_dir($directory);
}
echo json_encode($record, JSON_THROW_ON_ERROR);
exit($record['code']);
PHP;
        try {
            $factoryProcess = new Process([\PHP_BINARY, '-r', $factoryScript, \dirname(__DIR__, 4) . '/vendor/autoload.php', $factoryPrepend]);
            $poolProcess = new Process([\PHP_BINARY, '-r', $poolScript, \dirname(__DIR__, 4) . '/vendor/autoload.php', $poolPrepend]);
            $factoryProcess->setTimeout(WorkerRequest::START_DEADLINE_SECONDS * 2);
            $poolProcess->setTimeout(WorkerRequest::START_DEADLINE_SECONDS * 2);
            $started = microtime(true);
            $factoryProcess->start();
            $poolProcess->start();
            $factoryProcess->wait();
            $poolProcess->wait();
            self::assertLessThan(WorkerRequest::START_DEADLINE_SECONDS * 2, microtime(true) - $started);

            self::assertSame(3, $factoryProcess->getExitCode(), $factoryProcess->getErrorOutput());
            $factoryRecord = json_decode($factoryProcess->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertStringContainsString('did not answer within 10 s', $factoryRecord['message']);
            self::assertStringContainsString('missing_worker_startup_function', $factoryRecord['message']);
            self::assertStringContainsString('--workers=2', $factoryRecord['message']);
            self::assertTrue($factoryRecord['closed']);

            self::assertSame(3, $poolProcess->getExitCode(), $poolProcess->getErrorOutput());
            $poolRecord = json_decode($poolProcess->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertStringContainsString('did not answer within 10 s', $poolRecord['message']);
            self::assertStringContainsString('probe_startup_missing_function', $poolRecord['message']);
            self::assertTrue($poolRecord['closed']);
            self::assertStringNotContainsString('Operation is no longer pending', $poolProcess->getErrorOutput());
        } finally {
            unlink($factoryPrepend);
            unlink($poolPrepend);
        }
    }
}
