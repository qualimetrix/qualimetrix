<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit;

use Amp\Parallel\Worker\TaskFailureException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Parallel\WorkerContextFactory;
use Qualimetrix\Infrastructure\Parallel\WorkerNotes;
use Qualimetrix\Infrastructure\Parallel\WorkerPool;
use Qualimetrix\Infrastructure\Parallel\WorkerRequest;
use RuntimeException;
use Symfony\Component\Process\Process;

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
    public function itDrainsACancelledProbeBeforeClosingItsIpcAndReturningTheProcessCode(): void
    {
        $prepend = sys_get_temp_dir() . '/qmx-probe-fatal-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($prepend, '<?php probe_startup_missing_function();');
        $script = <<<'PHP'
require $argv[1];
$request = new \Qualimetrix\Infrastructure\Parallel\WorkerRequest(2, '--workers=2');
$notes = new \Qualimetrix\Infrastructure\Parallel\WorkerNotes(new \Qualimetrix\Infrastructure\Console\ErrorStream());
$contexts = new \Qualimetrix\Infrastructure\Parallel\WorkerContextFactory($request, $notes, [PHP_BINARY, '-d', 'auto_prepend_file=' . $argv[2]]);
$pool = \Qualimetrix\Infrastructure\Parallel\WorkerPool::open(2, new \Psr\Log\NullLogger(), $contexts, $notes);
$factory = new \Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory(new \Qualimetrix\Analysis\Evidence\Cohesion\Runtime\LcomCollectionConfigurationStore(),new \Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms\RuleOptionDocumentForms(),\Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor::class,[\Qualimetrix\Analysis\Evidence\Size\LocCollector::class]);
try {
    $pool->probe($factory->probe(\Qualimetrix\Core\Path\AbsolutePath::fromString(dirname($argv[2])),null),$request);
    $code=0;
} catch (\Qualimetrix\Infrastructure\Parallel\WorkerStartFailure $failure) {
    echo json_encode(['message'=>$failure->getMessage()], JSON_THROW_ON_ERROR);
    $code=3;
} finally {
    $pool->close();
}
exit($code);
PHP;
        try {
            $process = new Process([\PHP_BINARY, '-r', $script, \dirname(__DIR__, 4) . '/vendor/autoload.php', $prepend]);
            $process->setTimeout(WorkerRequest::START_DEADLINE_SECONDS * 3);
            $process->run();
            self::assertSame(3, $process->getExitCode(), $process->getErrorOutput());
            $record = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertStringContainsString('did not answer within 10 s', $record['message']);
            self::assertStringContainsString('probe_startup_missing_function', $record['message']);
            self::assertStringNotContainsString('Operation is no longer pending', $process->getErrorOutput());
        } finally {
            unlink($prepend);
        }
    }
}
