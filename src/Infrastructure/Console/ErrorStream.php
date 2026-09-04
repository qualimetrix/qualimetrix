<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * The single owner of the run's error stream.
 *
 * Two things are written to that stream while a command runs: the progress
 * frame, which erases upwards by the height of its own section, and every
 * human diagnostic — the logger, a preflight warning, a report note, an
 * uncaught throwable. When the second kind reaches the stream outside the
 * section bookkeeping, the frame's next erase counts lines that are no longer
 * its own: the diagnostic is destroyed and the bar loses its place for the rest
 * of the run.
 *
 * The fix is not to silence either writer but to give them one owner.
 * `ConsoleSectionOutput` takes the section list **by reference** and keeps the
 * reference, so sections that share one list redraw around each other. This
 * class holds that list. The diagnostic section is created first and the
 * progress section second, which puts progress at the bottom of the screen:
 * writing a diagnostic then erases the frame, appends the line permanently and
 * reprints the frame underneath it.
 *
 * The stream also has exactly one fallback. Where the output has no error
 * stream of its own — a buffer, a `NullOutput`, an embedder's single-channel
 * output — diagnostics are dropped rather than folded into the payload:
 * `--format=json` promises a parsable stdout, and that promise is the reason
 * the two channels are separated at all.
 */
final class ErrorStream
{
    /**
     * Shared by every section this class hands out; the reference is what makes
     * them redraw around each other.
     *
     * @var array<int, ConsoleSectionOutput>
     */
    private array $sections = [];

    private ?OutputInterface $boundTo = null;

    private OutputInterface $diagnostics;

    /** The stream a progress section may be drawn on, or null when there is none. */
    private ?StreamOutput $sectionable = null;

    private ?ConsoleSectionOutput $progress = null;

    public function __construct()
    {
        $this->diagnostics = new NullOutput();
    }

    /** Drops the binding so the next run starts with an empty section list. */
    public function reset(): void
    {
        $this->sections = [];
        $this->boundTo = null;
        $this->diagnostics = new NullOutput();
        $this->sectionable = null;
        $this->progress = null;
    }

    /** The writer every diagnostic must go through. */
    public function writer(OutputInterface $output): OutputInterface
    {
        $this->bind($output);

        return $this->diagnostics;
    }

    /**
     * The writer this run is already bound to, or the given output when the run
     * never bound one.
     *
     * For the paths that are handed a resolved stream rather than the console
     * output — {@see Application::renderThrowable} receives `getErrorOutput()`
     * itself — so that asking the owner cannot rebind it to a stream that has
     * no error channel and swallow the message.
     */
    public function boundWriter(OutputInterface $fallback): OutputInterface
    {
        return $this->boundTo === null ? $fallback : $this->diagnostics;
    }

    /** Writes one diagnostic line through this run's writer. */
    public function write(OutputInterface $output, string $message): void
    {
        $this->writer($output)->writeln($message);
    }

    /**
     * A redrawable section for the progress frame, or null when this output has
     * no error stream to draw one on.
     *
     * Always created after the diagnostic section, which is what puts the frame
     * below the log rather than on top of it.
     */
    public function progressSection(OutputInterface $output): ?ConsoleSectionOutput
    {
        $this->bind($output);

        if ($this->sectionable === null) {
            return null;
        }

        return $this->progress ??= $this->sectionOn($this->sectionable);
    }

    /**
     * Erases the progress frame and forgets it.
     *
     * Used on the paths that end the run without the reporter's own `finish()`
     * — an uncaught throwable — so the last frame does not stay on the screen
     * above the trace.
     */
    public function stopProgress(): void
    {
        $this->progress?->clear();
        $this->progress = null;
    }

    private function bind(OutputInterface $output): void
    {
        if ($this->boundTo === $output) {
            return;
        }

        $this->sections = [];
        $this->progress = null;
        $this->boundTo = $output;

        if (!$output instanceof ConsoleOutputInterface) {
            $this->sectionable = null;
            $this->diagnostics = new NullOutput();

            return;
        }

        $error = $output->getErrorOutput();

        // `getErrorOutput()` hands back a plain `StreamOutput`, which has no
        // `section()` of its own; anything else (a buffer set by a test double)
        // is written to directly, and then no progress frame exists to collide
        // with.
        if (!$error instanceof StreamOutput || $error instanceof ConsoleSectionOutput) {
            $this->sectionable = null;
            $this->diagnostics = $error;

            return;
        }

        $this->sectionable = $error;
        $this->diagnostics = $this->sectionOn($error);
    }

    private function sectionOn(StreamOutput $error): ConsoleSectionOutput
    {
        return new ConsoleSectionOutput(
            $error->getStream(),
            $this->sections,
            $error->getVerbosity(),
            $error->isDecorated(),
            $error->getFormatter(),
        );
    }
}
