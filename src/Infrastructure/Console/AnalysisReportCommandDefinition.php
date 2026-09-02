<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * What every command that renders one analysis of a configured run takes: which
 * configuration to read, how to render the answer, and whether the run reports
 * its progress.
 *
 * Declared once rather than per command because these are a contract with the
 * user, not a detail of any one command: `--format` must be the way a machine
 * representation is asked for everywhere (`CLI_CONVENTIONS.md`), and a second
 * spelling of `--config` in one command would be a second place to forget the
 * shortcut or the default.
 *
 * `--no-progress` is not about rendering the answer — it is about the run that
 * produces it, which is why the group is no longer described as a rendering
 * contract. It belongs with the others all the same: a command that runs an
 * analysis without it leaves the caller no way to silence the bar, and
 * {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator} reads the
 * flag off the input by name for every command alike.
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
            )
            ->addOption(
                'no-progress',
                null,
                InputOption::VALUE_NONE,
                'Disable progress bar',
            );
    }

    /**
     * The options that decide **which rules ran and against which boundaries**,
     * for a command whose answer is relative to that decision.
     *
     * Separate from {@see addOptions()} because not every rendering command has
     * a run to configure. Where a command does, leaving these out is not a
     * smaller surface but a wrong one: it would print the selection its
     * configuration resolved while giving the caller no way to state the
     * selection the run it defends was measured under.
     */
    public static function addSelectionOptions(Command $command): Command
    {
        return $command
            ->addOption(
                'preset',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Apply a named preset (strict, legacy, ci) or path to preset file (can be repeated or comma-separated)',
                [],
            )
            ->addOption(
                'disable-rule',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Disable a rule or group by prefix (e.g., complexity, size.class-count)',
                [],
            )
            ->addOption(
                'only-rule',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Run only specified rules or group by prefix (e.g., complexity, code-smell)',
                [],
            )
            ->addOption(
                'rule-opt',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Rule-specific option (format: rule-name:option=value)',
                [],
            );
    }
}
