<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Support;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
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
use Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionAddress;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
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
        $lookups = [];
        foreach ($metadata as $producer) {
            $lookups[] = ['metadata' => $producer, 'create' => static fn(): RuleInterface => throw new LogicException('Metadata lookup constructed a rule.')];
        }
        $execution = new RuleExecution($lookups, new class implements ProfilerInterface {
            public function start(string $name, ?string $category = null): void {}
            public function stop(string $name): void {}
        }, new RuleOptionsRegistry());
        return (new RuleOptionsBuild($execution))->build($resolved);
    }

    /**
     * @param list<RuleMetadata> $metadata
     * @param list<CommandLinePathWrite>|null $cliPathWrites
     */
    public static function authoredConfiguration(FindingConfiguration $configuration, array $metadata, ?array $cliPathWrites = null): FindingConfiguration
    {
        $cli = $configuration->cliOverrides->options;
        if ($cli === [] && ($cliPathWrites === null || $cliPathWrites === [])) {
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

        $resolved = DocumentComposer::compose(new DocumentSchema(DocumentRoots::completing([])), $layers);
        $document = new ConfigurationDocument($sources, AbsolutePath::fromString('/project'), $resolved);
        $merged = (new FindingConfigurationResolver())->resolve($document, $configuration->cliOverrides);

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
