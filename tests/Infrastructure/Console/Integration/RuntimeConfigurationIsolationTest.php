<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationStoreInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationException;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationResolver;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationStoreInterface;
use Qualimetrix\Infrastructure\Console\AnalysisRuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Parallel\Configuration\ParallelConfigurationResolver;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationStoreInterface;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileReportInterface;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class RuntimeConfigurationIsolationTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/qmx-runtime-isolation-' . uniqid('', true);
        mkdir($this->temporaryDirectory, 0o755, true);
    }

    protected function tearDown(): void
    {
        rmdir($this->temporaryDirectory);
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
        $runtimeConfigurator->configure(
            $customDocument,
            $ruleInputValidator->resolve($customDocument, new ArrayInput([], $command->getDefinition())),
            $this->cacheConfiguration($customDocument, $projectRoot),
            $this->parallelConfiguration($customDocument),
            new ArrayInput([], $command->getDefinition()),
            new BufferedOutput(),
        );

        self::assertSame($customCacheDirectory, $this->cacheStore($runtimeConfigurator)->current()->directory->value());
        self::assertFalse($this->cacheStore($runtimeConfigurator)->current()->enabled);
        self::assertSame(0, $this->parallelStore($runtimeConfigurator)->current()->workers);

        $runtimeConfigurator->resetRunState();
        $defaultDocument = $this->document([]);
        $runtimeConfigurator->configure(
            $defaultDocument,
            $ruleInputValidator->resolve($defaultDocument, new ArrayInput([], $command->getDefinition())),
            $this->cacheConfiguration($defaultDocument, $projectRoot),
            $this->parallelConfiguration($defaultDocument),
            new ArrayInput([], $command->getDefinition()),
            new BufferedOutput(),
        );

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
        $invalidDocument = $this->document(['parallel.workers' => -1]);

        try {
            $runtimeConfigurator->configure(
                $invalidDocument,
                $ruleInputValidator->resolve($invalidDocument, new ArrayInput([], $command->getDefinition())),
                $this->cacheConfiguration($invalidDocument, $projectRoot),
                $this->parallelConfiguration($invalidDocument),
                new ArrayInput([], $command->getDefinition()),
                new BufferedOutput(),
            );
            self::fail('Invalid parallel configuration must fail before mutating owner stores.');
        } catch (InvalidArgumentException) {
        }

        self::assertTrue($this->cacheStore($runtimeConfigurator)->current()->enabled);
        self::assertNull($this->parallelStore($runtimeConfigurator)->current()->workers);

        $defaultDocument = $this->document([]);
        $runtimeConfigurator->configure(
            $defaultDocument,
            $ruleInputValidator->resolve($defaultDocument, new ArrayInput([], $command->getDefinition())),
            $this->cacheConfiguration($defaultDocument, $projectRoot),
            $this->parallelConfiguration($defaultDocument),
            new ArrayInput([], $command->getDefinition()),
            new BufferedOutput(),
        );

        self::assertTrue($this->cacheStore($runtimeConfigurator)->current()->enabled);
        self::assertNull($this->parallelStore($runtimeConfigurator)->current()->workers);
    }

    #[Test]
    public function itKeepsEveryOwnerStoreAtDefaultsAfterLateArchitectureFailureInTheCompiledContainer(): void
    {
        [$runtimeConfigurator, $command, $ruleInputValidator] = $this->runtimeServices();
        $runtimeConfigurator->resetRunState();
        $projectRoot = \Qualimetrix\Core\Path\AbsolutePath::fromString($this->temporaryDirectory);
        $invalidDocument = $this->document([
            'architecture' => ['layers' => ['not-an-ordered-list']],
        ]);
        $input = new ArrayInput(['--profile' => true], $command->getDefinition());

        try {
            $runtimeConfigurator->configure(
                $invalidDocument,
                $ruleInputValidator->resolve($invalidDocument, $input),
                $this->cacheConfiguration($invalidDocument, $projectRoot),
                $this->parallelConfiguration($invalidDocument),
                $input,
                new BufferedOutput(),
            );
            self::fail('Invalid architecture configuration must fail before mutating owner stores or effects.');
        } catch (ArchitectureConfigurationException) {
        }

        $this->assertDefaultOwnerState($runtimeConfigurator);

        $defaultDocument = $this->document([]);
        $defaultInput = new ArrayInput([], $command->getDefinition());
        $runtimeConfigurator->configure(
            $defaultDocument,
            $ruleInputValidator->resolve($defaultDocument, $defaultInput),
            $this->cacheConfiguration($defaultDocument, $projectRoot),
            $this->parallelConfiguration($defaultDocument),
            $defaultInput,
            new BufferedOutput(),
        );

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
            'computedMetrics' => ['computed.first' => ['formula' => '1', 'levels' => ['class']]],
            'onlyRules' => ['computed.first'],
        ]);
        $firstInput = new ArrayInput([], $command->getDefinition());
        $runtimeConfigurator->resetRunState();
        $runtimeConfigurator->configure(
            $first,
            $ruleInputValidator->resolve($first, $firstInput),
            $this->cacheConfiguration($first, $projectRoot),
            $this->parallelConfiguration($first),
            $firstInput,
            new BufferedOutput(),
        );
        self::assertContains('computed.health#computed.first', $this->computedChannels($runtimeConfigurator));

        $second = $this->document([
            'computedMetrics' => ['computed.second' => ['formula' => '1', 'levels' => ['class']]],
            'onlyRules' => ['computed.second'],
        ]);
        $secondInput = new ArrayInput([], $command->getDefinition());
        $runtimeConfigurator->resetRunState();
        $runtimeConfigurator->configure(
            $second,
            $ruleInputValidator->resolve($second, $secondInput),
            $this->cacheConfiguration($second, $projectRoot),
            $this->parallelConfiguration($second),
            $secondInput,
            new BufferedOutput(),
        );
        self::assertContains('computed.health#computed.second', $this->computedChannels($runtimeConfigurator));
        self::assertNotContains('computed.health#computed.first', $this->computedChannels($runtimeConfigurator));

        $invalid = $this->document([
            'computedMetrics' => ['computed.invalid' => ['formula' => '(', 'levels' => ['class']]],
        ]);
        $invalidInput = new ArrayInput([], $command->getDefinition());
        $runtimeConfigurator->resetRunState();
        $this->expectException(RuntimeException::class);
        try {
            $runtimeConfigurator->configure(
                $invalid,
                $ruleInputValidator->resolve($invalid, $invalidInput),
                $this->cacheConfiguration($invalid, $projectRoot),
                $this->parallelConfiguration($invalid),
                $invalidInput,
                new BufferedOutput(),
            );
        } finally {
            self::assertContains('computed.health#computed.second', $this->computedChannels($runtimeConfigurator));
            self::assertNotContains('computed.health#computed.invalid', $this->computedChannels($runtimeConfigurator));
            self::assertNotContains('computed.health#computed.first', $this->computedChannels($runtimeConfigurator));
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

        return [$runtimeConfigurator, $command, $ruleInputValidator];
    }

    /** @param array<string, mixed> $values */
    private function document(array $values): ConfigurationDocument
    {
        return new ConfigurationDocument(
            [['source' => 'test', 'values' => $values]],
            \Qualimetrix\Core\Path\AbsolutePath::fromString($this->temporaryDirectory),
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
        self::assertFalse($this->ruleConfiguration($runtimeConfigurator)->capturesExcludedViolations());
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
        $analysisRuntime = (new ReflectionProperty(RuntimeConfigurator::class, 'analysisRuntimeConfigurator'))
            ->getValue($runtimeConfigurator);
        self::assertInstanceOf(AnalysisRuntimeConfigurator::class, $analysisRuntime);
        $validator = (new ReflectionProperty(AnalysisRuntimeConfigurator::class, 'ruleInputValidator'))
            ->getValue($analysisRuntime);
        self::assertInstanceOf(RuleInputValidator::class, $validator);
        $selector = (new ReflectionProperty(RuleInputValidator::class, 'ruleSelector'))->getValue($validator);
        self::assertInstanceOf(RuleSelector::class, $selector);
        $channels = (new ReflectionProperty(RuleSelector::class, 'channels'))->getValue($selector);

        return array_values(array_map(
            static fn($channel): string => $channel->toKey(),
            $channels->channelsProducedBy('computed.health'),
        ));
    }
}
