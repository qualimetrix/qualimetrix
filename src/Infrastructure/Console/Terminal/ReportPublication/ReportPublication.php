<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Terminal\ReportPublication;

use Qualimetrix\Infrastructure\Console\CheckReport\RenderedCheckReport;

use Qualimetrix\Infrastructure\Console\Invocation\CommandLineSpelling;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\Console\Terminal\ErrorStream;
use Qualimetrix\Reporting\Formatter\FormattedReport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ReportPublication
{
    public function __construct(private ErrorStream $errorStream) {}

    public function publishReport(RenderedCheckReport $report, InputInterface $input, OutputInterface $output, RunTargets $runTargets): void
    {
        $this->writeOutput($report->formatted, $report->format, $input, $output, $runTargets);
    }

    public function assertOutputIsWritable(InputInterface $input, RunTargets $runTargets): void
    {
        $path = CommandLineSpelling::option($input, 'output');
        if ($path !== null) {
            $runTargets->judge('--output', $path);
        }
    }

    private function writeOutput(
        FormattedReport $formattedOutput,
        string $format,
        InputInterface $input,
        OutputInterface $output,
        RunTargets $runTargets,
    ): void {
        $target = CommandLineSpelling::option($input, 'output');

        if ($target !== null) {
            $runTargets->write('--output', $formattedOutput->body);

            $this->errorStream->write(
                $output,
                \sprintf('<info>Report written to %s</info>', $target),
            );

            return;
        }

        // TTY warning for HTML output to stdout
        if ($format === 'html' && $this->isOutputTty($output)) {
            $this->errorStream->write(
                $output,
                '<comment>HTML output is best saved to a file. Use --output=report.html</comment>',
            );
        }

        OutputHelper::write($output, $formattedOutput->body);
    }

    private function isOutputTty(OutputInterface $output): bool
    {
        return $output instanceof \Symfony\Component\Console\Output\StreamOutput && stream_isatty($output->getStream());
    }
}
