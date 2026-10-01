<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Support;

use Closure;
use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\CommandLinePathWrite;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\DocumentRoots;
use Qualimetrix\Analysis\Configuration\Loader\CommandLineLayer;
use Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionAddress;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection;
use Qualimetrix\Analysis\Finding\RuleExecution;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;

/** Builds test snapshots with the product builder and explicit producer metadata. */
final readonly class ResolvedOptionsFixture
{
    public function __construct(private RuleOptionsRegistry $registry) {}

    /** @param class-string<RuleOptionsInterface> $optionsClass */
    public function create(string $producer, string $optionsClass): RuleOptionsInterface
    {
        if (!class_exists($optionsClass)) {
            throw new InvalidArgumentException('Options class ' . $optionsClass . ' does not exist');
        }
        if (!is_a($optionsClass, RuleOptionsInterface::class, true)) {
            throw new InvalidArgumentException('An options class must implement RuleOptionsInterface.');
        }
        $configuration = FindingConfiguration::none()
            ->withRuleOptions($this->registry->configFileOptions())
            ->withCliOverrides($this->registry->cliOptions())
            ->withSelection($this->registry->selection());
        $snapshot = self::build($configuration, [new RuleMetadata($producer, $optionsClass, '', [], false)]);
        $this->registry->replace($configuration->withResolvedOptions($snapshot));
        return $snapshot->for($producer);
    }

    /**
     * @param list<RuleMetadata> $metadata
     * @param list<CommandLinePathWrite>|null $cliPathWrites Actual CLI records, or null for the direct fixture inputs
     */
    public static function build(FindingConfiguration $configuration, array $metadata, ?array $cliPathWrites = null): ResolvedRuleOptions
    {
        $resolved = self::authoredConfiguration($configuration, $metadata, $cliPathWrites);
        $execution = self::execution($metadata);
        return (new RuleOptionsBuild($execution))->build($resolved);
    }

    /**
     * @param list<array{source: string, values: array<string, mixed>}> $sources
     * @param list<RuleMetadata>|null $metadata
     */
    public static function document(array $sources, AbsolutePath $root, ?array $metadata = null): ConfigurationDocument
    {
        if ($metadata === null) {
            static $catalogue = null;
            if ($catalogue === null) {
                $container = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create();
                $execution = $container->get(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class);
                if (!$execution instanceof \Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface) {
                    throw new LogicException('The fixture container has no rule metadata execution.');
                }
                $catalogue = $execution->allRules();
            }
            $metadata = $catalogue;
        }
        $execution = self::execution($metadata);
        $sections = [];
        foreach (['rules', 'only_rules', 'disabled_rules'] as $key) {
            $sections[] = new RulesSection($execution, $key);
        }
        $layers = [];
        foreach ($sources as $source) {
            $origin = $source['source'] === 'preset'
                ? ConfigurationOrigin::of(ConfigurationSource::Preset, 'fixture')
                : ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $root->value() . '/qmx.yaml');
            $layers[] = new AuthoredLayer($origin, AuthoredNode::fromPlain($source['values']));
        }
        $resolved = DocumentComposer::compose(new DocumentSchema(DocumentRoots::completing($sections)), $layers);
        return new ConfigurationDocument($sources, $root, $resolved);
    }

    /** @param list<RuleMetadata> $metadata */
    public static function execution(array $metadata): RuleExecution
    {
        $lookups = [];
        foreach ($metadata as $producer) {
            $lookups[] = ['metadata' => $producer, 'create' => static fn(): RuleInterface => throw new LogicException('Metadata lookup constructed a rule.')];
        }
        return new RuleExecution($lookups, new class implements ProfilerInterface {
            public function start(string $name, ?string $category = null): void {}
            public function stop(string $name): void {}
        }, new RuleOptionsRegistry());
    }

    /**
     * @param class-string<RuleOptionsInterface|LevelOptionsInterface> $optionsClass
     * @param array<string, mixed> $options
     */
    public static function values(string $optionsClass, array $options): ResolvedRuleOptionValues
    {
        $entry = RuleOptionSurface::of($optionsClass)->schema();
        $section = new class ($entry) implements DocumentSectionSchemaInterface {
            public function __construct(private readonly NodeSchema $entry) {}
            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration('rules', NodeSchema::namedMap($this->entry, NameVocabulary::fixed(['fixture'])));
            }
        };
        $document = DocumentComposer::compose(new DocumentSchema([$section]), [new AuthoredLayer(
            ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/project/qmx.yaml'),
            AuthoredNode::fromPlain(['rules' => ['fixture' => $options]]),
        )]);
        return new ResolvedRuleOptionValues($document, 'fixture');
    }

    /**
     * @param list<RuleMetadata> $metadata
     * @param list<CommandLinePathWrite>|null $cliPathWrites
     */
    public static function authoredConfiguration(FindingConfiguration $configuration, array $metadata, ?array $cliPathWrites = null): FindingConfiguration
    {
        $cli = $configuration->cliOverrides->options;
        if ($metadata === []) {
            return $configuration;
        }

        $sources = [];
        $layers = [];
        $file = $configuration->ruleOptions->rules;
        if ($file !== []) {
            $sources[] = ['source' => 'config', 'values' => ['rules' => $file]];
            $layers[] = new AuthoredLayer(
                ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/project/qmx.yaml'),
                AuthoredNode::fromPlain(['rules' => $file]),
            );
        }

        $writes = $cliPathWrites ?? [];
        if ($cliPathWrites === null) {
            foreach ($metadata as $producer) {
                $surface = RuleOptionSurface::of($producer->optionsClass);
                foreach ($cli[$producer->name] ?? [] as $option => $value) {
                    RetiredSuppressionOptions::refuseRuleOption([(string) $option => $value], ConfigurationOrigin::of(ConfigurationSource::Resolved));
                    $address = $surface->locate((string) $option);
                    if ($address === null) {
                        $framework = FrameworkOptionKeys::declared();
                        $spelling = $framework->spellingOf(ConfigKeySpelling::normalize((string) $option));
                        $address = $spelling === null ? null : new RuleOptionAddress(null, $spelling);
                    }
                    if ($address === null) {
                        throw new LogicException('Test CLI option has no declared address: ' . $option);
                    }
                    $path = ['rules', $producer->name];
                    if ($address->level !== null) {
                        $path[] = $address->level;
                    }
                    $path[] = $address->key;
                    $writes[] = new CommandLinePathWrite(
                        $path,
                        \is_array($value) ? '' : (string) json_encode($value, \JSON_THROW_ON_ERROR),
                        '--rule-opt',
                        $surface->schemaAt($address),
                        \is_array($value) ? $value : null,
                    );
                }
            }
        }
        if ($writes !== []) {
            $cliLayer = CommandLineLayer::of(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), cliPathWrites: $writes));
            $sources[] = ['source' => 'cli', 'values' => ['rules' => $cliLayer->root->children['rules']->plain()]];
            $layers[] = $cliLayer;
        }

        $execution = self::execution($metadata);
        $sections = [];
        foreach (['rules', 'only_rules', 'disabled_rules'] as $root) {
            $sections[] = new RulesSection($execution, $root);
        }
        $resolved = DocumentComposer::compose(new DocumentSchema(DocumentRoots::completing($sections)), $layers);
        $document = new ConfigurationDocument($sources, AbsolutePath::fromString('/project'), $resolved);
        $typed = FindingConfiguration::fromDocument($document);
        $merged = new FindingConfiguration($configuration->ruleOptions, $configuration->cliOverrides, $typed->selection, document: $typed->document);

        return $merged->withSelection($configuration->selection);
    }

    /** @param array<string, mixed> $rules */
    public static function file(RuleOptionsRegistry $registry, array $rules): void
    {
        self::configure($registry, FindingConfiguration::none()->withRuleOptions($rules)->withCliOverrides($registry->cliOptions())->withSelection($registry->selection()));
    }

    /** @param array<string, mixed> $options */
    public static function cli(RuleOptionsRegistry $registry, string $producer, array $options): void
    {
        $cli = $registry->cliOptions();
        $cli[$producer] = $options;
        self::configure($registry, FindingConfiguration::none()->withRuleOptions($registry->configFileOptions())->withCliOverrides($cli)->withSelection($registry->selection()));
    }

    public static function cliValue(RuleOptionsRegistry $registry, string $producer, string $key, mixed $value): void
    {
        $options = $registry->cliOptions()[$producer] ?? [];
        $options[$key] = $value;
        self::cli($registry, $producer, $options);
    }

    public static function selection(RuleOptionsRegistry $registry, \Qualimetrix\Analysis\Finding\Contract\RuleSelection $selection): void
    {
        self::configure($registry, FindingConfiguration::none()->withRuleOptions($registry->configFileOptions())->withCliOverrides($registry->cliOptions())->withSelection($selection));
    }

    /** Installs raw input for a unit test that subsequently chooses its producer metadata. */
    public static function configure(RuleOptionsRegistry $registry, FindingConfiguration $configuration): void
    {
        $registry->replace($configuration->withResolvedOptions(self::build($configuration, [])));
    }

    /** @return array{metadata: RuleMetadata, create: Closure(): RuleInterface} */
    public static function lookup(RuleInterface $rule): array
    {
        return [
            'metadata' => new RuleMetadata($rule->getName(), $rule::getOptionsClass(), $rule::getDescription(), CliAliasReader::read($rule::class), false),
            'create' => static fn(): RuleInterface => $rule,
        ];
    }
}
