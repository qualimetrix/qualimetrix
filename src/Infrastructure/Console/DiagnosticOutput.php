<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/** Routes human diagnostics away from the selected report payload. */
final readonly class DiagnosticOutput
{
    public function stream(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : new NullOutput();
    }

    public function write(OutputInterface $output, string $message, bool $newline = true): void
    {
        $errorOutput = $this->stream($output);
        if ($newline) {
            $errorOutput->writeln($message);

            return;
        }

        $errorOutput->write($message);
    }
}
