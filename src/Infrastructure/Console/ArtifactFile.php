<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * A file an option names for a command to write its artifact to — the
 * `check` report, the profile export, the exported graph.
 *
 * A regular file, or a name nothing stands at yet, is written to a temporary
 * file beside it and renamed over it, so a reader never sees half an
 * artifact; a replaced file keeps its permissions. A symbolic link is
 * followed to the file it names and the link is left in place. Anything else
 * that exists — a device, a named pipe, a descriptor such as `/dev/stdout` —
 * is written in place: renaming over it would replace the thing the caller
 * named with a file nobody reads.
 *
 * The precheck and the write share one model of the target, because the
 * precheck is only as good as its model of the write: asking whether the
 * *target* is writable passed a directory and a writable file in a sealed
 * directory, and both then failed after the whole analysis had run.
 */
final readonly class ArtifactFile
{
    /** More links than this in one chain is a loop, as the kernel's own limit treats it. */
    private const int MAX_LINKS = 40;

    /**
     * @param string $option the option that named the file, as refusals quote it (`--output`)
     */
    public function __construct(
        public string $path,
        private string $option,
    ) {}

    /**
     * Refuses, before any work, a target {@see self::write()} cannot write: a
     * directory or a name ending in `/`, a file in a directory that does not
     * exist or cannot be written, or an existing target that cannot be
     * written. A fast precheck, not a guarantee — writability can change
     * before the write, which `write()` refuses on its own.
     */
    public function refuseUnwritable(): void
    {
        $this->destination();
    }

    /**
     * @throws ConfigurationRefusal when the artifact cannot be written
     */
    public function write(string $content): void
    {
        [$destination, $replaceWhole] = $this->destination();

        if (!$replaceWhole) {
            if (@file_put_contents($destination, $content) !== \strlen($content)) {
                throw $this->refusal(\sprintf('Failed to write the %s file %s', $this->option, $destination));
            }

            return;
        }

        $temporary = $destination . '.tmp.' . getmypid();

        // The permissions go on before the content, so a file kept private is
        // never readable under the temporary name.
        if (@file_put_contents($temporary, '') === false
            || (file_exists($destination) && !chmod($temporary, fileperms($destination) & 0o7777))
            || @file_put_contents($temporary, $content) === false
        ) {
            $this->discard($temporary);

            throw $this->refusal(\sprintf('Failed to write the %s file to temporary file %s', $this->option, $temporary));
        }

        if (!@rename($temporary, $destination)) {
            $this->discard($temporary);

            throw $this->refusal(\sprintf('Failed to rename the %s file %s to %s', $this->option, $temporary, $destination));
        }
    }

    /**
     * Where the artifact is written, and whether it is replaced whole there.
     *
     * @return array{string, bool}
     */
    private function destination(): array
    {
        clearstatcache();

        if (str_ends_with($this->path, '/') || is_dir($this->path)) {
            throw $this->refusal(\sprintf(
                'Option %s names "%s", which is a directory. Name a file to write to.',
                $this->option,
                $this->path,
            ));
        }

        if (file_exists($this->path)) {
            return $this->existingDestination();
        }

        $target = $this->linkTarget();
        $directory = \dirname($target);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw $this->unwritable($directory);
        }

        return [$target, true];
    }

    /** @return array{string, bool} */
    private function existingDestination(): array
    {
        if (!is_writable($this->path)) {
            throw $this->unwritable(\dirname($this->path));
        }

        $real = realpath($this->path);
        $real = $real === false ? $this->path : $real;

        // A regular file under /dev is a descriptor the process already holds:
        // under `> report.json`, `/dev/stdout` stats as that file — reached as
        // `/dev/fd/1` on macOS, as the file's own path on Linux — and renaming
        // over it would detach the report from the stream it was written to.
        if (!is_file($this->path) || str_starts_with($this->path, '/dev/') || str_starts_with($real, '/dev/')) {
            return [$this->path, false];
        }

        if (!is_writable(\dirname($real))) {
            throw $this->unwritable(\dirname($real));
        }

        return [$real, true];
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

    private function unwritable(string $directory): ConfigurationRefusal
    {
        return $this->refusal(\sprintf(
            'Option %s names "%s", which is not writable, or whose directory "%s" does not exist or is not writable.',
            $this->option,
            $this->path,
            $directory,
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
