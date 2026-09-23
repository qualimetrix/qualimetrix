<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Symfony\Component\Console\Input\InputInterface;

/** Converts the Symfony CLI ingress into the Configuration-owned request. */
final class ConfigurationInputAdapter
{
    public function __construct(
        private readonly ConfigurationPipelineInterface $configurationPipeline,
        private readonly CliSelectorDecoder $selectorDecoder = new CliSelectorDecoder(),
    ) {}

    public function resolve(InputInterface $input): ConfigurationDocument
    {
        return $this->configurationPipeline->resolve(
            $this->adapt($input, self::currentWorkingDirectory()->value()),
        );
    }

    public function exitPolicy(ConfigurationDocument $document): ExitPolicy
    {
        return ExitPolicy::fromContributions($document->contributions(ConfigSchema::FAIL_ON));
    }

    public function adapt(InputInterface $input, string $workingDirectory): ConfigurationResolutionRequest
    {
        $this->refuseEmptyValues($input);

        $config = $this->option($input, 'config');
        $presets = $this->option($input, 'preset');

        return new ConfigurationResolutionRequest(
            self::absoluteWorkingDirectory($workingDirectory),
            \is_string($config) ? $config : null,
            \is_array($presets) ? array_values(array_filter($presets, is_string(...))) : [],
            $this->overrides($input),
        );
    }

    /**
     * Options whose owners read the empty string as "not given". Written
     * empty — `--config=$QMX_CONFIG` with the variable unset — each of them
     * used to run something other than what was asked: `--config=` fell back
     * to discovering `qmx.yaml` in the working directory, `--output=` printed
     * the report to stdout and left no artifact. The other doors of these
     * commands carry the empty string to an owner that refuses it itself.
     */
    private const array DOORS_READING_EMPTY_AS_ABSENT = ['config', 'preset', 'baseline', 'output', 'report'];

    /**
     * Every command that analyses passes through here once, before analysis
     * starts, so a door refused here is refused before any work is done.
     */
    private function refuseEmptyValues(InputInterface $input): void
    {
        foreach (self::DOORS_READING_EMPTY_AS_ABSENT as $name) {
            $value = $this->option($input, $name);
            $values = \is_array($value) ? $value : [$value];

            foreach ($values as $written) {
                if (\is_string($written) && trim($written) === '') {
                    throw ConfigurationRefusal::aboutCommandLineInput(
                        '--' . $name,
                        \sprintf(
                            'Option --%s was written with an empty value ("--%s="). '
                            . 'Write a value after "=", or omit --%s entirely to use its default.',
                            $name,
                            $name,
                            $name,
                        ),
                    );
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function overrides(InputInterface $input): array
    {
        $values = [];
        $this->put($values, ConfigSchema::PATHS, $input->hasArgument('paths') ? $input->getArgument('paths') : null);
        foreach ($this->mappedOptions() as $option => $key) {
            $value = $this->option($input, $option);
            if ($option === 'exclude' && \is_array($value)) {
                $value = array_map(
                    fn(string $selector) => $this->selectorDecoder->decodePath($selector, '--exclude'),
                    array_values(array_filter($value, is_string(...))),
                );
            }
            $this->put($values, $key, $value);
        }

        if ($this->option($input, 'no-cache') === true) {
            $values[ConfigSchema::CACHE_ENABLED] = false;
        }
        if ($this->option($input, 'include-generated') === true) {
            $values[ConfigSchema::INCLUDE_GENERATED] = true;
        }
        if ($this->option($input, 'include-autoload-dev') === true) {
            $values[ConfigSchema::INCLUDE_AUTOLOAD_DEV] = true;
        }
        $workers = $this->option($input, 'workers');
        if ($workers !== null) {
            $values[ConfigSchema::PARALLEL_WORKERS] = (int) $workers;
        }

        return $values;
    }

    /** @return array<string, string> */
    private function mappedOptions(): array
    {
        return [
            'exclude' => ConfigSchema::EXCLUDES,
            'format' => ConfigSchema::FORMAT,
            'cache-dir' => ConfigSchema::CACHE_DIR,
            'disable-rule' => ConfigSchema::DISABLED_RULES,
            'only-rule' => ConfigSchema::ONLY_RULES,
            'fail-on' => ConfigSchema::FAIL_ON,
            'exclude-health' => ConfigSchema::EXCLUDE_HEALTH,
            'memory-limit' => ConfigSchema::MEMORY_LIMIT,
        ];
    }

    private function option(InputInterface $input, string $name): mixed
    {
        return $input->hasOption($name) ? $input->getOption($name) : null;
    }

    /**
     * An absent option is null and an option nobody repeated is `[]`; both mean
     * "nothing was written here". The empty string does not: `--format=` is a
     * value the author typed, and dropping it here made the CLI door accept
     * silently what the YAML door refuses.
     *
     * @param array<string, mixed> $values
     */
    private function put(array &$values, string $key, mixed $value): void
    {
        if ($value !== null && $value !== []) {
            $values[$key] = $value;
        }
    }

    private static function absoluteWorkingDirectory(string $workingDirectory): AbsolutePath
    {
        return str_starts_with($workingDirectory, '/')
            ? AbsolutePath::fromString($workingDirectory)
            : PathFactory::fromCliArgument($workingDirectory, self::currentWorkingDirectory());
    }

    private static function currentWorkingDirectory(): AbsolutePath
    {
        $workingDirectory = getcwd();

        return AbsolutePath::fromString($workingDirectory !== false ? $workingDirectory : '/');
    }
}
