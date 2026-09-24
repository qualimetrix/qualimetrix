<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * A file an option names for a command to write its artifact to — the
 * `check` report, the profile export, the exported graph.
 *
 * The write turns on one question, whether the target exists, and never on
 * what kind of thing it is. An existing target is opened by the path as
 * written and written in place, as `>` in a shell does, so whatever stands
 * there — a file, a hard link, a file mounted on its own, a device, a named
 * pipe, the file a symbolic link names — stays the same object with the same
 * owner. The price is that a write failing midway leaves that file partly
 * written; nothing promises a reader the old artifact or the new one whole.
 * A name nothing stands at yet is created: a chain of symbolic links is
 * followed to the name it ends at, and the file is written beside that name
 * and renamed onto it, so a failed write leaves no file behind.
 *
 * The one exception is closed: `/dev/stdout`, `/dev/stderr`, `/dev/fd/N` and
 * `/proc/self/fd/N`, written or reached through a link, are written through
 * the descriptor itself. On Linux PHP opens them by resolving the path, which
 * fails when the descriptor is a pipe and reopens, truncating, the file it is
 * redirected to.
 *
 * The precheck asks what the write needs and nothing else: an existing target
 * that is writable and not a directory, or a new name whose directory can be
 * written, or a descriptor the process holds.
 */
final readonly class ArtifactFile
{
    /** More links than this in one chain is a loop, as the kernel's own limit treats it. */
    private const int MAX_LINKS = 40;

    private const string DESCRIPTOR = '~^(?:/dev/fd|/proc/self/fd)/(\d+)$~';

    private const array STANDARD_STREAMS = ['/dev/stdout' => 1, '/dev/stderr' => 2];

    /**
     * @param string $option the option that named the file, as refusals quote it (`--output`)
     */
    public function __construct(
        public string $path,
        private string $option,
    ) {}

    /**
     * Refuses, before any work, a target {@see self::write()} cannot write: a
     * directory or a name ending in `/`, an existing target that cannot be
     * written, a new name in a directory that does not exist or cannot be
     * written, or a descriptor the process does not hold. A fast precheck,
     * not a guarantee — writability can change before the write, which
     * `write()` refuses on its own.
     */
    public function refuseUnwritable(): void
    {
        $this->destination();
    }

    /**
     * @throws ConfigurationRefusal when the artifact cannot be written whole
     */
    public function write(string $content): void
    {
        [$destination, $create] = $this->destination();

        if (!$create) {
            if (@file_put_contents($destination, $content) !== \strlen($content)) {
                throw $this->refusal(\sprintf('Failed to write the %s file %s', $this->option, $this->path));
            }

            return;
        }

        $temporary = $destination . '.tmp.' . getmypid();

        if (@file_put_contents($temporary, $content) !== \strlen($content)) {
            $this->discard($temporary);

            throw $this->refusal(\sprintf('Failed to write the %s file to temporary file %s', $this->option, $temporary));
        }

        if (!@rename($temporary, $destination)) {
            $this->discard($temporary);

            throw $this->refusal(\sprintf('Failed to rename the %s file %s to %s', $this->option, $temporary, $destination));
        }
    }

    /**
     * Where the artifact is written, and whether it is created there.
     *
     * @return array{string, bool}
     */
    private function destination(): array
    {
        clearstatcache();

        if (str_ends_with($this->path, '/') || is_dir($this->path)) {
            throw $this->directory($this->path);
        }

        $descriptor = $this->descriptor();
        if ($descriptor !== null) {
            return [$this->heldStream($descriptor), false];
        }

        if (file_exists($this->path)) {
            if (!is_writable($this->path)) {
                throw $this->refusal(\sprintf('Option %s names "%s", which exists and is not writable.', $this->option, $this->path));
            }

            return [$this->path, false];
        }

        return [$this->nameToCreate(), true];
    }

    /** The stream of a descriptor this process holds open. */
    private function heldStream(int $descriptor): string
    {
        $stream = 'php://fd/' . $descriptor;
        $handle = @fopen($stream, 'w');
        if ($handle === false) {
            throw $this->refusal(\sprintf(
                'Option %s names "%s", descriptor %d, which this process does not hold open.',
                $this->option,
                $this->path,
                $descriptor,
            ));
        }
        fclose($handle);

        return $stream;
    }

    /** The name a new artifact is created at, in a directory that can be written. */
    private function nameToCreate(): string
    {
        $target = $this->linkTarget();
        if (str_ends_with($target, '/')) {
            throw $this->directory($target);
        }

        $directory = \dirname($target);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw $this->refusal(\sprintf(
                'Option %s names "%s", whose directory "%s" does not exist or is not writable.',
                $this->option,
                $this->path,
                $directory,
            ));
        }

        return $target;
    }

    /** The descriptor the path names, written or at any link of its chain. */
    private function descriptor(): ?int
    {
        $path = $this->path;
        for ($links = 0; $links <= self::MAX_LINKS; ++$links) {
            if (isset(self::STANDARD_STREAMS[$path])) {
                return self::STANDARD_STREAMS[$path];
            }

            if (preg_match(self::DESCRIPTOR, $path, $match) === 1) {
                return (int) $match[1];
            }

            $link = is_link($path) ? readlink($path) : false;
            if ($link === false) {
                return null;
            }

            $path = str_starts_with($link, '/') ? $link : \dirname($path) . '/' . $link;
        }

        return null;
    }

    /** The name a chain of symbolic links ends at, which need not exist yet. */
    private function linkTarget(): string
    {
        $path = $this->path;
        for ($links = 0; is_link($path); ++$links) {
            $link = readlink($path);
            if ($link === false || $links === self::MAX_LINKS) {
                throw $this->refusal(\sprintf(
                    'Option %s names "%s", a symbolic link that does not resolve to a file name.',
                    $this->option,
                    $this->path,
                ));
            }

            $path = str_starts_with($link, '/') ? $link : \dirname($path) . '/' . $link;
        }

        return $path;
    }

    private function directory(string $name): ConfigurationRefusal
    {
        return $this->refusal($name === $this->path
            ? \sprintf('Option %s names "%s", which is a directory. Name a file to write to.', $this->option, $name)
            : \sprintf(
                'Option %s names "%s", a symbolic link to the directory name "%s". Name a file to write to.',
                $this->option,
                $this->path,
                $name,
            ));
    }

    private function discard(string $temporary): void
    {
        if (file_exists($temporary) && !is_dir($temporary)) {
            unlink($temporary);
        }
    }

    private function refusal(string $summary): ConfigurationRefusal
    {
        return ConfigurationRefusal::aboutCommandLineInput($this->option, $summary);
    }
}
