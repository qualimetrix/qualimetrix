<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * A file an option names for a command to write its artifact to — the
 * `check` report, the profile export, the exported graph.
 *
 * The write goes to a temporary file beside the target and is renamed over
 * it, so a reader never sees half an artifact. The precheck and the write
 * live together because the precheck is only as good as its model of the
 * write: asking whether the *target* is writable passed a directory and a
 * writable file in a sealed directory, and both then failed after the whole
 * analysis had run.
 */
final readonly class ArtifactFile
{
    /**
     * @param string $option the option that named the file, as refusals quote it (`--output`)
     */
    public function __construct(
        public string $path,
        private string $option,
    ) {}

    /**
     * Refuses, before any work, a target {@see self::replaceWith()} cannot
     * write: a directory, a directory that does not exist or cannot be
     * written, or an existing file that cannot be written. A fast precheck,
     * not a guarantee — writability can change before the write, which
     * `replaceWith()` refuses on its own.
     */
    public function refuseUnwritable(): void
    {
        if (is_dir($this->path)) {
            throw $this->refusal(\sprintf(
                'Option %s names "%s", which is a directory. Name a file to write to.',
                $this->option,
                $this->path,
            ));
        }

        $directory = \dirname($this->path);
        if (!is_dir($directory) || !is_writable($directory) || (file_exists($this->path) && !is_writable($this->path))) {
            throw $this->refusal(\sprintf(
                'Option %s names "%s", which is not writable, or whose directory "%s" does not exist or is not writable.',
                $this->option,
                $this->path,
                $directory,
            ));
        }
    }

    /**
     * @throws ConfigurationRefusal when the artifact cannot be written
     */
    public function replaceWith(string $content): void
    {
        $temporary = $this->path . '.tmp.' . getmypid();

        if (@file_put_contents($temporary, $content) === false) {
            throw $this->refusal(\sprintf('Failed to write the %s file to temporary file %s', $this->option, $temporary));
        }

        if (!@rename($temporary, $this->path)) {
            if (file_exists($temporary) && !is_dir($temporary)) {
                unlink($temporary);
            }

            throw $this->refusal(\sprintf('Failed to rename the %s file %s to %s', $this->option, $temporary, $this->path));
        }
    }

    private function refusal(string $summary): ConfigurationRefusal
    {
        return ConfigurationRefusal::aboutCommandLineInput($this->option, $summary);
    }
}
