<?php

declare(strict_types=1);

/**
 * The three cheap observation points, taken through the product's real doors.
 *
 * `warning: true -> 1` happens inside `fromArray()`; before the factory the
 * value is still `true`. So a stand with one observation point cannot say
 * where a form was lost, and this one keeps three: what the DOOR produced,
 * what the MERGED DOCUMENT carried, and what the OPTIONS OBJECT became. The
 * fourth point — the report — costs a process and lives in {@see ProcessProbe}.
 *
 * None of this re-implements parsing. The yaml door is `ConfigFileStage`
 * behind the real pipeline, the `--rule-opt` door is `RuleOptionsParser`, the
 * `cli-alias` door is `CliOptionsParser` over a real `InputDefinition` built
 * from the real `check` command. A stand that parsed the value itself would be
 * measuring its own parser.
 */

namespace Qualimetrix\PromiseEffect;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Console\CheckCommandDefinition;
use Qualimetrix\Infrastructure\Console\CliOptionsParser;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
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

    private readonly FindingConfigurationResolver $resolver;

    private readonly RuleOptionsParser $ruleOptionsParser;

    private readonly Command $checkCommand;

    private string $workDirectory = '';

    /** @var array<string, array{door: Observation, merged: Observation, object: Observation}> */
    private array $memo = [];

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

        if (!$pipeline instanceof ConfigurationPipeline
            || !$execution instanceof RuleExecutionInterface
            || !$registry instanceof RuleRegistryInterface) {
            throw new LedgerError('the container did not yield the collaborators the product wires');
        }

        $this->pipeline = $pipeline;

        // Stateless; the container keeps it private, and a copy of the product
        // class is the product class.
        $this->resolver = new FindingConfigurationResolver();

        foreach ($execution->allRules() as $metadata) {
            $this->optionsClasses[$metadata->name] = $metadata->optionsClass;
        }

        $this->aliases = $registry->getAllCliAliases();
        $this->ruleOptionsParser = (new RuleOptionsParserFactory())->createFromClasses($registry->getClasses());

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

        $cliValues = [];
        $door = null;

        try {
            $input = new ArrayInput(
                array_merge(
                    $ruleOpts === [] ? [] : ['--rule-opt' => $ruleOpts],
                    $aliasFlags === [] ? [] : array_combine(
                        array_map(static fn(string $a): string => '--' . $a, array_keys($aliasFlags)),
                        array_values($aliasFlags),
                    ),
                ),
                $this->checkCommand->getDefinition(),
            );
            $cliValues = (new CliOptionsParser($this->ruleOptionsParser))->parseRuleOptions($input);
        } catch (Throwable $error) {
            $door = self::fromThrowable($error);
        }

        $request = new ConfigurationResolutionRequest(
            AbsolutePath::fromString($this->workDirectory),
            $file,
        );

        $merged = null;
        $object = null;
        $documentContributions = null;

        try {
            $resolved = $this->pipeline->resolve($request);

            if ($door === null) {
                // The yaml door's own product: what ConfigFileStage made of the
                // written document, before any other layer touched it.
                $doorLayer = [];

                foreach ($this->pipeline->stages() as $stage) {
                    if ($stage->name() !== 'config_file') {
                        continue;
                    }

                    $layer = $stage->apply($request);
                    $doorLayer = $layer === null ? [] : $layer->values;
                }

                $door = new Observation(Observation::ACCEPTED, self::dump([
                    'yaml' => $doorLayer,
                    'cli' => $cliValues,
                ]));
            }

            $documentContributions = [];

            foreach (['rules', 'architecture', 'computedMetrics', 'coupling', 'cache', 'parallel',
                'paths', 'exclude', 'format', 'failOn', 'disabledRules', 'onlyRules',
                'suppressPaths', 'suppressNamespaces', 'excludeHealth', 'includeGenerated',
                'memoryLimit'] as $root) {
                $contributions = $resolved->contributions($root);

                if ($contributions !== []) {
                    $documentContributions[$root] = $contributions;
                }
            }

            $merged = new Observation(Observation::ACCEPTED, self::dump($documentContributions));
        } catch (Throwable $error) {
            $merged = self::fromThrowable($error);
            $door ??= $merged;
        }

        if ($rule === '' || $this->optionsClasses[$rule] === null) {
            $object = new Observation(Observation::ACCEPTED, '(no options object at this path)');
        }

        if ($object === null && $merged->accepted()) {
            try {
                $resolved = $this->pipeline->resolve($request);
                $configuration = $this->resolver->resolve($resolved, new FindingCliOverrides($cliValues));

                // A fresh registry per observation: the factory drains the
                // framework keys into providers the registry owns, and a shared
                // one would carry the previous probe's exclusions into this one.
                $registry = new RuleOptionsRegistry();
                $registry->replace($configuration);
                $options = (new RuleOptionsFactory($registry))->create($rule, $this->optionsClasses[$rule]);

                // The three framework keys never reach `fromArray()`; the
                // factory drains them into the registry's two providers, and
                // the decision they feed is taken by namespace and by path, not
                // by any field of the options object. So the object point for
                // those rows is the answer of the predicates themselves.
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
