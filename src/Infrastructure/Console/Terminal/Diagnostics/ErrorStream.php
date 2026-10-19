<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Terminal\Diagnostics;

use LogicException;
use Qualimetrix\Infrastructure\Console\Application\Application;

use Qualimetrix\Infrastructure\Console\Terminal\Glyphs\GlyphOutput;
use Qualimetrix\Infrastructure\Parallel\Contract\WorkerDiagnosticsInterface;
use Qualimetrix\Reporting\Formatter\Prose\GlyphMode;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

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
 * the two channels are separated at all. The one message exempt from that is
 * the uncaught throwable that ends the run: see {@see self::boundWriter()}.
 *
 * @qmx-threshold coupling.cbo 30 -- Section-list and redraw state belong to ErrorSections. This adapter still binds shared diagnostic consumers to one output policy; another dependency must be reviewed rather than relocating the same consumers behind another wrapper.
 */
final class ErrorStream implements WorkerDiagnosticsInterface
{
    private ?GlyphMode $mode = null;

    private readonly ErrorSections $sections;

    public function __construct()
    {
        $this->sections = new ErrorSections($this->glyphMode(...));
    }

    public function useGlyphMode(GlyphMode $mode): void
    {
        if ($this->mode !== null && $this->mode !== $mode) {
            throw new LogicException('The error stream already has a different glyph mode.');
        }
        $this->mode = $mode;
    }

    public function glyphMode(): GlyphMode
    {
        return $this->mode ?? GlyphMode::Unicode;
    }

    /** Drops the binding so the next run starts with an empty section list. */
    public function reset(): void
    {
        $this->sections->reset();
    }

    /** The writer every diagnostic must go through. */
    public function writer(OutputInterface $output): OutputInterface
    {
        $writer = $this->sections->writer($output);

        return $this->sections->hasWriter() ? new GlyphOutput($writer, $this->glyphMode(...)) : $writer;
    }

    /**
     * The writer this run is already bound to, or the given output when this
     * run has no diagnostic writer of its own.
     *
     * For the paths that are handed a resolved stream rather than the console
     * output — {@see Application::renderThrowable} receives `getErrorOutput()`
     * itself — so that asking the owner cannot rebind it to a stream that has
     * no error channel and swallow the message.
     *
     * The fallback is taken whenever there is no writer, and a run bound to a
     * single-channel output has none: its diagnostics are dropped by design.
     * Dropping applies to diagnostics, not to the message that ends the run —
     * an uncaught throwable would otherwise leave an internal error and an empty
     * screen, which is worse than writing the trace into the one channel the
     * caller gave, and is what Symfony does when no owner is involved at all.
     */
    public function boundWriter(OutputInterface $fallback): OutputInterface
    {
        return new GlyphOutput($this->sections->boundWriter($fallback), $this->glyphMode(...));
    }

    /** Writes one diagnostic line through this run's writer. */
    public function write(OutputInterface $output, string $message): void
    {
        $this->writer($output)->writeln($message);
    }

    public function diagnostic(string $message): void
    {
        (new GlyphOutput($this->sections->currentWriter(), $this->glyphMode(...)))->writeln($message, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
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
        return $this->sections->progressSection($output);
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
        $this->sections->stopProgress();
    }
}
