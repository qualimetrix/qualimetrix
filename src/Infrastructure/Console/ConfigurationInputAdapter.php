<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Converts the Symfony CLI ingress into the Configuration-owned request, and
 * answers the author about what the resolved document accepted.
 *
 * Every command that reads the document resolves it here, which is why its
 * warnings are written here: a command that forgets to print them runs on a
 * configuration whose author was never told what it did.
 */
final class ConfigurationInputAdapter
{
    public function __construct(
        private readonly ConfigurationPipelineInterface $configurationPipeline,
        private readonly ErrorStream $errorStream,
        private readonly CliSelectorDecoder $selectorDecoder = new CliSelectorDecoder(),
    ) {}

    public function resolve(InputInterface $input): ConfigurationDocument
    {
        return $this->configurationPipeline->resolve(
            $this->adapt($input, self::currentWorkingDirectory()->value()),
        );
    }

    /** The document's warnings about accepted configuration, one `Warning:` line each on the error stream. */
    public function writeDiagnostics(ConfigurationDocument $document, OutputInterface $output): void
    {
        foreach ($document->diagnostics() as $diagnostic) {
            $this->errorStream->write($output, \sprintf('<comment>Warning: %s</comment>', OutputFormatter::escape($diagnostic->message)));
        }
    }

    /**
     * The same warnings as a structured report publishes them, each with every
     * layer it is about.
     *
     * @return list<array{message: string, source: list<array<string, mixed>>}>
     */
    public function publishedDiagnostics(ConfigurationDocument $document): array
    {
        $published = [];
        foreach ($document->diagnostics() as $diagnostic) {
            $published[] = [
                'message' => $diagnostic->message,
                'source' => array_map(
                    static fn(Provenance $provenance): array => RefusalPresenter::sourceDocument($provenance->origin),
                    $diagnostic->sources,
                ),
            ];
        }

        return $published;
    }

    /**
     * A refusal of the analysed paths naming the layer that wrote them: the
     * `paths` argument when the command line wrote them, the file or preset
     * otherwise.
     */
    public static function pathsRefusal(ConfigurationDocument $document, string $summary): ConfigurationRefusal
    {
        return $document->resolved()->get(ConfigSchema::PATHS)?->refusal($summary)
            ?? ConfigurationRefusal::aboutResolvedInput($summary, ConfigSchema::PATHS);
    }

    public function exitPolicy(ConfigurationDocument $document): ExitPolicy
    {
        return ExitPolicy::fromContributions($document->contributions(ConfigSchema::FAIL_ON));
    }

    public function adapt(InputInterface $input, string $workingDirectory): ConfigurationResolutionRequest
    {
        $this->refuseEmptyValues($input);

        [$values, $optionNames] = $this->overrides($input);

        return new ConfigurationResolutionRequest(
            self::absoluteWorkingDirectory($workingDirectory),
            CommandLineSpelling::option($input, 'config'),
            CommandLineSpelling::options($input, 'preset'),
            $values,
            $optionNames,
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

    /**
     * The configuration keys the command line writes, and the option or
     * argument that wrote each.
     *
     * An `--exclude` selector is checked here, where the option still names
     * it, and handed on in the mapping form a document writes it in.
     *
     * @return array{array<string, mixed>, array<string, string>}
     */
    private function overrides(InputInterface $input): array
    {
        $values = [];
        $names = [];
        $this->put($values, $names, ConfigSchema::PATHS, CommandLineSpelling::arguments($input, 'paths'), 'paths');
        $this->put($values, $names, ConfigSchema::EXCLUDES, array_map(
            function (string $selector): array {
                $definition = $this->selectorDecoder->decodePath($selector, '--exclude')->definition;

                return [$definition->kind->value => $definition->value];
            },
            CommandLineSpelling::options($input, 'exclude'),
        ), '--exclude');
        foreach (self::SINGLE_VALUED as $option => $key) {
            $this->put($values, $names, $key, CommandLineSpelling::option($input, $option), '--' . $option);
        }
        foreach (self::REPEATABLE as $option => $key) {
            $this->put($values, $names, $key, CommandLineSpelling::options($input, $option), '--' . $option);
        }

        if ($this->option($input, 'no-cache') === true) {
            $this->put($values, $names, ConfigSchema::CACHE_ENABLED, false, '--no-cache');
        }
        if ($this->option($input, 'include-generated') === true) {
            $this->put($values, $names, ConfigSchema::INCLUDE_GENERATED, true, '--include-generated');
        }
        if ($this->option($input, 'include-autoload-dev') === true) {
            $this->put($values, $names, ConfigSchema::INCLUDE_AUTOLOAD_DEV, true, '--include-autoload-dev');
        }
        $workers = CommandLineSpelling::option($input, 'workers');
        if ($workers !== null) {
            $this->put($values, $names, ConfigSchema::PARALLEL_WORKERS, (int) $workers, '--workers');
        }

        return [$values, $names];
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
     * @param array<string, string> $names
     */
    private function put(array &$values, array &$names, string $key, mixed $value, string $writer): void
    {
        if ($value !== null && $value !== []) {
            $values[$key] = $value;
            $names[$key] = $writer;
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
