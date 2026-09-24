<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * A file an option names for a command to write its artifact to — the
 * `check` report, the profile export, the exported graph.
 *
 * The write is `>` in a shell as nearly as PHP allows, and the kernel, not
 * this class, decides what a path leads to. A target the kernel reaches is
 * opened by the path as written and written in place, so a file, a hard
 * link, a file mounted on its own, a device, a named pipe or the file a link
 * names stays the same object. A name the kernel reaches nothing at is
 * created by `touch()`, whose open the kernel resolves: a dangling link
 * creates the file it names, and a link the kernel refuses to follow
 * (`fs.protected_symlinks`) is refused. Every other PHP open resolves links
 * itself, beyond that rule's reach. A write that fails midway removes a
 * file it created and leaves an existing one partly written.
 *
 * `/dev/stdout`, `/dev/stderr`, `/dev/fd/N` and `/proc/self/fd/N`, spelled
 * exactly so, are written through the descriptor: PHP on Linux cannot open
 * a pipe by its path and reopens, truncating, a file the stream is
 * redirected to. Another spelling of the same stream is opened by its path.
 *
 * The precheck asks the kernel what it can without writing: not a directory,
 * a reachable target writable, a new name's directory writable and
 * searchable, a descriptor held and, where the system publishes it, open for
 * writing. A link the kernel does not follow to a file is left to the write,
 * which is the final judge.
 */
final readonly class ArtifactFile
{
    private const string DESCRIPTOR = '~^/(?:dev/fd|proc/self/fd)/(\d+)$~';

    private const array STANDARD_STREAMS = ['/dev/stdout' => 1, '/dev/stderr' => 2];

    private const string STREAM_SPELLINGS = '/dev/stdout, /dev/stderr, /dev/fd/N or /proc/self/fd/N';

    /** The access-mode bits of a descriptor's open flags; zero is read-only. */
    private const int ACCESS_MODE = 0b11;

    /**
     * @param string $option the option that named the file, as refusals quote it (`--output`)
     */
    public function __construct(
        public string $path,
        private string $option,
    ) {}

    /**
     * Refuses, before any work, a target the kernel already says the write
     * cannot take: a directory or a name ending in `/`, an existing target
     * that cannot be written, a new name in a directory that does not exist
     * or cannot be written and searched, or a descriptor the process does not
     * hold or holds only for reading. A best-effort precheck, not a guarantee:
     * {@see self::write()} refuses what it could not see.
     */
    public function refuseUnwritable(): void
    {
        clearstatcache();
        $this->refuseDirectory();

        $descriptor = $this->descriptor();
        if ($descriptor !== null) {
            $this->refuseUnwritableDescriptor($descriptor);

            return;
        }

        if (file_exists($this->path)) {
            if (!is_writable($this->path)) {
                throw $this->refusal(\sprintf('Option %s names "%s", which exists and is not writable.', $this->option, $this->path));
            }

            return;
        }

        if (is_link($this->path)) {
            return;
        }

        $directory = \dirname($this->path);
        if (!is_dir($directory) || !is_writable($directory) || !is_executable($directory)) {
            throw $this->refusal(\sprintf(
                'Option %s names "%s", whose directory "%s" does not exist or does not allow creating a file.',
                $this->option,
                $this->path,
                $directory,
            ));
        }
    }

    /**
     * @throws ConfigurationRefusal when the artifact cannot be written whole
     */
    public function write(string $content): void
    {
        clearstatcache();
        $this->refuseDirectory();

        $descriptor = $this->descriptor();
        if ($descriptor !== null) {
            $this->put('php://fd/' . $descriptor, $content);

            return;
        }

        if (file_exists($this->path)) {
            $this->put($this->path, $content);

            return;
        }

        [$created, $reason] = self::attempt(fn(): bool => touch($this->path));
        if (!$created) {
            throw $this->unopenable('create', $reason);
        }

        try {
            $this->put($this->path, $content);
        } catch (ConfigurationRefusal $refusal) {
            // Through a link the created file is the link's target, which only the kernel names.
            if (!is_link($this->path)) {
                @unlink($this->path);
            }

            throw $refusal;
        }
    }

    private function put(string $stream, string $content): void
    {
        [$handle, $reason] = self::attempt(static fn() => fopen($stream, 'w'));
        if ($handle === false) {
            throw $this->unopenable('open', $reason);
        }

        // A descriptor shares non-blocking mode with every copy of it, the one a parent handed over included.
        stream_set_blocking($handle, true);
        [$written] = self::attempt(static fn() => fwrite($handle, $content));
        [$closed] = self::attempt(static fn(): bool => fclose($handle));

        if ($written !== \strlen($content) || !$closed) {
            throw $this->refusal(\sprintf('Failed to write the %s file %s', $this->option, $this->path));
        }
    }

    private function refuseDirectory(): void
    {
        if (str_ends_with($this->path, '/') || is_dir($this->path)) {
            throw $this->refusal(\sprintf('Option %s names "%s", which is a directory. Name a file to write to.', $this->option, $this->path));
        }
    }

    private function refuseUnwritableDescriptor(int $descriptor): void
    {
        $handle = @fopen('php://fd/' . $descriptor, 'w');
        if ($handle === false) {
            throw $this->refusal(\sprintf(
                'Option %s names "%s", descriptor %d, which this process does not hold open.',
                $this->option,
                $this->path,
                $descriptor,
            ));
        }
        fclose($handle);

        $flags = self::openFlags($descriptor);
        if ($flags !== null && ($flags & self::ACCESS_MODE) === 0) {
            throw $this->refusal(\sprintf(
                'Option %s names "%s", descriptor %d, which this process holds open only for reading.',
                $this->option,
                $this->path,
                $descriptor,
            ));
        }
    }

    /** The descriptor one of the supported spellings names, exactly as written. */
    private function descriptor(): ?int
    {
        if (isset(self::STANDARD_STREAMS[$this->path])) {
            return self::STANDARD_STREAMS[$this->path];
        }

        return preg_match(self::DESCRIPTOR, $this->path, $match) === 1 ? (int) $match[1] : null;
    }

    /** A descriptor's open flags where the system publishes them (Linux), or null. */
    private static function openFlags(int $descriptor): ?int
    {
        $info = @file_get_contents('/proc/self/fdinfo/' . $descriptor);
        if ($info === false || preg_match('~^flags:\s+([0-7]+)$~m', $info, $match) !== 1) {
            return null;
        }

        return (int) octdec($match[1]);
    }

    /**
     * Runs a filesystem call and keeps the system's reason for a failure, as
     * PHP words it in the warning, instead of letting the warning out.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return array{T, string}
     */
    private static function attempt(callable $operation): array
    {
        $reason = 'unknown reason';
        set_error_handler(static function (int $level, string $message) use (&$reason): bool {
            $reason = preg_match('~^.*(?: because |: )(.+)$~s', $message, $match) === 1 ? $match[1] : $message;

            return true;
        });

        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return [$result, $reason];
    }

    /**
     * Names the supported spellings of a stream, since another spelling of
     * one fails exactly here — whatever the reason, which the class does not
     * sort.
     */
    private function unopenable(string $verb, string $reason): ConfigurationRefusal
    {
        return $this->refusal(\sprintf(
            'Failed to %s the %s file %s: %s. To write to a stream of this process, name it %s.',
            $verb,
            $this->option,
            $this->path,
            $reason,
            self::STREAM_SPELLINGS,
        ));
    }

    private function refusal(string $summary): ConfigurationRefusal
    {
        return ConfigurationRefusal::aboutCommandLineInput($this->option, $summary);
    }
}
