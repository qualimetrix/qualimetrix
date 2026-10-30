<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Invocation\CommandDefinition;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * What every command that renders one analysis of a configured run takes: which
 * configuration to read, how to render the answer, and whether the run reports
 * its progress.
 *
 * Declared once rather than per command because these are a contract with the
 * user, not a detail of any one command: `--format` must be the way a machine
 * representation is asked for everywhere (`docs/internal/CLI_CONVENTIONS.md`), and a second
 * spelling of `--config` in one command would be a second place to forget the
 * shortcut or the default.
 *
 * `--no-progress` is not about rendering the answer — it is about the run that
 * produces it, which is why the group is no longer described as a rendering
 * contract. It belongs with the others all the same: a command that runs an
 * analysis without it leaves the caller no way to silence the bar, and
 * {@see \Qualimetrix\Infrastructure\Console\RunSetup\RuntimeConfigurator} reads the
 * flag off the input by name for every command alike.
 *
 * The execution options (`--no-cache`, `--cache-dir`, `--workers`, `--memory-limit`)
 * live here too, as {@see addCacheOptions()}, {@see addNoCacheOption()},
 * {@see addWorkersOption()} and {@see addMemoryLimitOption()}: a command that
 * accepts one of them accepts the spelling and meaning `check` gives it.
 * `--cache-dir` is a separate method because `graph:export` and `baseline:*`
 * take the switch without the directory: their preflight profiles do not map
 * a cache directory.
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
                'Path to the configuration file (defaults to qmx.yaml or qmx.yml, else qmx.dist.yaml or qmx.dist.yml, in the current working directory)',
            )
            ->addOption(
                'preset',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Apply a named preset (strict, legacy, ci) or path to preset file',
                [],
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
                'Disable a rule or channel by exact name, or every rule under a group with NAME.* (e.g., complexity.*, size.class-count)',
                [],
            )
            ->addOption(
                'only-rule',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Run only the named rule or channel, or every rule under a group with NAME.* (e.g., complexity.*, code-smell.*)',
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

    /** `--no-cache` and `--cache-dir`. */
    public static function addCacheOptions(Command $command): Command
    {
        return self::addNoCacheOption($command)
            ->addOption(
                'cache-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Writable private cache directory outside the project root (default: user cache directory)',
            );
    }

    public static function addNoCacheOption(Command $command): Command
    {
        return $command->addOption(
            'no-cache',
            null,
            InputOption::VALUE_NONE,
            'Disable caching',
        );
    }

    public static function addWorkersOption(Command $command): Command
    {
        return $command->addOption(
            'workers',
            'w',
            InputOption::VALUE_REQUIRED,
            'Number of parallel workers (0 = disable parallel, default: auto-detect)',
        );
    }

    public static function addMemoryLimitOption(Command $command): Command
    {
        return $command->addOption(
            'memory-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'PHP memory limit (e.g., 512M, 1G, -1 for unlimited)',
        );
    }
}
