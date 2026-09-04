<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Core\Version;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Qualimetrix CLI application.
 *
 * Supports `--working-dir` / `-d` global option to change the effective
 * working directory before any command runs (same pattern as Composer).
 */
final class Application extends BaseApplication
{
    public const string NAME = 'Qualimetrix';

    public function __construct(private readonly ErrorStream $errorStream)
    {
        parent::__construct(self::NAME, Version::get());
    }

    /**
     * Renders an uncaught throwable through the run's error-stream owner.
     *
     * The base class resolves `getErrorOutput()` itself and writes there
     * directly, which is the one diagnostic guaranteed to arrive while a
     * progress frame is still on screen — nothing called the reporter's
     * `finish()` on this path. Going through the owner erases the frame first
     * and then writes the trace where it will not be erased in turn.
     *
     * The output handed in here is already the resolved error stream, not the
     * console output, so the owner is asked for the writer it is *already*
     * bound to; rebinding on it would resolve to no error channel at all.
     */
    public function renderThrowable(Throwable $e, OutputInterface $output): void
    {
        $this->errorStream->stopProgress();

        parent::renderThrowable($e, $this->errorStream->boundWriter($output));
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $workingDir = $input->getParameterOption(['--working-dir', '-d']);

        if (\is_string($workingDir) && $workingDir !== '') {
            $resolved = realpath($workingDir);

            if ($resolved === false || !is_dir($resolved)) {
                throw new InvalidArgumentException(\sprintf(
                    'Invalid working directory: %s',
                    $workingDir,
                ));
            }

            if (!chdir($resolved)) {
                throw new InvalidArgumentException(\sprintf(
                    'Failed to change working directory to: %s',
                    $resolved,
                ));
            }
        }

        return parent::doRun($input, $output);
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();

        $definition->addOption(new InputOption(
            'working-dir',
            'd',
            InputOption::VALUE_REQUIRED,
            'Use the given directory as working directory',
        ));

        return $definition;
    }
}
