<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\HeldTarget;
use RuntimeException;
use Stringable;

/** Writes eligible records as JSON lines after Console claims the log target. */
final class FileLogger extends AbstractLogger
{
    use LoggerHelperTrait;

    /** @var list<string> */
    private array $pending = [];

    private ?HeldTarget $target = null;
    private ?FileTargetFailure $failure = null;
    private int $lostRecords = 0;

    public function __construct(
        private readonly string $path,
        private readonly string $minLevel = LogLevel::DEBUG,
    ) {
        self::rank($minLevel);
    }

    public function attach(HeldTarget $target): void
    {
        $this->target = $target;
        foreach ($this->pending as $line) {
            $this->appendLine($line);
        }
        $this->pending = [];
    }

    /** @param array<string, mixed> $context */
    // @phpstan-ignore-next-line method.childParameterType
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!$this->meetsMinLevel($level, $this->minLevel)) {
            return;
        }

        if ($this->failure !== null) {
            ++$this->lostRecords;

            return;
        }

        $record = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $this->interpolate((string) $message, $context),
            'context' => $context,
        ];

        $line = self::encodeJson($record);
        if ($line === null) {
            $record['context'] = null;
            $record['context_error'] = json_last_error_msg();
            $line = self::encodeJson($record) ?? throw new RuntimeException('A log record without context must encode.');
        }
        $line .= "\n";

        if ($this->target === null) {
            $this->pending[] = $line;

            return;
        }

        $this->appendLine($line);
    }

    public function settle(): void
    {
        if ($this->failure === null) {
            return;
        }

        throw new FileTargetFailure(
            $this->failure->kind,
            $this->path,
            \sprintf('%s; %d log record(s) lost', $this->failure->reason, $this->lostRecords),
            $this->failure->detail,
        );
    }

    private function appendLine(string $line): void
    {
        if ($this->failure !== null) {
            ++$this->lostRecords;

            return;
        }

        try {
            ($this->target ?? throw new RuntimeException('Log target was not attached'))->append($line);
        } catch (FileTargetFailure $failure) {
            $this->failure = $failure;
            ++$this->lostRecords;
        }
    }
}
