<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Support;

use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * A `ConsoleOutputInterface` whose two streams a test can read back.
 *
 * `ConsoleOutput` opens `php://stdout` and `php://stderr` in private methods,
 * so there is no way to hand it a pair of in-memory streams; and
 * `BufferedOutput` has one stream, which is precisely the distinction under
 * test. This double keeps the two apart and lets each be decorated
 * independently — the case that matters is a redirected stdout beside a live
 * stderr.
 */
final class SplitStreamConsoleOutput extends StreamOutput implements ConsoleOutputInterface
{
    /** @var array<int, ConsoleSectionOutput> */
    private array $sections = [];

    private OutputInterface $errorOutput;

    /** @var resource */
    private $errorStream;

    public function __construct(
        bool $stdoutDecorated = false,
        bool $stderrDecorated = true,
        int $verbosity = self::VERBOSITY_NORMAL,
    ) {
        $stdout = self::memoryStream();
        $this->errorStream = self::memoryStream();

        parent::__construct($stdout, $verbosity, $stdoutDecorated);
        $this->errorOutput = new StreamOutput($this->errorStream, $verbosity, $stderrDecorated);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errorOutput;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->errorOutput = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        return new ConsoleSectionOutput(
            $this->getStream(),
            $this->sections,
            $this->getVerbosity(),
            $this->isDecorated(),
            $this->getFormatter(),
        );
    }

    public function standardOutputContent(): string
    {
        return self::contentOf($this->getStream());
    }

    public function errorOutputContent(): string
    {
        return self::contentOf($this->errorStream);
    }

    /** @return resource */
    private static function memoryStream()
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot open an in-memory stream');
        }

        return $stream;
    }

    /** @param resource $stream */
    private static function contentOf($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
