<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Defines all arguments and options for the check command.
 *
 * Extracted from CheckCommand::configure() to keep the command class focused
 * on orchestration logic.
 */
final class CheckCommandDefinition
{
    /**
     * Adds all arguments and options to the check command.
     *
     * @return list<string> Names of rule-specific options (to be hidden from --help)
     */
    public static function addOptions(Command $command, RuleRegistryInterface $ruleRegistry): array
    {
        self::addPresetOptions($command);
        self::addPathArgument($command);
        self::addFileOptions($command);
        self::addOutputOptions($command);
        self::addCacheOptions($command);
        self::addBaselineOptions($command);
        self::addSuppressionOptions($command);
        self::addGitScopeOptions($command);
        self::addRuntimeOptions($command);
        self::addProfileOptions($command);
        self::addFormatterOptions($command);
        self::addHealthOptions($command);
        $ruleOptionNames = self::addDynamicRuleOptions($command, $ruleRegistry);
        self::addGenericRuleOptions($command);

        return $ruleOptionNames;
    }

    private static function addPresetOptions(Command $command): void
    {
        $command->addOption(
            'preset',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Apply a named preset (strict, legacy, ci) or path to preset file (can be repeated or comma-separated)',
            [],
        );
    }

    private static function addPathArgument(Command $command): void
    {
        $command->addArgument(
            'paths',
            InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
            'Paths to analyze [default: auto-detect from composer.json]',
            [],
        );
    }

    private static function addFileOptions(Command $command): void
    {
        $command
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Directories to exclude (can be repeated)',
                [],
            )
            ->addOption(
                'exclude-path',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Suppress violations for files matching pattern (can be repeated, e.g., src/Entity/*)',
                [],
            )
            ->addOption(
                'include-generated',
                null,
                InputOption::VALUE_NONE,
                'Include files marked with @generated annotation (skipped by default)',
            )
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to configuration file',
            );
    }

    private static function addOutputOptions(Command $command): void
    {
        $command
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format (summary, text, json, checkstyle, sarif, gitlab, github, metrics, health). Default: summary',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Write output to file instead of stdout (atomic write)',
            )
            ->addOption(
                'fail-on',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum severity to trigger non-zero exit code (none, warning, error). Default: error. Exit codes: 0 = clean or warnings-only, 1 = warnings (requires --fail-on=warning), 2 = errors',
            )
            ->addOption(
                'namespace',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter results by namespace (boundary-aware prefix match)',
            )
            ->addOption(
                'class',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter results by class FQCN (exact match)',
            );
    }

    private static function addCacheOptions(Command $command): void
    {
        $command
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Disable caching',
            )
            ->addOption(
                'cache-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Cache directory',
            )
            ->addOption(
                'clear-cache',
                null,
                InputOption::VALUE_NONE,
                'Clear cache before analysis',
            );
    }

    private static function addBaselineOptions(Command $command): void
    {
        $command
            ->addOption(
                'baseline',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to baseline file for filtering known violations',
            )
            ->addOption(
                'generate-baseline',
                null,
                InputOption::VALUE_REQUIRED,
                'Generate baseline file with current violations',
            )
            ->addOption(
                'show-resolved',
                null,
                InputOption::VALUE_NONE,
                'Show count of violations resolved since baseline',
            )
            ->addOption(
                'baseline-ignore-stale',
                null,
                InputOption::VALUE_NONE,
                'Ignore stale baseline entries instead of failing',
            );
    }

    private static function addSuppressionOptions(Command $command): void
    {
        $command
            ->addOption(
                'show-suppressed',
                null,
                InputOption::VALUE_NONE,
                'Show suppressed violations',
            )
            ->addOption(
                'no-suppression',
                null,
                InputOption::VALUE_NONE,
                'Ignore suppression tags',
            );
    }

    private static function addGitScopeOptions(Command $command): void
    {
        $command
            ->addOption(
                'analyze',
                null,
                InputOption::VALUE_REQUIRED,
                'Scope of files to analyze (e.g., git:staged, git:main..HEAD)',
            )
            ->addOption(
                'report',
                null,
                InputOption::VALUE_REQUIRED,
                'Scope of violations to report (e.g., git:main..HEAD)',
            )
            ->addOption(
                'report-strict',
                null,
                InputOption::VALUE_NONE,
                'Only show violations exactly in changed files (exclude parent namespaces)',
            );
    }

    private static function addRuntimeOptions(Command $command): void
    {
        $command
            ->addOption(
                'workers',
                'w',
                InputOption::VALUE_REQUIRED,
                'Number of parallel workers (0 = disable parallel, default: auto-detect)',
            )
            ->addOption(
                'log-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Write debug log to file',
            )
            ->addOption(
                'log-level',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum log level (debug, info, warning, error)',
                'info',
            )
            ->addOption(
                'no-progress',
                null,
                InputOption::VALUE_NONE,
                'Disable progress bar',
            );
    }

    private static function addProfileOptions(Command $command): void
    {
        $command
            ->addOption(
                'profile',
                null,
                InputOption::VALUE_OPTIONAL,
                'Enable profiling and export to file (or show summary if no file specified)',
                false,
            )
            ->addOption(
                'profile-format',
                null,
                InputOption::VALUE_REQUIRED,
                'Profile export format (json or chrome-tracing)',
                'json',
            );
    }

    private static function addFormatterOptions(Command $command): void
    {
        $command
            ->addOption(
                'group-by',
                null,
                InputOption::VALUE_REQUIRED,
                'Group violations by: none, file, rule, severity (default: formatter-specific)',
            )
            ->addOption(
                'format-opt',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Formatter-specific option (format: key=value)',
                [],
            )
            ->addOption(
                'detail',
                null,
                InputOption::VALUE_OPTIONAL,
                'Show detailed violations (default: 200, --detail=all for unlimited, --detail=N for custom limit)',
                false, // false = not passed, null = passed without value
            )
            ->addOption(
                'top',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of top impact issues to show (default 10, 0 to disable)',
            );
    }

    /**
     * @return list<string> Names of dynamically registered rule options
     */
    private static function addDynamicRuleOptions(Command $command, RuleRegistryInterface $ruleRegistry): array
    {
        $booleanAliases = self::detectBooleanAliases($ruleRegistry);
        $optionNames = [];

        foreach ($ruleRegistry->getAllCliAliases() as $alias => $info) {
            $optionNames[] = $alias;

            if (\in_array($alias, $booleanAliases, true)) {
                $command->addOption(
                    $alias,
                    null,
                    InputOption::VALUE_NONE,
                    \sprintf('[%s] %s', $info['rule'], $info['option']),
                );
            } else {
                $command->addOption(
                    $alias,
                    null,
                    InputOption::VALUE_REQUIRED,
                    \sprintf('[%s] %s', $info['rule'], $info['option']),
                );
            }
        }

        return $optionNames;
    }

    /**
     * Detects CLI aliases that map to boolean options.
     *
     * Uses reflection on rule Options classes to find boolean constructor parameters.
     *
     * @return list<string>
     */
    private static function detectBooleanAliases(RuleRegistryInterface $ruleRegistry): array
    {
        $booleanAliases = [];

        foreach ($ruleRegistry->getClasses() as $ruleClass) {
            $aliases = $ruleClass::getCliAliases();
            if ($aliases === []) {
                continue;
            }

            $optionsClass = $ruleClass::getOptionsClass();
            $reflection = new ReflectionClass($optionsClass);

            foreach ($aliases as $alias => $optionName) {
                // Option name may be nested (e.g., 'method.warning'), use the leaf
                $leafName = str_contains($optionName, '.') ? substr($optionName, (int) strrpos($optionName, '.') + 1) : $optionName;

                if ($reflection->hasProperty($leafName)) {
                    $property = $reflection->getProperty($leafName);
                    $type = $property->getType();

                    if ($type instanceof ReflectionNamedType && $type->getName() === 'bool') {
                        $booleanAliases[] = $alias;
                    }
                }
            }
        }

        return $booleanAliases;
    }

    private static function addHealthOptions(Command $command): void
    {
        $command
            ->addOption(
                'exclude-health',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude health dimension from scoring (complexity, cohesion, coupling, typing, maintainability)',
                [],
            );
    }

    private static function addGenericRuleOptions(Command $command): void
    {
        $command
            ->addOption(
                'disable-rule',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Disable a rule or group by prefix (e.g., complexity, size.class-count). Disabling duplication.code-duplication also skips the memory-intensive detection phase',
            )
            ->addOption(
                'only-rule',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Run only specified rules or group by prefix (e.g., complexity, code-smell)',
            )
            ->addOption(
                'rule-opt',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Rule-specific option (format: rule-name:option=value)',
            );
    }
}
