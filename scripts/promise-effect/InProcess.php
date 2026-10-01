<?php

declare(strict_types=1);

/**
 * The three cheap observation points, taken through the product's real doors.
 *
 * A value can change between the authored door, the merged document and the
 * resolved options object. This probe keeps all three observation points. The
 * fourth point — the report — costs a process and lives in {@see ProcessProbe}.
 *
 * The YAML and CLI doors use the production configuration input adapter and
 * pipeline over the real `check` command definition.
 */

namespace Qualimetrix\PromiseEffect;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Loader\CommandLineLayer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Finding\Selection\RuleEnablementResolver;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Console\CheckCommandDefinition;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/** One raw observation: what came out, or how it refused to. */
final readonly class Observation
{
    public const string ACCEPTED = 'accepted';
    public const string REFUSED_FRAMED = 'refused-framed';
    public const string REFUSED_UNFRAMED = 'refused-unframed';
    public const string CRASHED = 'crashed';

    /**
     * The comparable text of a run that exited 2. The prefix is the shape
     * {@see ProcessObservation::text()} writes for a non-accepted run, which
     * is how the frozen snapshot spells it too.
     */
    private const string FINDINGS_ABOVE_THE_GATE = 'exit=2';

    public function __construct(
        public string $outcome,
        public string $text,
    ) {}

    /**
     * One observation as the classifier is allowed to read it — the only place
     * a process exit code is turned into an outcome for judging.
     *
     * **Exit 2 is not a refusal.** In this product it means "findings at or
     * above the gate", which is an observed VALUE of the run: a `fail_on`
     * probe that reaches it has been honoured, not rejected. Counting it as an
     * unframed refusal made `fail_on|null` read MALFORMED on both doors — the
     * stand reporting its own reading of an exit code as a product defect.
     *
     * The comparable text of such a run is the exit code ALONE. The body that
     * travels beside it in the raw snapshot is a 400-byte head of the report
     * carrying a timestamp, so comparing bodies would make two runs of the
     * same configuration differ for a reason that has nothing to do with the
     * door. Nothing is lost where it matters: exit 2 can only be reached where
     * this stand withdraws its own `--fail-on=none`, which is the `fail_on`
     * rows, and their declared observable IS the exit code. Where it is
     * reached by anything else the row loses sensitivity and reads
     * NOT OBSERVABLE — the worse verdict, deliberately.
     *
     * Applied to BOTH halves: the frozen raw snapshot is re-judged through
     * this function, and so is every fresh measurement, so the two halves stay
     * judged by one rule. It is idempotent, because a fresh observation
     * normalized here is stored in the shape this function returns.
     */
    public static function ofMeasured(string $outcome, string $text): self
    {
        if ($text === self::FINDINGS_ABOVE_THE_GATE || str_starts_with($text, self::FINDINGS_ABOVE_THE_GATE . ' ')) {
            return new self(self::ACCEPTED, self::FINDINGS_ABOVE_THE_GATE);
        }

        return new self($outcome, $text);
    }

    public function accepted(): bool
    {
        return $this->outcome === self::ACCEPTED;
    }
}

final class InProcess
{
    /** @var array<string, class-string<RuleOptionsInterface>> rule name => options class */
    public array $optionsClasses = [];

    /** @var array<string, array{rule: string, option: string}> */
    public array $aliases = [];

    private readonly ConfigurationPipeline $pipeline;

    private readonly ConfigurationInputAdapter $inputAdapter;

    private readonly RuleInputValidator $ruleInputValidator;

    private readonly Command $checkCommand;

    private string $workDirectory = '';

    /** @var array<string, array{door: Observation, merged: Observation, object: Observation}> */
    private array $memo = [];

    /** @var array<string, array{object: Observation, framework: Observation}> */
    private array $compositionMemo = [];

    private int $observations = 0;

    public function __construct(string $scratchRoot)
    {
        $container = (new ContainerFactory())->create();

        // Asked for by its interface, as the product asks; held as the
        // concrete class, because the door point needs the stage list and the
        // interface promises only the resolved document.
        $pipeline = $container->get(ConfigurationPipelineInterface::class);
        $execution = $container->get(RuleExecutionInterface::class);
        $registry = $container->get(RuleRegistryInterface::class);
        $universe = $container->get(ChannelUniverse::class);
        $optionsBuild = $container->get(RuleOptionsBuild::class);
        $computedMetrics = $container->get(ComputedMetricConfiguratorInterface::class);

        if (!$pipeline instanceof ConfigurationPipeline
            || !$execution instanceof RuleExecutionInterface
            || !$registry instanceof RuleRegistryInterface
            || !$universe instanceof ChannelUniverse
            || !$optionsBuild instanceof RuleOptionsBuild
            || !$computedMetrics instanceof ComputedMetricConfiguratorInterface) {
            throw new LedgerError('the container did not yield the collaborators the product wires');
        }

        $this->pipeline = $pipeline;
        $this->inputAdapter = new ConfigurationInputAdapter($pipeline, new ErrorStream(), $execution);
        $this->ruleInputValidator = new RuleInputValidator(
            $registry,
            $universe,
            $optionsBuild,
            $computedMetrics,
            new RuleEnablementResolver(),
        );

        foreach ($execution->allRules() as $metadata) {
            $this->optionsClasses[$metadata->name] = $metadata->optionsClass;
        }

        $this->aliases = $registry->getAllCliAliases();

        $this->checkCommand = new Command('check');
        CheckCommandDefinition::addOptions($this->checkCommand, $registry);

        $this->workDirectory = $scratchRoot . '/in-process';

        if (!is_dir($this->workDirectory)) {
            mkdir($this->workDirectory, 0o775, true);
        }
    }

    public function observations(): int
    {
        return $this->observations;
    }

    /** @return list<string> the 54 producer names the run itself registers */
    public function producerNames(): array
    {
        return array_keys($this->optionsClasses);
    }

    /**
     * Takes all three points for one written document plus one CLI door.
     *
     * @param array<string, mixed> $document what the yaml door is handed
     * @param list<string> $ruleOpts what the `--rule-opt` door is handed
     * @param array<string, string> $aliasFlags what the `cli-alias` door is handed
     *
     * @return array{door: Observation, merged: Observation, object: Observation}
     */
    public function take(array $document, array $ruleOpts, array $aliasFlags, string $rule): array
    {
        $memoKey = md5(serialize([$document, $ruleOpts, $aliasFlags, $rule]));

        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        ++$this->observations;
        $this->memo[$memoKey] = $this->takeFresh($document, $ruleOpts, $aliasFlags, $rule);

        return $this->memo[$memoKey];
    }

    /**
     * The axis-C probe: one invocation written by up to three layers at once,
     * observed at the two points a merged document is actually read from.
     *
     * Two observations come back, not one:
     *
     *   - `object` is the producer's resolved options object.
     *   - `framework` is what the registry's exclusion predicates answer for
     *     the same resolved configuration.
     *
     * @param array<string, mixed> $document what the `qmx.yaml` door is handed
     * @param list<array<string, mixed>> $presets preset documents, lowest layer first
     * @param list<string> $ruleOpts what the `--rule-opt` door is handed
     * @param array<string, string> $aliasFlags what the `cli-alias` door is handed
     * @param list<string> $pathWitnesses relative files the path predicate is asked about
     * @param list<string> $namespaceWitnesses namespaces the namespace predicate is asked about
     *
     * @return array{object: Observation, framework: Observation}
     */
    public function compose(
        array $document,
        array $presets,
        array $ruleOpts,
        array $aliasFlags,
        string $rule,
        array $pathWitnesses = [],
        array $namespaceWitnesses = [],
    ): array {
        $memoKey = 'compose:' . md5(serialize([$document, $presets, $ruleOpts, $aliasFlags, $rule, $pathWitnesses, $namespaceWitnesses]));

        if (isset($this->compositionMemo[$memoKey])) {
            return $this->compositionMemo[$memoKey];
        }

        ++$this->observations;
        $this->compositionMemo[$memoKey] = $this->composeFresh(
            $document,
            $presets,
            $ruleOpts,
            $aliasFlags,
            $rule,
            $pathWitnesses,
            $namespaceWitnesses,
        );

        return $this->compositionMemo[$memoKey];
    }

    /**
     * @param array<string, mixed> $document
     * @param list<array<string, mixed>> $presets
     * @param list<string> $ruleOpts
     * @param array<string, string> $aliasFlags
     * @param list<string> $pathWitnesses
     * @param list<string> $namespaceWitnesses
     *
     * @return array{object: Observation, framework: Observation}
     */
    private function composeFresh(
        array $document,
        array $presets,
        array $ruleOpts,
        array $aliasFlags,
        string $rule,
        array $pathWitnesses,
        array $namespaceWitnesses,
    ): array {
        $file = $this->workDirectory . '/qmx.yaml';
        file_put_contents($file, Yaml::dump($document, 8, 2));

        $presetNames = [];
        foreach ($presets as $index => $preset) {
            $name = 'composition-' . $index . '-' . md5(serialize($preset)) . '.yaml';
            file_put_contents($this->workDirectory . '/' . $name, Yaml::dump($preset, 8, 2));
            $presetNames[] = $name;
        }

        try {
            $input = $this->input($file, $presetNames, $ruleOpts, $aliasFlags);
            $request = $this->inputAdapter->adapt($input, $this->workDirectory);
            $document = $this->pipeline->resolve($request);
            $configuration = $this->ruleInputValidator->resolve($document, $input);
            $registry = new RuleOptionsRegistry();
            $registry->replace($configuration);
            $options = $registry->resolvedOptions()->for($rule);
        } catch (Throwable $error) {
            $refused = self::fromThrowable($error);
            $refused = new Observation($refused->outcome, $this->tokenize($refused->text));

            return ['object' => $refused, 'framework' => $refused];
        }

        $excluded = [];
        foreach ($pathWitnesses as $witness) {
            $excluded['path:' . $witness] = $registry->isPathExcluded($rule, RelativePath::fromString($witness));
        }
        foreach ($namespaceWitnesses as $witness) {
            $excluded['namespace:' . $witness] = $registry->isNamespaceExcluded($rule, $witness);
        }

        return [
            'object' => new Observation(Observation::ACCEPTED, $this->tokenize(self::dump(['options' => self::plain($options)]))),
            'framework' => new Observation(Observation::ACCEPTED, $this->tokenize(self::dump(['excluded' => $excluded]))),
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $ruleOpts
     * @param array<string, string> $aliasFlags
     *
     * @return array{door: Observation, merged: Observation, object: Observation}
     */
    private function takeFresh(array $document, array $ruleOpts, array $aliasFlags, string $rule): array
    {
        $file = $this->workDirectory . '/qmx.yaml';
        file_put_contents($file, Yaml::dump($document, 8, 2));

        try {
            $input = $this->input($file, [], $ruleOpts, $aliasFlags);
            $request = $this->inputAdapter->adapt($input, $this->workDirectory);
            $cliDoor = CommandLineLayer::of($request)->root->plain();
        } catch (Throwable $error) {
            $refused = self::fromThrowable($error);
            $refused = new Observation($refused->outcome, $this->tokenize($refused->text));

            return ['door' => $refused, 'merged' => $refused, 'object' => $refused];
        }

        $door = null;
        $merged = null;
        $object = null;
        $resolved = null;

        try {
            $resolved = $this->pipeline->resolve($request);

            $yamlDoor = null;
            foreach ($this->pipeline->stages() as $stage) {
                if ($stage->name() !== 'config_file') {
                    continue;
                }
                $layer = $stage->apply($request);
                if ($layer === null || \count($layer->authored) !== 1) {
                    throw new LedgerError('The written config file produced no single authored layer.');
                }
                $yamlDoor = $layer->authored[0]->root->plain();
                break;
            }
            if ($yamlDoor === null) {
                throw new LedgerError('The pipeline has no config-file stage for the written document.');
            }
            $door = new Observation(Observation::ACCEPTED, self::dump([
                'yaml' => $yamlDoor,
                'cli' => $cliDoor,
            ]));

            $documentValues = [];
            foreach ($resolved->resolved()->roots() as $root => $value) {
                $documentValues[$root] = $value->plain();
            }
            $merged = new Observation(Observation::ACCEPTED, self::dump($documentValues));
        } catch (Throwable $error) {
            $merged = self::fromThrowable($error);
            $door ??= $merged;
        }

        if ($rule === '' || !isset($this->optionsClasses[$rule])) {
            $object = new Observation(Observation::ACCEPTED, '(no options object at this path)');
        }

        if (!$door->accepted()) {
            $merged = $door;
            $object = $door;
        }

        if ($object === null && $merged->accepted()) {
            try {
                if ($resolved === null) {
                    throw new LedgerError('The accepted merged observation has no resolved document.');
                }
                $configuration = $this->ruleInputValidator->resolve($resolved, $input);
                $registry = new RuleOptionsRegistry();
                $registry->replace($configuration);
                $options = $registry->resolvedOptions()->for($rule);

                $object = new Observation(Observation::ACCEPTED, self::dump([
                    'options' => self::plain($options),
                    'excluded' => [
                        'namespace' => $registry->isNamespaceExcluded($rule, 'Probe\\Sub'),
                        'path' => $registry->isPathExcluded($rule, RelativePath::fromString('src/Sub/Helper.php')),
                    ],
                ]));
            } catch (Throwable $error) {
                $object = self::fromThrowable($error);
            }
        }

        $object ??= $merged;

        return [
            'door' => new Observation($door->outcome, $this->tokenize($door->text)),
            'merged' => new Observation($merged->outcome, $this->tokenize($merged->text)),
            'object' => new Observation($object->outcome, $this->tokenize($object->text)),
        ];
    }

    /**
     * @param list<string> $presetNames
     * @param list<string> $ruleOpts
     * @param array<string, string> $aliasFlags
     */
    private function input(string $configFile, array $presetNames, array $ruleOpts, array $aliasFlags): ArrayInput
    {
        $arguments = ['--config' => $configFile];
        if ($presetNames !== []) {
            $arguments['--preset'] = $presetNames;
        }
        if ($ruleOpts !== []) {
            $arguments['--rule-opt'] = $ruleOpts;
        }
        foreach ($aliasFlags as $alias => $value) {
            $arguments['--' . $alias] = $value;
        }

        return new ArrayInput($arguments, $this->checkCommand->getDefinition());
    }

    /**
     * A refusal is the product's own, framed answer; anything else thrown is
     * the defect class the round calls MALFORMED, and the two must never be
     * folded together.
     */
    private static function fromThrowable(Throwable $error): Observation
    {
        if ($error instanceof ConfigurationRefusal) {
            return new Observation(Observation::REFUSED_FRAMED, $error->getMessage());
        }

        if ($error instanceof InvalidArgumentException) {
            return new Observation(Observation::REFUSED_UNFRAMED, $error::class . ': ' . $error->getMessage());
        }

        return new Observation(Observation::CRASHED, $error::class . ': ' . $error->getMessage());
    }

    /**
     * The stored text must be machine-independent: a refusal quotes the path
     * of the document it refused, the snapshot is tracked, and
     * `scripts/check-private-leaks.sh` forbids an absolute home path in a
     * tracked file. Longest prefix first, raw and resolved.
     */
    public function tokenize(string $text): string
    {
        foreach ([$this->workDirectory, realpath($this->workDirectory)] as $candidate) {
            if (\is_string($candidate) && $candidate !== '') {
                $text = str_replace($candidate, '<WORK>', $text);
            }
        }

        return $text;
    }

    private static function dump(mixed $value): string
    {
        $encoded = json_encode(self::plain($value), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? '(unencodable)' : $encoded;
    }

    private static function plain(mixed $value): mixed
    {
        if (\is_object($value)) {
            $out = ['@' => $value::class];

            foreach ((array) $value as $name => $property) {
                // Private and protected property names arrive NUL-padded.
                $out[trim(str_replace([$value::class, '*'], '', (string) $name), "\0")] = self::plain($property);
            }

            return $out;
        }

        if (\is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        return $value;
    }
}
