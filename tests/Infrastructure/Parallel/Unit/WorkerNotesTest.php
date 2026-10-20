<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit;

use Amp\ByteStream\ReadableResourceStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Terminal\Diagnostics\ErrorStream;
use Qualimetrix\Infrastructure\Parallel\WorkerNotes;
use ReflectionProperty;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

#[CoversClass(WorkerNotes::class)]
final class WorkerNotesTest extends TestCase
{
    #[Test]
    public function itDoesNotWaitForADeathNoteAfterTheWorkerStreamReachedEof(): void
    {
        $notes = new WorkerNotes(new ErrorStream());
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        $notes->captureStderr(new ReadableResourceStream($stream), 'nonce')->await();
        $start = hrtime(true);
        $keepAlive = \Revolt\EventLoop::delay(11, static function (): void {});
        try {
            self::assertNull($notes->deathFor('/project/Killed.php'));
            self::assertLessThan(500_000_000, hrtime(true) - $start);
        } finally {
            \Revolt\EventLoop::cancel($keepAlive);
            $notes->close();
        }
    }

    #[Test]
    public function itUsesOnlyTheNamedNonceChannelAndKeepsAllWorkerOutputOffThePayload(): void
    {
        $stderr = new BufferedOutput();
        $output = self::createStub(ConsoleOutputInterface::class);
        $output->method('getErrorOutput')->willReturn($stderr);
        $owner = new ErrorStream();
        $owner->writer($output);
        $notes = new WorkerNotes($owner);
        $death = ['kind' => 'death', 'file' => '/project/Real.php', 'message' => 'Allowed memory size of 1 bytes exhausted', 'limit' => '48M'];
        $foreign = 'QMX-WORKER-NOTE foreign ' . base64_encode(json_encode($death, \JSON_THROW_ON_ERROR));
        $notes->stderrLine('trusted', $foreign);
        $notes->stderrLine('trusted', 'QMX-WORKER-NOTE misleading warning');
        $notes->stdoutLine('payload bytes');
        self::assertSame([], (new ReflectionProperty($notes, 'deaths'))->getValue($notes));
        $notes->stderrLine('trusted', 'QMX-WORKER-NOTE trusted ' . base64_encode(json_encode($death, \JSON_THROW_ON_ERROR)));
        self::assertStringContainsString('Allowed memory size', $notes->deathFor('/project/Real.php') ?? '');
        $notes->stderrLine('trusted', 'QMX-WORKER-NOTE trusted ' . base64_encode(json_encode(['kind' => 'diagnostic', 'file' => '/collector.php', 'line' => 7, 'message' => 'visible warning', 'severity' => 'Warning'], \JSON_THROW_ON_ERROR)));
        $text = $stderr->fetch();
        self::assertStringContainsString('worker: ' . $foreign, $text);
        self::assertStringContainsString('worker: QMX-WORKER-NOTE misleading warning', $text);
        self::assertStringContainsString('worker: payload bytes', $text);
        self::assertStringContainsString('PHP Warning:  visible warning in /collector.php on line 7', $text);
        self::assertStringNotContainsString('QMX-WORKER-NOTE trusted ', $text);
        $notes->close();
    }
}
