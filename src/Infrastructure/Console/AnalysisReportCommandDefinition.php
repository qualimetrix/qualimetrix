<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * The two options every command that renders one analysis of a configured run
 * takes: which configuration to read, and how to render the answer.
 *
 * Declared once rather than per command because the pair is a contract with the
 * user, not a detail of any one command: `--format` must be the way a machine
 * representation is asked for everywhere (`CLI_CONVENTIONS.md`), and a second
 * spelling of `--config` in one command would be a second place to forget the
 * shortcut or the default.
 */
final class AnalysisReportCommandDefinition
{
    public static function addOptions(Command $command): Command
    {
        return $command
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to qmx.yaml (defaults to qmx.yaml in the current working directory)',
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: text or json',
                'text',
            );
    }
}
