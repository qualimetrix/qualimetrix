<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline\Stage;

use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationLayer;
use Symfony\Component\Console\Input\InputInterface;

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

        // Paths from arguments
        $this->setIfNotEmpty($values, 'paths', $this->extractArrayArgument($input, 'paths'));

        // Options: excludes, format, cache, rules
        $this->setIfNotEmpty($values, 'excludes', $this->extractArrayOption($input, 'exclude'));
        $this->setIfNotEmpty($values, 'format', $this->extractStringOption($input, 'format'));
        $this->setIfNotEmpty($values, 'cache.dir', $this->extractStringOption($input, 'cache-dir'));
        $this->setIfNotEmpty($values, 'disabled_rules', $this->extractArrayOption($input, 'disable-rule'));
        $this->setIfNotEmpty($values, 'only_rules', $this->extractArrayOption($input, 'only-rule'));
        $this->setIfNotEmpty($values, 'fail_on', $this->extractStringOption($input, 'fail-on'));
        $this->setIfNotEmpty($values, 'exclude_health', $this->extractArrayOption($input, 'exclude-health'));

        // Cache disable flag
        if ($input->hasOption('no-cache') && $input->getOption('no-cache') === true) {
            $values['cache.enabled'] = false;
        }

        // Include generated files flag
        if ($input->hasOption('include-generated') && $input->getOption('include-generated') === true) {
            $values['include_generated'] = true;
        }

        // Parallel workers (0 = auto-detect, 1 = sequential, >1 = parallel)
        if ($input->hasOption('workers') && $input->getOption('workers') !== null) {
            $values['parallel.workers'] = (int) $input->getOption('workers');
        }

        // Memory limit
        $this->setIfNotEmpty($values, 'memory_limit', $this->extractStringOption($input, 'memory-limit'));

        if ($values === []) {
            return null;
        }

        return new ConfigurationLayer('cli', $values);
    }

    /**
     * @return non-empty-array<array-key, mixed>|null
     */
    private function extractArrayArgument(InputInterface $input, string $name): ?array
    {
        if (!$input->hasArgument($name)) {
            return null;
        }

        $value = $input->getArgument($name);

        return \is_array($value) && $value !== [] ? $value : null;
    }

    /**
     * @return non-empty-array<array-key, mixed>|null
     */
    private function extractArrayOption(InputInterface $input, string $name): ?array
    {
        if (!$input->hasOption($name)) {
            return null;
        }

        $value = $input->getOption($name);

        return \is_array($value) && $value !== [] ? $value : null;
    }

    private function extractStringOption(InputInterface $input, string $name): ?string
    {
        if (!$input->hasOption($name)) {
            return null;
        }

        $value = $input->getOption($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function setIfNotEmpty(array &$values, string $key, mixed $value): void
    {
        if ($value !== null) {
            $values[$key] = $value;
        }
    }
}
