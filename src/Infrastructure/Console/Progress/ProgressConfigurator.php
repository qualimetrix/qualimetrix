<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Progress;

use Qualimetrix\Infrastructure\Console\ErrorStream;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Decides whether this run shows a progress frame, and on what.
 *
 * Split out of `RuntimeConfigurator` because the decision needs two
 * collaborators of its own — the console-owned reporter switch and the error
 * stream's owner — and neither is any other runtime concern's business.
 *
 * **Every question is asked of the error stream, because that is where the
 * frame is drawn.** Asking stdout instead would answer for the wrong file:
 * `qmx check --format=json > report.json` on a terminal has an undecorated
 * stdout and a live stderr, and that run is exactly the one this arrangement
 * exists for.
 */
final readonly class ProgressConfigurator
{
    public function __construct(
        private SwitchableProgressReporter $progressReporter,
        private ErrorStream $errorStream,
    ) {}

    /** Clears the per-run reporter and the error stream's section list. */
    public function reset(): void
    {
        $this->progressReporter->reset();
        $this->errorStream->reset();
    }

    public function configure(InputInterface $input, OutputInterface $output): void
    {
        $section = $this->errorStream->progressSection($output);

        // No error stream of its own: a buffer, a NullOutput. Progress is not
        // possible, so nothing is enabled.
        if ($section === null) {
            $this->progressReporter->reset();

            return;
        }

        // Disable for non-TTY (CI, pipes, a redirected error stream)
        if (!$section->isDecorated()) {
            $this->progressReporter->reset();

            return;
        }

        // Explicit disable
        if ($input->hasOption('no-progress') && $input->getOption('no-progress') === true) {
            $this->progressReporter->reset();

            return;
        }

        // Disable for quiet mode
        if ($section->getVerbosity() === OutputInterface::VERBOSITY_QUIET) {
            $this->progressReporter->reset();

            return;
        }

        $this->progressReporter->enable(new ConsoleProgressBar($section));
    }
}
