<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Integration;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationStoreInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationResolver;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationStoreInterface;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Parallel\Configuration\ParallelConfigurationResolver;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationStoreInterface;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileReportInterface;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class RuntimeConfigurationIsolationTest extends TestCase
{
    private string $temporaryDirectory;

    /** @var list<DocumentSectionSchemaInterface> the sections the compiled pipeline composes with */
    private array $sections = [];

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/qmx-runtime-isolation-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Resolving an enabled cache creates its directory, so the run leaves
        // more here than the empty root `rmdir` alone can take back.
        exec(\sprintf('rm -rf %s', escapeshellarg($this->temporaryDirectory)));
    }

    #[Test]
    public function itRestoresDefaultOwnerConfigurationsAfterACustomRunInTheSameContainer(): void
    {
        [$runtimeConfigurator, $command, $ruleInputValidator] = $this->runtimeServices();
        $customCacheDirectory = $this->temporaryDirectory . '/custom-cache';

        $runtimeConfigurator->resetRunState();
        $customDocument = $this->document([
            'cache.dir' => $customCacheDirectory,
            'cache.enabled' => false,
            'parallel.workers' => 0,
        ]);
        $projectRoot = \Qualimetrix\Core\Path\AbsolutePath::fromString($this->temporaryDirectory);
        $runtimeConfigurator->configure($customDocument, new \Qualimetrix\Infrastructure\Console\ResolvedRunConfiguration($this->runConfigurationFor($customDocument), $this->cacheConfiguration($customDocument, $projectRoot), $this->parallelConfiguration($customDocument)), $ruleInputValidator->resolve($customDocument, new ArrayInput([], $command->getDefinition())), new ArrayInput([], $command->getDefinition()), new BufferedOutput());

        self::assertSame($customCacheDirectory, $this->cacheStore($runtimeConfigurator)->current()->directory->value());
        self::assertFalse($this->cacheStore($runtimeConfigurator)->current()->enabled);
        self::assertSame(0, $this->parallelStore($runtimeConfigurator)->current()->workers);

        $runtimeConfigurator->resetRunState();
        $defaultDocument = $this->document([]);
        $runtimeConfigurator->configure($defaultDocument, new \Qualimetrix\Infrastructure\Console\ResolvedRunConfiguration($this->runConfigurationFor($defaultDocument), $this->cacheConfiguration($defaultDocument, $projectRoot), $this->parallelConfiguration($defaultDocument)), $ruleInputValidator->resolve($defaultDocument, new ArrayInput([], $command->getDefinition())), new ArrayInput([], $command->getDefinition()), new BufferedOutput());

        self::assertSame($this->temporaryDirectory . '/.qmx-cache', $this->cacheStore($runtimeConfigurator)->current()->directory->value());
        self::assertTrue($this->cacheStore($runtimeConfigurator)->current()->enabled);
        self::assertNull($this->parallelStore($runtimeConfigurator)->current()->workers);
    }

    #[Test]
    public function itKeepsDefaultsAfterFailedResolutionBeforeTheNextRunInTheSameContainer(): void
    {
        [$runtimeConfigurator, $command, $ruleInputValidator] = $this->runtimeServices();
        $runtimeConfigurator->resetRunState();
        $projectRoot = \Qualimetrix\Core\Path\AbsolutePath::fromString($this->temporaryDirectory);
        try {
            $invalidDocument = $this->document(['parallel.workers' => -1]);
            $runtimeConfigurator->configure($invalidDocument, new \Qualimetrix\Infrastructure\Console\ResolvedRunConfiguration($this->runConfigurationFor($invalidDocument), $this->cacheConfiguration($invalidDocument, $projectRoot), $this->parallelConfiguration($invalidDocument)), $ruleInputValidator->resolve($invalidDocument, new ArrayInput([], $command->getDefinition())), new ArrayInput([], $command->getDefinition()), new BufferedOutput());
            self::fail('Invalid parallel configuration must fail before mutating owner stores.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('parallel.workers must be a non-negative integer.', $refusal->summary());
            $position = $refusal->position();
            self::assertNotNull($position);
            self::assertSame(['parallel', 'workers'], $position->segments);
            self::assertSame('workers', $position->written);
            self::assertCount(1, $refusal->sources());
            self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
            self::assertSame('test', $refusal->sources()[0]->locator());
        }

        self::assertTrue($this->cacheStore($runtimeConfigurator)->current()->enabled);
        self::assertNull($this->parallelStore($runtimeConfigurator)->current()->workers);

        $defaultDocument = $this->document([]);
        $runtimeConfigurator->configure($defaultDocument, new \Qualimetrix\Infrastructure\Console\ResolvedRunConfiguration($this->runConfigurationFor($defaultDocument), $this->cacheConfiguration($defaultDocument, $projectRoot), $this->parallelConfiguration($defaultDocument)), $ruleInputValidator->resolve($defaultDocument, new ArrayInput([], $command->getDefinition())), new ArrayInput([], $command->getDefinition()), new BufferedOutput());

        self::assertTrue($this->cacheStore($runtimeConfigurator)->current()->enabled);
        self::assertNull($this->parallelStore($runtimeConfigurator)->current()->workers);
    }

    #[Test]
    public function itKeepsEveryOwnerStoreAtDefaultsAfterLateArchitectureFailureInTheCompiledContainer(): void
    {
        [$runtimeConfigurator, $command, $ruleInputValidator] = $this->runtimeServices();
        $runtimeConfigurator->resetRunState();
        $projectRoot = \Qualimetrix\Core\Path\AbsolutePath::fromString($this->temporaryDirectory);
        // Well-formed for the engine, so the document composes and the refusal
        // comes from the architecture owner inside configure(), after the
        // owners resolved before it.
        $invalidDocument = $this->document([
            'architecture' => ['layers' => [
                ['name' => 'app', 'patterns' => ['App\\First']],
                ['name' => 'app', 'patterns' => ['App\\Second']],
            ]],
        ]);
        $input = new ArrayInput(['--profile' => true], $command->getDefinition());

        try {
            $runtimeConfigurator->configure($invalidDocument, new \Qualimetrix\Infrastructure\Console\ResolvedRunConfiguration($this->runConfigurationFor($invalidDocument), $this->cacheConfiguration($invalidDocument, $projectRoot), $this->parallelConfiguration($invalidDocument)), $ruleInputValidator->resolve($invalidDocument, $input), $input, new BufferedOutput());
            self::fail('Invalid architecture configuration must fail before mutating owner stores or effects.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('duplicate layer name "app"', $refusal->getMessage());
        }

        $this->assertDefaultOwnerState($runtimeConfigurator);

        $defaultDocument = $this->document([]);
        $defaultInput = new ArrayInput([], $command->getDefinition());
        $runtimeConfigurator->configure($defaultDocument, new \Qualimetrix\Infrastructure\Console\ResolvedRunConfiguration($this->runConfigurationFor($defaultDocument), $this->cacheConfiguration($defaultDocument, $projectRoot), $this->parallelConfiguration($defaultDocument)), $ruleInputValidator->resolve($defaultDocument, $defaultInput), $defaultInput, new BufferedOutput());

        $this->assertDefaultOwnerState($runtimeConfigurator);
    }

    /**
     * Two properties, one about succeeding runs and one about a failing one.
     *
     * Between two successful runs the configured computed channels are
     * replaced, never merged: run two must not be able to address run one's
     * metrics.
     *
     * A run that fails preflight commits nothing, so what the universe answers
     * afterwards is still the last *successful* configuration — not a
     * half-applied mixture of the two, and not an empty set.
     *
     * That last clause changed with the merged channel universe, and the
     * change is deliberate rather than incidental. The producer half used to
     * be backed by a container instance frozen over an empty definition set
     * for the whole process; its "nothing configured" answer after a reset was
     * an artefact of that instance never being given definitions at all, not a
     * guarantee anyone had made. The declaration half, meanwhile, has always
     * read the live catalog and has always answered from the last committed
     * configuration. One instance cannot do both, and the live reading is the
     * one with real consumers: the baseline ceiling has no other source for a
     * computed metric's direction.
     *
     * Draining the catalog on reset would restore the stricter reading, but
     * the catalog belongs to the ComputedMetrics capability and today offers
     * `replace()` and no reset. That is its owner's call to make, not this
     * test's to assume.
     */
    #[Test]
    public function itReplacesDynamicComputedChannelsBetweenRunsAndCommitsNothingFromAFailedRun(): void
    {
        [$runtimeConfigurator, $command, $ruleInputValidator] = $this->runtimeServices();
        $projectRoot = \Qualimetrix\Core\Path\AbsolutePath::fromString($this->temporaryDirectory);

        $first = $this->document([
            ConfigSchema::COMPUTED_METRICS => ['computed.first' => ['formula' => '1', 'levels' => ['class']]],
            ConfigSchema::ONLY_RULES => ['computed.first'],
        ]);
        $firstInput = new ArrayInput([], $command->getDefinition());
        $runtimeConfigurator->resetRunState();
        $runtimeConfigurator->configure($first, new \Qualimetrix\Infrastructure\Console\ResolvedRunConfiguration($this->runConfigurationFor($first), $this->cacheConfiguration($first, $projectRoot), $this->parallelConfiguration($first)), $ruleInputValidator->resolve($first, $firstInput), $firstInput, new BufferedOutput());
        self::assertContains('computed.first', $this->computedChannels($runtimeConfigurator));

        $second = $this->document([
            ConfigSchema::COMPUTED_METRICS => ['computed.second' => ['formula' => '1', 'levels' => ['class']]],
            ConfigSchema::ONLY_RULES => ['computed.second'],
        ]);
        $secondInput = new ArrayInput([], $command->getDefinition());
        $runtimeConfigurator->resetRunState();
        $runtimeConfigurator->configure($second, new \Qualimetrix\Infrastructure\Console\ResolvedRunConfiguration($this->runConfigurationFor($second), $this->cacheConfiguration($second, $projectRoot), $this->parallelConfiguration($second)), $ruleInputValidator->resolve($second, $secondInput), $secondInput, new BufferedOutput());
        self::assertContains('computed.second', $this->computedChannels($runtimeConfigurator));
        self::assertNotContains('computed.first', $this->computedChannels($runtimeConfigurator));

        $invalid = $this->document([
            ConfigSchema::COMPUTED_METRICS => ['computed.invalid' => ['formula' => 'm["computed.nonexistent"] + 1', 'levels' => ['class']]],
        ]);
        $invalidInput = new ArrayInput([], $command->getDefinition());
        $runtimeConfigurator->resetRunState();
        $this->expectException(RuntimeException::class);
        try {
            $runtimeConfigurator->configure($invalid, new \Qualimetrix\Infrastructure\Console\ResolvedRunConfiguration($this->runConfigurationFor($invalid), $this->cacheConfiguration($invalid, $projectRoot), $this->parallelConfiguration($invalid)), $ruleInputValidator->resolve($invalid, $invalidInput), $invalidInput, new BufferedOutput());
        } finally {
            $configuration = $this->ruleConfiguration($runtimeConfigurator);
            self::assertNull($configuration->enablement());
            try {
                $configuration->channelUniverse();
                self::fail('A failed preflight must leave no channel universe installed.');
            } catch (LogicException $error) {
                self::assertSame('Rule channels are unavailable before analysis preflight.', $error->getMessage());
            }
            $this->assertDefaultOwnerState($runtimeConfigurator);
        }
    }

    /** @return array{RuntimeConfigurator, CheckCommand, RuleInputValidator} */
    private function runtimeServices(): array
    {
        $container = (new ContainerFactory())->create();
        $runtimeConfigurator = $container->get(RuntimeConfigurator::class);
        $command = $container->get(CheckCommand::class);
        self::assertInstanceOf(RuntimeConfigurator::class, $runtimeConfigurator);
        self::assertInstanceOf(CheckCommand::class, $command);

        $ruleInputValidator = (new ReflectionProperty(CheckCommand::class, 'ruleInputValidator'))->getValue($command);
        self::assertInstanceOf(RuleInputValidator::class, $ruleInputValidator);
        $pipeline = $container->get(ConfigurationPipelineInterface::class);
        self::assertInstanceOf(ConfigurationPipelineInterface::class, $pipeline);
        $this->sections = LayeredDocument::sectionsOf($pipeline);

        return [$runtimeConfigurator, $command, $ruleInputValidator];
    }

    /** @param array<string, mixed> $values */
    private function document(array $values): ConfigurationDocument
    {
        return LayeredDocument::of(
            [['source' => 'test', 'values' => $values]],
            \Qualimetrix\Core\Path\AbsolutePath::fromString($this->temporaryDirectory),
            ...$this->sections,
        );
    }

    private function cacheStore(RuntimeConfigurator $runtimeConfigurator): CacheConfigurationStoreInterface
    {
        $factory = (new ReflectionProperty(RuntimeConfigurator::class, 'cacheFactory'))->getValue($runtimeConfigurator);
        self::assertInstanceOf(CacheFactory::class, $factory);
        $store = (new ReflectionProperty(CacheFactory::class, 'configurationStore'))->getValue($factory);
        self::assertInstanceOf(CacheConfigurationStoreInterface::class, $store);

        return $store;
    }

    private function cacheConfiguration(
        ConfigurationDocument $document,
        \Qualimetrix\Core\Path\AbsolutePath $projectRoot,
    ): CacheConfiguration {
        return (new CacheConfigurationResolver())->resolve($document, $projectRoot);
    }

    private function parallelStore(RuntimeConfigurator $runtimeConfigurator): ParallelConfigurationStoreInterface
    {
        $store = (new ReflectionProperty(RuntimeConfigurator::class, 'parallelConfigurationStore'))->getValue($runtimeConfigurator);
        self::assertInstanceOf(ParallelConfigurationStoreInterface::class, $store);

        return $store;
    }

    private function parallelConfiguration(
        ConfigurationDocument $document,
    ): ParallelConfiguration {
        return (new ParallelConfigurationResolver())->resolve($document);
    }

    private function assertDefaultOwnerState(RuntimeConfigurator $runtimeConfigurator): void
    {
        self::assertTrue($this->cacheStore($runtimeConfigurator)->current()->enabled);
        self::assertNull($this->parallelStore($runtimeConfigurator)->current()->workers);
        self::assertSame([], $this->ruleConfiguration($runtimeConfigurator)->all());
        self::assertFalse($this->ruleConfiguration($runtimeConfigurator)->capturesExcludedFindings());
        self::assertSame([], $this->lcomConfigurationStore($runtimeConfigurator)->current()->excludedMethods);
        self::assertFalse($this->profileReport($runtimeConfigurator)->isEnabled());
    }

    private function ruleConfiguration(RuntimeConfigurator $runtimeConfigurator): RuleConfigurationInterface
    {
        $analysisRuntime = (new ReflectionProperty(RuntimeConfigurator::class, 'analysisRuntimeConfigurator'))->getValue($runtimeConfigurator);
        $ruleConfiguration = (new ReflectionProperty($analysisRuntime::class, 'ruleOptionsRegistry'))->getValue($analysisRuntime);
        self::assertInstanceOf(RuleConfigurationInterface::class, $ruleConfiguration);

        return $ruleConfiguration;
    }

    private function lcomConfigurationStore(RuntimeConfigurator $runtimeConfigurator): LcomCollectionConfigurationStoreInterface
    {
        $analysisRuntime = (new ReflectionProperty(RuntimeConfigurator::class, 'analysisRuntimeConfigurator'))->getValue($runtimeConfigurator);
        $lcomConfigurationStore = (new ReflectionProperty($analysisRuntime::class, 'lcomConfigurationStore'))->getValue($analysisRuntime);
        self::assertInstanceOf(LcomCollectionConfigurationStoreInterface::class, $lcomConfigurationStore);

        return $lcomConfigurationStore;
    }

    private function profileReport(RuntimeConfigurator $runtimeConfigurator): ProfileReportInterface
    {
        $profileReport = (new ReflectionProperty(RuntimeConfigurator::class, 'profileSession'))->getValue($runtimeConfigurator);
        self::assertInstanceOf(ProfileReportInterface::class, $profileReport);

        return $profileReport;
    }

    /** @return list<string> */
    private function computedChannels(RuntimeConfigurator $runtimeConfigurator): array
    {
        $channels = $this->ruleConfiguration($runtimeConfigurator)->channelUniverse();

        return array_values(array_map(
            static fn($channel): string => $channel->code,
            $channels->channelsProducedBy('computed'),
        ));
    }
    private function runConfigurationFor(ConfigurationDocument $document): RunConfiguration
    {
        $root = $document->workingDirectory();

        return new RunConfiguration(
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Include,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $root, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [$root], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Narrowed, uncoveredRoots: ['uncovered']),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        );
    }

}
