<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * The input every baseline command shares, defined once.
 *
 * **The line these options are drawn along is ADR 0017 asymmetry: a flag may
 * narrow the measured set, none may widen it.**
 *
 * *Absent, and deliberately so* — the exclusion and suppression flags `check`
 * accepts (`--exclude-path`, `--exclude-namespace`,
 * `--no-suppression-annotations`). They only ever *remove* findings from a
 * report, so a baseline command that took them would capture less than the
 * `check` it must agree with, leaving entries that can never apply.
 *
 * *Present, and equally deliberately* — the four options that decide **which
 * rules run and against which thresholds**: `--preset`, `--rule-opt`,
 * `--only-rule`, `--disable-rule`. These are configuration, not exclusion — a
 * preset is a configuration layer, and ADR 0017 defines the measured set by
 * configuration. Denying them to these commands does not keep the two sides
 * in agreement, it breaks them: `check --preset=strict --baseline=b.json`
 * measures strictly more than `baseline:generate b.json` captured, and every
 * finding the capture could not see reads as a breach and promotes its whole
 * group to Error on code nobody touched (ADR 0017). Widening is the direction
 * that costs, and this is the one place it could happen silently.
 *
 * Defining the shared input in one place means both halves are one thing to
 * check rather than five, and
 * {@see \Qualimetrix\Tests\Analysis\Policy\Baseline\Functional\BaselineCommandOptionSurfaceTest}
 * checks them by asking each command's definition rather than by reading it.
 *
 * `--config` belongs with the second group for the same reason: it names
 * *which* configuration defines the set, and without it a baseline command
 * could not be pointed at the same `qmx.yaml` the `check` it must agree with
 * was pointed at.
 *
 * The per-rule CLI aliases `check` registers dynamically (`--max-ccn` and
 * friends) are not mirrored here: `--rule-opt=rule:option=value` reaches every
 * one of them without this class needing the rule registry, and one spelling
 * of a thing is what keeps the two surfaces comparable.
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
     * Every name below is spelled exactly as `check` spells it, and that is
     * the requirement rather than a convenience: `paths`, `preset`,
     * `disable-rule` and `only-rule` are read by
     * {@see \Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage} and
     * {@see \Qualimetrix\Analysis\Configuration\Pipeline\Stage\PresetStage} off the
     * `InputInterface` by name, and `rule-opt` by
     * {@see \Qualimetrix\Infrastructure\Console\CliOptionsParser}. A different
     * spelling here would leave the option accepted and inert — the failure
     * mode where a user narrows one side of the comparison and is never told
     * the other side did not follow.
     *
     * `--no-progress` is here for a different reason than the rest: it changes
     * nothing about the measured set, only about what the run draws on the
     * error stream while measuring it. It is the run's input all the same, and
     * a baseline command that measures a whole tree without offering it would
     * be the one analysing command a caller cannot quiet.
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
            )
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
            )
            ->addOption(
                'no-progress',
                null,
                InputOption::VALUE_NONE,
                'Disable progress bar',
            );
    }
}
