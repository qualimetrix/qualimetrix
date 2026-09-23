<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Refusal;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;

/**
 * Where and how the message that ends a run is written.
 *
 * Three outcomes, each with its own factory-shaped method rather than one
 * method with a kind flag, so a caller cannot pass the wrong exit code for
 * the throwable it caught:
 *
 * - {@see self::refusal()} — {@see ConfigurationRefusal}, the carrier for
 *   exit code 3.
 * - {@see self::fallbackRefusal()} — a caught `InvalidArgumentException` that
 *   never became a carrier. A named secondary signal for code 3, kept
 *   separate so how many inputs still take
 *   this path is a call count, not an inference.
 * - {@see self::internalError()} — anything else: a product defect, exit
 *   code 1.
 *
 * Framing lives here and only here: callers pass an unframed `summary()` or
 * `getMessage()`, never a pre-built `<error>…</error>` string. Every write
 * happens at {@see OutputInterface::VERBOSITY_QUIET}: the message that ends a
 * run is not report payload that `-q` is allowed to swallow — only the
 * progress frame and the report are.
 */
final class RefusalPresenter
{
    public function __construct(private readonly ErrorStream $errorStream) {}

    /** A refusal caused by user input: a configuration key, value, file, or selector. */
    public function refusal(OutputInterface $output, ?string $format, ConfigurationRefusal $refusal): int
    {
        $this->present($output, $format, self::refusalSentence($refusal->summary()), ConsoleExitCode::Refusal, $refusal->position());

        return ConsoleExitCode::Refusal->value;
    }

    /**
     * A caught `InvalidArgumentException` with no {@see ConfigurationRefusal}
     * behind it — the named secondary signal for code 3. A separate method rather than a
     * shared one, precisely so this path is visible as a call count.
     *
     * Framed exactly like {@see self::refusal()}: the split is bookkeeping about
     * which inputs have not reached the carrier yet, and a reader shown two
     * dialects for one exit code learned nothing from the difference.
     */
    public function fallbackRefusal(OutputInterface $output, ?string $format, Throwable $failure): int
    {
        $this->present($output, $format, self::refusalSentence($failure->getMessage()), ConsoleExitCode::Refusal, null);

        return ConsoleExitCode::Refusal->value;
    }

    private static function refusalSentence(string $message): string
    {
        return \sprintf('Configuration error: %s', $message);
    }

    /**
     * A product defect the configuration's author could not have caused
     * through valid input. Carries a trace at
     * {@see OutputInterface::VERBOSITY_VERBOSE} and above — the one thing a
     * refusal never gets, because a refusal is the user's problem to fix and
     * an internal error is ours.
     */
    public function internalError(OutputInterface $output, ?string $format, Throwable $failure): int
    {
        $this->present($output, $format, \sprintf('Internal error: %s', $failure->getMessage()), ConsoleExitCode::InternalError, null);

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->writeStderr($output, '<comment>Stack trace:</comment>');
            $this->writeStderr($output, OutputFormatter::escape($failure->getTraceAsString()));
        }

        return ConsoleExitCode::InternalError->value;
    }

    private function present(
        OutputInterface $output,
        ?string $format,
        string $message,
        ConsoleExitCode $code,
        ?RefusedPosition $position,
    ): void {
        // Erase the progress frame first: printed on top of a live frame, the
        // message is destroyed by the frame's next redraw.
        $this->errorStream->stopProgress();

        if (MachineReadableFormats::carriesJson($format)) {
            $this->writeEnvelope($output, $message, $code->value, $position);

            return;
        }

        // `escape()` because `$message` is not ours: it quotes what the user
        // typed and, for a git refusal, what git printed — which can carry a
        // commit subject. An unknown tag would pass through, but a well-formed
        // one with a bad value (`<fg=bogus>`) makes the formatter throw, and
        // the exit ladder above reports that instead of this refusal. Only
        // the frame below is ours to have the formatter read.
        $this->writeStderr($output, \sprintf('<error>%s</error>', OutputFormatter::escape($message)));

        // The `--quiet` rule that hides the pointer on a report does not apply
        // here: this presenter writes at VERBOSITY_QUIET on purpose, because a
        // run that ends this way must still reach a reader who asked for
        // silence — see the class docblock.
        $this->writeStderr($output, \sprintf('<comment>%s</comment>', ProductIdentity::pointerText()));
    }

    /**
     * `errorStream->writer()` first, only to bind — its return is discarded
     * here — then `boundWriter()` to write. Every message this class writes
     * is the message that ends the run, and `writer()`'s no-channel drop is
     * scoped to ordinary diagnostics (a log line, a preflight warning) — see
     * the exemption on `boundWriter()`'s own docblock. Calling `boundWriter()`
     * alone, unbound, would resolve its fallback against `$output` itself
     * rather than against `$output`'s error channel: the callers here (a
     * command's `execute()` and the shared exit ladder) hand in the console
     * output, not an already-resolved error stream the way
     * `Application::renderThrowable()` receives one. Binding first is what
     * lets `boundWriter()` fall back only when `$output` truly has no
     * separate channel — an embedder's single-channel output — rather than
     * merely because nothing bound this run yet.
     */
    private function writeStderr(OutputInterface $output, string $message): void
    {
        $this->errorStream->writer($output);
        $this->errorStream->boundWriter($output)->writeln($message, OutputInterface::VERBOSITY_QUIET);
    }

    /**
     * Writes the `{error, exit_code, position}` envelope to stdout — the shape
     * every command's refusal and internal-error path shares.
     *
     * `position` is the key a refusal is addressed to — `{path, written,
     * accepted, closed}` — and `null` whenever the run ended without one: a
     * refusal about a whole document or a bare value, the fallback, an
     * internal error. The key is always present, so the document's shape does
     * not depend on what ended the run. The text path does not print it: the
     * wording of `$message` already names the key for a reader.
     *
     * `JSON_INVALID_UTF8_SUBSTITUTE`: `$message` can embed raw CLI input
     * (an option value, a path) that the user typed, and PHP argv bytes are
     * not guaranteed valid UTF-8. Without this flag, `JSON_THROW_ON_ERROR`
     * turns a malformed-input refusal into a `JsonException` that escapes
     * this method and is caught by the outer ladder as an internal error —
     * exit code 1 instead of the exit code 3 this refusal already committed
     * to returning. Substituting the invalid bytes keeps the envelope valid
     * JSON and keeps the refusal a refusal.
     */
    private function writeEnvelope(OutputInterface $output, string $message, int $exitCode, ?RefusedPosition $position): void
    {
        $payload = json_encode(
            ['error' => $message, 'exit_code' => $exitCode, 'position' => self::positionDocument($position)],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
        ) . "\n";

        // Mirrors OutputHelper::write()'s blocking-mode restore (amphp leaves
        // STDOUT non-blocking after worker communication), duplicated locally
        // because that helper's signature has no verbosity parameter and this
        // write must carry VERBOSITY_QUIET to survive `-q` (see class docblock).
        if ($output instanceof StreamOutput) {
            stream_set_blocking($output->getStream(), true);
        }

        // `OUTPUT_RAW`: the payload is already JSON and has nothing for the
        // console formatter to do. Left to read it, the formatter would parse
        // markup embedded in `$message` and throw on a malformed style, and
        // the envelope this refusal promised would never be written at all.
        $output->write($payload, false, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
    }

    /** @return ?array{path: list<string>, written: string, accepted: list<string>, closed: bool} */
    private static function positionDocument(?RefusedPosition $position): ?array
    {
        if ($position === null) {
            return null;
        }

        return [
            'path' => $position->segments(),
            'written' => $position->written(),
            'accepted' => $position->accepted(),
            'closed' => $position->isClosed(),
        ];
    }
}
