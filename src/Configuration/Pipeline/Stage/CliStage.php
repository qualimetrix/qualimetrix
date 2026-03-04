<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration\Pipeline\Stage;

use AiMessDetector\Configuration\Pipeline\ConfigurationContext;
use AiMessDetector\Configuration\Pipeline\ConfigurationLayer;

/**
 * CLI arguments and options (priority: 30).
 *
 * Has highest priority - overrides all other stages.
 */
final class CliStage implements ConfigurationStageInterface
{
    private const int PRIORITY = 30;

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function name(): string
    {
        return 'cli';
    }

    public function apply(ConfigurationContext $context): ?ConfigurationLayer
    {
        $values = [];
        $input = $context->input;

        // Paths from arguments (if paths argument exists)
        if ($input->hasArgument('paths')) {
            $paths = $input->getArgument('paths');
            if (\is_array($paths) && $paths !== []) {
                $values['paths'] = $paths;
            }
        }

        // Excludes from option
        if ($input->hasOption('exclude')) {
            $excludes = $input->getOption('exclude');
            if (\is_array($excludes) && $excludes !== []) {
                $values['excludes'] = $excludes;
            }
        }

        // Format
        if ($input->hasOption('format')) {
            $format = $input->getOption('format');
            if (\is_string($format) && $format !== '') {
                $values['format'] = $format;
            }
        }

        // Cache options
        if ($input->hasOption('no-cache') && $input->getOption('no-cache') === true) {
            $values['cache.enabled'] = false;
        }

        if ($input->hasOption('cache-dir')) {
            $cacheDir = $input->getOption('cache-dir');
            if (\is_string($cacheDir) && $cacheDir !== '') {
                $values['cache.dir'] = $cacheDir;
            }
        }

        // Disabled rules
        if ($input->hasOption('disable-rule')) {
            $disabledRules = $input->getOption('disable-rule');
            if (\is_array($disabledRules) && $disabledRules !== []) {
                $values['disabled_rules'] = $disabledRules;
            }
        }

        // Only rules
        if ($input->hasOption('only-rule')) {
            $onlyRules = $input->getOption('only-rule');
            if (\is_array($onlyRules) && $onlyRules !== []) {
                $values['only_rules'] = $onlyRules;
            }
        }

        // Parallel workers
        if ($input->hasOption('workers')) {
            $workers = $input->getOption('workers');
            if ($workers !== null) {
                $workersInt = (int) $workers;
                // 0 means auto-detect, 1 means sequential, >1 means parallel
                $values['parallel.workers'] = $workersInt;
            }
        }

        if ($values === []) {
            return null;
        }

        return new ConfigurationLayer('cli', $values);
    }
}
