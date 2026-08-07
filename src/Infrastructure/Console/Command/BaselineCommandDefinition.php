<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * The input every baseline command shares, defined once.
 *
 * **What is deliberately absent is the point.** §5.5 of the
 * baseline-ceiling plan forbids these commands the exclusion and suppression
 * flags `check` accepts — `--exclude-path`, `--exclude-namespace`,
 * `--no-suppression-annotations` — because a set that a flag can move is a
 * set two commands can disagree about. Defining the shared input in one place
 * means the prohibition is one thing to check rather than five, and
 * {@see \Qualimetrix\Tests\Functional\Console\Command\BaselineCommandOptionSurfaceTest}
 * checks it by asking each command's definition rather than by reading them.
 *
 * `--config` is not such a flag: it names *which* configuration defines the
 * set, and without it a baseline command could not be pointed at the same
 * `qmx.yaml` the `check` it must agree with was pointed at.
 */
final class BaselineCommandDefinition
{
    /**
     * The baseline file a command reads, writes, or both.
     */
    public static function addBaselineFileArgument(Command $command, string $description): void
    {
        $command->addArgument('baseline', InputArgument::REQUIRED, $description);
    }

    /**
     * The paths to analyse, plus the configuration that defines the measured
     * set over them.
     *
     * `paths` is named exactly as `check` names it so that
     * {@see \Qualimetrix\Configuration\Pipeline\Stage\CliStage} picks it up:
     * the paths then travel the same route into the resolved configuration
     * for both commands, instead of one of them applying them afterwards.
     */
    public static function addMeasuredRunInput(Command $command): void
    {
        $command
            ->addArgument(
                'paths',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'Paths to analyze [default: auto-detect from composer.json]',
                [],
            )
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to configuration file',
            );
    }
}
