<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Refusal;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;

/**
 * Where and how the message that ends a run is written.
 *
 * Three outcomes, each with its own factory-shaped method rather than one
 * method with a kind flag, so a caller cannot pass the wrong exit code for
 * the throwable it caught (`01-refusal-exit-ladder.md` §3):
 *
 * - {@see self::refusal()} — {@see ConfigurationRefusal}, the round's carrier
 *   for exit code 3.
 * - {@see self::fallbackRefusal()} — a caught `InvalidArgumentException` that
 *   never became a carrier. A named secondary signal for code 3
 *   (`00-overview.md` rule 1), kept separate so how many inputs still take
 *   this path is a call count, not an inference.
 * - {@see self::internalError()} — anything else: a product defect, exit
 *   code 1.
 *
 * Framing lives here and only here: callers pass an unframed `summary()` or
 * `getMessage()`, never a pre-built `<error>…</error>` string. Every write
 * happens at {@see OutputInterface::VERBOSITY_QUIET}: the message that ends a
 * run is not payload `-q` is allowed to swallow (`01-refusal-envelope.md`
 * §2.3) — only the progress frame and the report are.
 */
final class RefusalPresenter
{
    public function __construct(private readonly ErrorStream $errorStream) {}

    /** A refusal by user input: a config key, a value, a file, a selector — see `00-overview.md`. */
    public function refusal(OutputInterface $output, ?string $format, ConfigurationRefusal $refusal): int
    {
        $this->present($output, $format, \sprintf('Configuration error: %s', $refusal->summary()), ConsoleExitCode::Refusal);

        return ConsoleExitCode::Refusal->value;
    }

    /**
     * A caught `InvalidArgumentException` with no {@see ConfigurationRefusal}
     * behind it — the named secondary signal for code 3
     * (`01-refusal-exit-ladder.md` §2.6). A separate method rather than a
     * shared one, precisely so this path is visible as a call count.
     */
    public function fallbackRefusal(OutputInterface $output, ?string $format, Throwable $failure): int
    {
        $this->present($output, $format, $failure->getMessage(), ConsoleExitCode::Refusal);

        return ConsoleExitCode::Refusal->value;
    }

    /**
     * A product defect the configuration's author could not have caused
     * through valid input. Carries a trace at
     * {@see OutputInterface::VERBOSITY_VERBOSE} and above — the one thing a
     * refusal never gets, because a refusal is the user's problem to fix and
     * an internal error is ours (`01-refusal-exit-ladder.md` §3).
     */
    public function internalError(OutputInterface $output, ?string $format, Throwable $failure): int
    {
        $this->present($output, $format, \sprintf('Internal error: %s', $failure->getMessage()), ConsoleExitCode::InternalError);

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->writeStderr($output, '<comment>Stack trace:</comment>');
            $this->writeStderr($output, $failure->getTraceAsString());
        }

        return ConsoleExitCode::InternalError->value;
    }

    private function present(OutputInterface $output, ?string $format, string $message, ConsoleExitCode $code): void
    {
        // Erase the progress frame first: printed on top of a live frame, the
        // message is destroyed by the frame's next redraw
        // (`01-refusal-exit-ladder.md` §3).
        $this->errorStream->stopProgress();

        if (MachineReadableFormats::carriesJson($format)) {
            $this->writeEnvelope($output, $message, $code->value);

            return;
        }

        $this->writeStderr($output, \sprintf('<error>%s</error>', $message));
    }

    /**
     * `errorStream->writer()` first, only to bind — its return is discarded
     * here — then `boundWriter()` to write. Every message this class writes
     * is the message that ends the run, and `writer()`'s no-channel drop is
     * scoped to ordinary diagnostics (a log line, a preflight warning) — see
     * the exemption on `boundWriter()`'s own docblock. Calling `boundWriter()`
     * alone, unbound, would resolve its fallback against `$output` itself
     * rather than against `$output`'s error channel: the callers here (a
     * command's `execute()`, this round's own ladder) hand in the console
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
     * Writes the `{error, exit_code}` envelope to stdout — the shape every
     * command's refusal and internal-error path now shares
     * (`01-refusal-envelope.md` §2.1). `origin()`/`position()` are not
     * structural fields here; they reach the reader through the wording of
     * `$message` instead.
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
    private function writeEnvelope(OutputInterface $output, string $message, int $exitCode): void
    {
        $payload = json_encode(
            ['error' => $message, 'exit_code' => $exitCode],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
        ) . "\n";

        // Mirrors OutputHelper::write()'s blocking-mode restore (amphp leaves
        // STDOUT non-blocking after worker communication), duplicated locally
        // because that helper's signature has no verbosity parameter and this
        // write must carry VERBOSITY_QUIET to survive `-q` (see class docblock).
        if ($output instanceof StreamOutput) {
            stream_set_blocking($output->getStream(), true);
        }

        $output->write($payload, false, OutputInterface::VERBOSITY_QUIET);
    }
}
