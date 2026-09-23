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

        return new ConfigurationResolutionRequest(
            self::absoluteWorkingDirectory($workingDirectory),
            CommandLineSpelling::option($input, 'config'),
            CommandLineSpelling::options($input, 'preset'),
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
            foreach (CommandLineSpelling::options($input, $name) as $written) {
                if (trim($written) === '') {
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
        $this->put($values, ConfigSchema::PATHS, CommandLineSpelling::arguments($input, 'paths'));
        $this->put($values, ConfigSchema::EXCLUDES, array_map(
            fn(string $selector) => $this->selectorDecoder->decodePath($selector, '--exclude'),
            CommandLineSpelling::options($input, 'exclude'),
        ));
        foreach (self::SINGLE_VALUED as $option => $key) {
            $this->put($values, $key, CommandLineSpelling::option($input, $option));
        }
        foreach (self::REPEATABLE as $option => $key) {
            $this->put($values, $key, CommandLineSpelling::options($input, $option));
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
        $workers = CommandLineSpelling::option($input, 'workers');
        if ($workers !== null) {
            $values[ConfigSchema::PARALLEL_WORKERS] = (int) $workers;
        }

        return $values;
    }

    /** @var array<string, string> single-valued option => configuration key */
    private const array SINGLE_VALUED = [
        'format' => ConfigSchema::FORMAT,
        'cache-dir' => ConfigSchema::CACHE_DIR,
        'fail-on' => ConfigSchema::FAIL_ON,
        'memory-limit' => ConfigSchema::MEMORY_LIMIT,
    ];

    /** @var array<string, string> repeatable option => configuration key */
    private const array REPEATABLE = [
        'disable-rule' => ConfigSchema::DISABLED_RULES,
        'only-rule' => ConfigSchema::ONLY_RULES,
        'exclude-health' => ConfigSchema::EXCLUDE_HEALTH,
    ];

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
