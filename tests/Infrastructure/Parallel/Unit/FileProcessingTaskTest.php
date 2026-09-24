<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit;

use Amp\NullCancellation;
use Amp\Sync\Channel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTask;
use Qualimetrix\Infrastructure\Parallel\WorkerBootstrap;
use Qualimetrix\Infrastructure\Parallel\WorkerComposition;

#[CoversClass(FileProcessingTask::class)]
final class FileProcessingTaskTest extends TestCase
{
    private string $directory;

    private string $previousLimit;

    protected function setUp(): void
    {
        WorkerBootstrap::reset();
        $this->previousLimit = (string) \ini_get('memory_limit');
        $this->directory = sys_get_temp_dir() . '/qmx-task-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory . '/Subject.php', "<?php\nfinal class Subject { public function a(): int { return 1; } }\n");
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->previousLimit);
        WorkerBootstrap::reset();
        @unlink($this->directory . '/Subject.php');
        @rmdir($this->directory);
    }

    /**
     * A worker is a separate process, so a limit the coordinator set does not
     * reach it on its own; the task is what carries it across.
     */
    #[Test]
    public function itRunsUnderTheMemoryLimitItCarries(): void
    {
        ini_set('memory_limit', '256M');

        $this->task('300M')->run(self::createStub(Channel::class), new NullCancellation());

        self::assertSame('300M', \ini_get('memory_limit'));
    }

    private function task(string $memoryLimit): FileProcessingTask
    {
        return new FileProcessingTask(
            filePath: AbsolutePath::fromString($this->directory . '/Subject.php'),
            projectRoot: AbsolutePath::fromString($this->directory),
            composition: new WorkerComposition([CyclomaticComplexityCollector::class], DependencyVisitor::class),
            memoryLimit: $memoryLimit,
        );
    }
}
