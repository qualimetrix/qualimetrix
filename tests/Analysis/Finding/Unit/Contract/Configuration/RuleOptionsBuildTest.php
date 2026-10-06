<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Contract\Configuration;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\CommandLinePathWrite;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\Loader\CommandLineLayer;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\GotoRule;
use Qualimetrix\Analysis\Evidence\Complexity\CognitiveComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\NpathComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\NpathComplexityRule;
use Qualimetrix\Analysis\Evidence\Coupling\CboOptions;
use Qualimetrix\Analysis\Evidence\Coupling\InstabilityOptions;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityOptions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleEnablementResolver;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection;
use Qualimetrix\Analysis\Finding\RuleExecution;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassOptions;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[\PHPUnit\Framework\Attributes\CoversClass(\Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild::class)]
final class RuleOptionsBuildTest extends TestCase
{
    #[Test]
    public function itRetainsTheAuthoredModeWriterWhenTheFinalModeIsActive(): void
    {
        $metadata = [new RuleMetadata('architecture.unassigned-class', UnassignedClassOptions::class, '', [], false)];
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['rules' => ['architecture.unassigned-class' => ['mode' => 'warn']]]],
        ], AbsolutePath::fromString('/project'), $metadata);

        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata);
        $activity = $ready->resolvedOptions?->activityOf('architecture.unassigned-class', SymbolLevel::Project);
        self::assertNotNull($activity);
        self::assertTrue($activity->active);
        self::assertSame('rules.architecture.unassigned-class.mode: warn', $activity->written);
        self::assertSame(0, $activity->rank());
        $writer = $activity->decidedBy;
        self::assertNotNull($writer);
        self::assertSame('rules.architecture.unassigned-class.mode', $writer->displayPath());
        self::assertSame(ConfigurationSource::ConfigFile, $writer->origin->source());

        $default = ResolvedOptionsFixture::ready(FindingConfiguration::none(), $metadata)
            ->resolvedOptions?->activityOf('architecture.unassigned-class', SymbolLevel::Project);
        self::assertNotNull($default);
        self::assertFalse($default->active);
        self::assertNull($default->written);
        self::assertNull($default->decidedBy);
        self::assertSame(-1, $default->rank());
    }

    #[Test]
    public function itKeepsFullCliStatementsInSelectionAndActivity(): void
    {
        $surface = RuleOptionSurface::of(ComplexityOptions::class);
        $writes = [];
        foreach (['enabled' => 'false', 'class.enabled' => 'false'] as $option => $text) {
            $address = $surface->locate($option);
            self::assertNotNull($address);
            $path = ['rules', 'complexity.ccn', ...explode('.', $option)];
            $writes[] = new CommandLinePathWrite($path, $text, '--rule-opt', '--rule-opt=complexity.ccn:' . $option . '=' . $text, $surface->schemaAt($address));
        }
        $configuration = self::prepareLayers([CommandLineLayer::of(new ConfigurationResolutionRequest(
            AbsolutePath::fromString('/project'),
            cliPathWrites: $writes,
        ))]);
        $statements = \Qualimetrix\Analysis\Finding\Selection\AuthoredSelection::statements($configuration->document);
        self::assertSame('--rule-opt=complexity.ccn:enabled=false', $statements[0]['text']);
        $activity = $configuration->resolvedOptions?->activityOf('complexity.ccn', SymbolLevel::Class_);
        self::assertNotNull($activity);
        self::assertSame('--rule-opt=complexity.ccn:class.enabled=false', $activity->written);
    }

    #[Test]
    public function itKeepsTheWrittenFilterExpressionInsteadOfRebuildingItsList(): void
    {
        $origin = ConfigurationOrigin::of(ConfigurationSource::CommandLine)->locatedAtAuthoredWrite('--only-rule', '--only-rule=complexity.ccn');
        $writer = new \Qualimetrix\Analysis\Configuration\Contract\Document\Provenance($origin, null, 0);
        $filter = new \Qualimetrix\Analysis\Finding\Contract\SelectionFilter(['complexity.ccn'], $writer);
        self::assertSame(['--only-rule=complexity.ccn', $writer], \Qualimetrix\Analysis\Finding\Selection\SelectionCauses::filter($filter));
    }

    #[Test]
    public function itKeepsTheFullCliExpressionForAMutedMode(): void
    {
        $metadata = [new RuleMetadata('architecture.unassigned-class', UnassignedClassOptions::class, '', [], false)];
        $execution = ResolvedOptionsFixture::execution($metadata);
        $surface = RuleOptionSurface::of(UnassignedClassOptions::class);
        $address = $surface->locate('mode');
        self::assertNotNull($address);
        $layer = CommandLineLayer::of(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), cliPathWrites: [
            new CommandLinePathWrite(['rules', 'architecture.unassigned-class', 'mode'], 'ignore', '--rule-opt', '--rule-opt=architecture.unassigned-class:mode=ignore', $surface->schemaAt($address)),
        ]));
        $document = DocumentComposer::compose(new DocumentSchema([new RulesSection($execution, 'rules'), new RulesSection($execution, 'only_rules'), new RulesSection($execution, 'disabled_rules')]), [$layer]);
        $stated = (new RuleEnablementResolver())->decide($document, ResolvedOptionsFixture::universe($metadata));
        $options = (new RuleOptionsBuild($execution))->build(new FindingConfiguration($document), $stated);
        $decision = $stated->decisionFor('architecture.unassigned-class');
        $cell = new \Qualimetrix\Analysis\Finding\Contract\EnablementDecision(
            new \Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress($decision->producer, $decision->channel, $decision->level, $decision->role),
            new \Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision(
                \Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch::On,
                \Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission::Direct,
            ),
            $options->activityOf('architecture.unassigned-class', SymbolLevel::Project),
        );
        self::assertSame('--rule-opt=architecture.unassigned-class:mode=ignore (the command line)', \Qualimetrix\Analysis\Finding\Selection\SelectionCauses::mutedMode($cell));
    }

    #[Test]
    public function itDefersConstructionAndReplacesEveryMaterializedObjectWithTheInvocationSnapshot(): void
    {
        $registry = new RuleOptionsRegistry();
        $observed = new class {
            /** @var list<GotoRule> */
            public array $rules = [];
            /** @var list<CodeSmellOptions> */
            public array $options = [];
            public int $validators = 0;
        };
        $execution = new RuleExecution(
            [[
                'metadata' => new RuleMetadata(GotoRule::NAME, CodeSmellOptions::class, GotoRule::getDescription(), [], false),
                'create' => static function () use ($registry, $observed): GotoRule {
                    $current = $registry->optionsFor(GotoRule::NAME, CodeSmellOptions::class);
                    if (!$current instanceof CodeSmellOptions) {
                        throw new LogicException('Wrong options class.');
                    }
                    $observed->options[] = $current;
                    return $observed->rules[] = new GotoRule($current);
                },
            ]],
            self::createStub(ProfilerInterface::class),
            $registry,
            configurationValidators: [[
                'producer' => GotoRule::NAME,
                'create' => static function () use ($observed): ConfigurationValidatorInterface {
                    ++$observed->validators;
                    return new class implements ConfigurationValidatorInterface {
                        public static function producerRuleName(): string
                        {
                            return GotoRule::NAME;
                        }
                        public static function shape(): ChannelShape
                        {
                            return ChannelShape::Occurrence;
                        }
                        public static function channelDeclarations(): array
                        {
                            return [];
                        }
                        public function validate(AnalysisContext $context): array
                        {
                            return [];
                        }
                    };
                },
            ]],
        );
        self::assertCount(1, $execution->allRules());
        self::assertSame([], $observed->rules);
        self::assertSame(0, $observed->validators);
        $builder = new RuleOptionsBuild($execution);
        $prepare = static function (FindingConfiguration $input) use ($execution, $builder): FindingConfiguration {
            $metadata = $execution->allRules();
            $configuration = $input->document->roots() === [] ? ResolvedOptionsFixture::authoredConfiguration([], $metadata) : $input;
            $channels = ResolvedOptionsFixture::universe($metadata, [GotoRule::NAME => [\Qualimetrix\Core\Symbol\SymbolLevel::Callable]]);
            $resolver = new RuleEnablementResolver();
            $stated = $resolver->decide($configuration->document, $channels);
            $options = $builder->build($configuration, $stated);
            return $configuration->withChannelUniverse($channels)->withResolvedOptions($options)
                ->withEnablement($resolver->conclude($stated, $options));
        };
        $first = $prepare(FindingConfiguration::none());
        self::assertSame([], $observed->rules);
        $registry->replace($first);
        $context = new AnalysisContext(self::createStub(MetricRepositoryInterface::class));
        $execution->execute($context);
        $execution->execute($context);
        self::assertCount(1, $observed->rules);
        self::assertSame(1, $observed->validators);
        self::assertSame($first->resolvedOptions?->for(GotoRule::NAME), $observed->options[0]);
        $registry->resetRuntimeState();
        try {
            $execution->levelActivity();
            self::fail('Activity read an unconfigured invocation.');
        } catch (LogicException $error) {
            self::assertSame('Rule options are unavailable before analysis preflight.', $error->getMessage());
        }
        self::assertCount(1, $execution->allRules());
        self::assertCount(1, $observed->rules);
        $second = $prepare(FindingConfiguration::fromDocument(\Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::document([['source' => 'config', 'values' => ['rules' => [GotoRule::NAME => ['enabled' => false]]]]], \Qualimetrix\Core\Path\AbsolutePath::fromString('/project'))));
        $registry->replace($second);
        self::assertFalse($execution->levelActivity()->toMap()[GotoRule::NAME]['callable']);
        self::assertCount(1, $observed->rules);
        self::assertSame(1, $observed->validators);
        $execution->execute($context);
        self::assertCount(1, $observed->rules);
        self::assertSame(1, $observed->validators);
        $firstOptions = $first->resolvedOptions;
        $secondOptions = $second->resolvedOptions;
        self::assertNotNull($secondOptions);
        self::assertNotSame($firstOptions->for(GotoRule::NAME), $secondOptions->for(GotoRule::NAME));

        $third = $prepare(FindingConfiguration::fromDocument(\Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::document([['source' => 'config', 'values' => ['rules' => [GotoRule::NAME => ['enabled' => true]]]]], \Qualimetrix\Core\Path\AbsolutePath::fromString('/project'))));
        $registry->replace($third);
        self::assertTrue($execution->levelActivity()->toMap()[GotoRule::NAME]['callable']);
        self::assertCount(1, $observed->rules);
        self::assertSame(1, $observed->validators);
        $execution->execute($context);
        self::assertCount(2, $observed->rules);
        self::assertSame(2, $observed->validators);
        self::assertNotSame($observed->rules[0], $observed->rules[1]);
        self::assertNotSame($observed->options[0], $observed->options[1]);
        self::assertSame($third->resolvedOptions?->for(GotoRule::NAME), $observed->options[1]);
    }

    #[Test]
    public function itRefusesOptionsLookupBeforePreflight(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('Rule options are unavailable before analysis preflight.');
        (new RuleOptionsRegistry())->optionsFor(GotoRule::NAME, CodeSmellOptions::class);
    }
    #[Test]
    public function itRefusesAnInvertedRisingBandBeforeConstructingRules(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Warning threshold 30 must be less than or equal to error threshold 12. Warning: 30 from "rules.complexity.ccn.callable.warning" in configuration file "/project/qmx.yaml"; error: 12 from "rules.complexity.ccn.callable.error" in configuration file "/project/qmx.yaml".');
        self::buildLayers([self::file(['complexity.ccn' => ['callable' => ['warning' => 30, 'error' => 12]]])]);
    }

    #[Test]
    public function itActivatesAWrittenBandOnANormallyMutedClass(): void
    {
        $options = self::buildLayers([self::file(['complexity.npath' => ['class' => ['threshold' => 22]]])])->for('complexity.npath');
        self::assertInstanceOf(NpathComplexityOptions::class, $options);
        self::assertTrue($options->class->enabled);
        self::assertSame(22, $options->class->maxWarning);
        self::assertSame(22, $options->class->maxError);
    }

    /** @return iterable<string, array{array<string, int>, string, list<string>}> */
    public static function invalidRisingHalves(): iterable
    {
        yield 'warning above the owning error default' => [
            ['warning' => 100],
            'Warning threshold 100 must be less than or equal to error threshold 20. Warning: 100 from "rules.complexity.ccn.callable.warning" in configuration file "/project/qmx.yaml"; error: default 20.',
            ['rules', 'complexity.ccn', 'callable', 'warning'],
        ];
        yield 'error below the owning warning default' => [
            ['error' => 5],
            'Warning threshold 10 must be less than or equal to error threshold 5. Warning: default 10; error: 5 from "rules.complexity.ccn.callable.error" in configuration file "/project/qmx.yaml".',
            ['rules', 'complexity.ccn', 'callable', 'error'],
        ];
    }

    /**
     * @param array<string, int> $half
     * @param list<string> $position
     */
    #[Test]
    #[DataProvider('invalidRisingHalves')]
    public function itNamesAnUnwrittenBandHalfAsTheOwningDefault(array $half, string $summary, array $position): void
    {
        try {
            self::buildLayers([self::file(['complexity.ccn' => ['callable' => $half]])]);
            self::fail('The effective rising band was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame($summary, $refusal->summary());
            self::assertSame(['/project/qmx.yaml'], array_map(static fn(ConfigurationOrigin $origin): ?string => $origin->locator(), $refusal->sources()));
            self::assertSame($position, $refusal->position()?->segments);
        }
    }

    /** @return iterable<string, array{array<string, int>, string}> */
    public static function invalidFallingHalves(): iterable
    {
        yield 'both authored halves' => [
            ['warning' => 10, 'error' => 40],
            'Warning threshold 10 must be greater than or equal to error threshold 40. Warning: 10 from "rules.maintainability.mi.warning" in configuration file "/project/qmx.yaml"; error: 40 from "rules.maintainability.mi.error" in configuration file "/project/qmx.yaml".',
        ];
        yield 'written warning below the owning error default' => [
            ['warning' => 10],
            'Warning threshold 10 must be greater than or equal to error threshold 20. Warning: 10 from "rules.maintainability.mi.warning" in configuration file "/project/qmx.yaml"; error: default 20.',
        ];
    }

    /** @param array<string, int> $halves */
    #[Test]
    #[DataProvider('invalidFallingHalves')]
    public function itRefusesAnInvertedFallingBandIncludingOneWrittenHalf(array $halves, string $summary): void
    {
        try {
            self::buildLayers([self::file(['maintainability.mi' => $halves])]);
            self::fail('The effective falling band was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame($summary, $refusal->summary());
            self::assertSame('/project/qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame(['rules', 'maintainability.mi', \array_key_exists('error', $halves) ? 'error' : 'warning'], $refusal->position()?->segments);
        }
    }

    #[Test]
    public function itKeepsFallingDefaultsEqualityAndSeverityBoundaries(): void
    {
        $defaults = self::buildLayers([])->for('maintainability.mi');
        self::assertInstanceOf(MaintainabilityOptions::class, $defaults);
        self::assertSame(40.0, $defaults->warning);
        self::assertSame(20.0, $defaults->error);
        self::assertNull($defaults->getSeverity(40));
        self::assertSame(Severity::Warning, $defaults->getSeverity(20));
        self::assertSame(Severity::Error, $defaults->getSeverity(19));
        foreach ([['warning' => 50, 'error' => 40], ['warning' => 40, 'error' => 40], ['error' => 30], ['threshold' => 22]] as $input) {
            $options = self::buildLayers([self::file(['maintainability.mi' => $input])])->for('maintainability.mi');
            self::assertInstanceOf(MaintainabilityOptions::class, $options);
            self::assertSame((float) ($input['threshold'] ?? $input['warning'] ?? 40), $options->warning);
            self::assertSame((float) ($input['threshold'] ?? $input['error'] ?? 20), $options->error);
        }
    }

    /** @return iterable<string, array{array<string, int>, array<string, int>, string, list<string>}> */
    public static function splitBands(): iterable
    {
        yield 'preset error and file warning' => [
            ['error' => 1], ['warning' => 100],
            'Warning threshold 100 must be less than or equal to error threshold 1. Warning: 100 from "rules.complexity.ccn.callable.warning" in configuration file "/project/qmx.yaml"; error: 1 from "rules.complexity.ccn.callable.error" in preset "strict".',
            ['rules', 'complexity.ccn', 'callable', 'warning'],
        ];
        yield 'preset warning and file error' => [
            ['warning' => 100], ['error' => 1],
            'Warning threshold 100 must be less than or equal to error threshold 1. Warning: 100 from "rules.complexity.ccn.callable.warning" in preset "strict"; error: 1 from "rules.complexity.ccn.callable.error" in configuration file "/project/qmx.yaml".',
            ['rules', 'complexity.ccn', 'callable', 'error'],
        ];
        yield 'expanded shorthand retains its authored path' => [
            ['threshold' => 1], ['warning' => 100],
            'Warning threshold 100 must be less than or equal to error threshold 1. Warning: 100 from "rules.complexity.ccn.callable.warning" in configuration file "/project/qmx.yaml"; error: 1 from "rules.complexity.ccn.callable.threshold" in preset "strict".',
            ['rules', 'complexity.ccn', 'callable', 'warning'],
        ];
    }

    /**
     * @param array<string, int> $preset
     * @param array<string, int> $file
     * @param list<string> $position
     */
    #[Test]
    #[DataProvider('splitBands')]
    public function itNamesBothWinningLayersAndAddressesTheHigherWriter(array $preset, array $file, string $summary, array $position): void
    {
        $lower = new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::Preset, 'strict'), AuthoredNode::fromPlain(['rules' => ['complexity.ccn' => ['callable' => $preset]]]));
        try {
            self::buildLayers([$lower, self::file(['complexity.ccn' => ['callable' => $file]])]);
            self::fail('The effective cross-layer band was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame($summary, $refusal->summary());
            self::assertSame(['strict', '/project/qmx.yaml'], array_map(static fn(ConfigurationOrigin $origin): ?string => $origin->locator(), $refusal->sources()));
            self::assertSame($position, $refusal->position()?->segments);
        }
    }

    #[Test]
    public function itPreservesTheActualCliLocatorWithoutInventingADocumentPosition(): void
    {
        $surface = RuleOptionSurface::of(ComplexityOptions::class);
        $address = $surface->locate('callable.warning');
        self::assertNotNull($address);
        $cli = CommandLineLayer::of(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), cliPathWrites: [
            new CommandLinePathWrite(['rules', 'complexity.ccn', 'callable', 'warning'], '30', '--cyclomatic-warning', '--cyclomatic-warning=30', $surface->schemaAt($address)),
        ]));
        try {
            self::buildLayers([self::file(['complexity.ccn' => ['callable' => ['error' => 12]]]), $cli]);
            self::fail('The effective CLI/file band was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('Warning threshold 30 must be less than or equal to error threshold 12. Warning: 30 from option --cyclomatic-warning (written as --cyclomatic-warning=30); error: 12 from "rules.complexity.ccn.callable.error" in configuration file "/project/qmx.yaml".', $refusal->summary());
            self::assertSame(['/project/qmx.yaml', '--cyclomatic-warning'], array_map(static fn(ConfigurationOrigin $origin): ?string => $origin->locator(), $refusal->sources()));
            self::assertNull($refusal->position());
        }
        try {
            self::buildLayers([$cli]);
            self::fail('The CLI half above the owning default was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('Warning threshold 30 must be less than or equal to error threshold 20. Warning: 30 from option --cyclomatic-warning (written as --cyclomatic-warning=30); error: default 20.', $refusal->summary());
            self::assertSame(['--cyclomatic-warning'], array_map(static fn(ConfigurationOrigin $origin): ?string => $origin->locator(), $refusal->sources()));
            self::assertNull($refusal->position());
        }
    }

    #[Test]
    public function itNamesTheLineAndAuthoredSpellingOfBothBandHalves(): void
    {
        $layer = new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/lined.yaml'), AuthoredNode::mapping([
            'rules' => AuthoredNode::mapping(['complexity.ccn' => AuthoredNode::mapping(['class' => AuthoredNode::mapping([
                'maxWarning' => AuthoredNode::scalar(100, line: 9),
                'max_error' => AuthoredNode::scalar(1, line: 11),
            ])])]),
        ]));
        try {
            self::buildLayers([$layer]);
            self::fail('The effective band was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('Warning threshold 100 must be less than or equal to error threshold 1. Warning: 100 from "rules.complexity.ccn.class.maxWarning" in configuration file "/lined.yaml" at line 9; error: 1 from "rules.complexity.ccn.class.max_error" in configuration file "/lined.yaml" at line 11.', $refusal->summary());
            self::assertSame(['/lined.yaml'], array_map(static fn(ConfigurationOrigin $origin): ?string => $origin->locator(), $refusal->sources()));
            self::assertSame(['rules', 'complexity.ccn', 'class', 'max_error'], $refusal->position()?->segments);
        }
    }

    #[Test]
    public function itNamesOnlyTheWinningWriteOfAnOverriddenBandHalf(): void
    {
        $old = new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/old.yaml'), AuthoredNode::fromPlain(['rules' => ['complexity.ccn' => ['callable' => ['warning' => 999]]]]));
        try {
            self::buildLayers([$old, self::file(['complexity.ccn' => ['callable' => ['warning' => 30, 'error' => 12]]])]);
            self::fail('The effective band was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('Warning threshold 30 must be less than or equal to error threshold 12. Warning: 30 from "rules.complexity.ccn.callable.warning" in configuration file "/project/qmx.yaml"; error: 12 from "rules.complexity.ccn.callable.error" in configuration file "/project/qmx.yaml".', $refusal->summary());
            self::assertSame(['/project/qmx.yaml'], array_map(static fn(ConfigurationOrigin $origin): ?string => $origin->locator(), $refusal->sources()));
        }
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool, int, int}> */
    public static function mutedClassLayers(): iterable
    {
        yield 'untouched owning defaults' => [[], [], false, 500, 1000];
        yield 'preset threshold' => [['threshold' => 22], [], true, 22, 22];
        yield 'file threshold' => [[], ['threshold' => 22], true, 22, 22];
        yield 'zero remains written' => [[], ['threshold' => 0], true, 0, 0];
        yield 'written warning half' => [[], ['max-warning' => 600], true, 600, 1000];
        yield 'written error half' => [['max-error' => 700], [], true, 500, 700];
        yield 'explicit false survives a later band' => [['enabled' => false], ['threshold' => 22], false, 22, 22];
        yield 'later explicit false suppresses an earlier band' => [['threshold' => 22], ['enabled' => false], false, 22, 22];
        yield 'explicit true without a band' => [[], ['enabled' => true], true, 500, 1000];
        yield 'null is unwritten' => [[], ['threshold' => null, 'enabled' => null], false, 500, 1000];
    }

    /**
     * @param array<string, mixed> $preset
     * @param array<string, mixed> $file
     */
    #[Test]
    #[DataProvider('mutedClassLayers')]
    public function itUsesAuthoredBandWritesWithoutOverridingExplicitLevelEnablement(array $preset, array $file, bool $enabled, int $warning, int $error): void
    {
        $lower = new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::Preset, 'strict'), AuthoredNode::fromPlain(['rules' => ['complexity.npath' => ['class' => $preset]]]));
        $options = self::buildLayers([$lower, self::file(['complexity.npath' => ['class' => $file]])])->for('complexity.npath');
        self::assertInstanceOf(NpathComplexityOptions::class, $options);
        self::assertSame($enabled, $options->class->enabled);
        self::assertSame($warning, $options->class->maxWarning);
        self::assertSame($error, $options->class->maxError);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function nestedBands(): iterable
    {
        yield 'CCN callable' => ['complexity.ccn', 'callable', 'warning', 'error'];
        yield 'CCN class' => ['complexity.ccn', 'class', 'max-warning', 'max-error'];
        yield 'cognitive callable' => ['complexity.cognitive', 'callable', 'warning', 'error'];
        yield 'cognitive class' => ['complexity.cognitive', 'class', 'max-warning', 'max-error'];
        yield 'NPath callable' => ['complexity.npath', 'callable', 'warning', 'error'];
        yield 'NPath class' => ['complexity.npath', 'class', 'max-warning', 'max-error'];
        yield 'CBO class' => ['coupling.cbo', 'class', 'warning', 'error'];
        yield 'CBO namespace' => ['coupling.cbo', 'namespace', 'warning', 'error'];
        yield 'instability class' => ['coupling.instability', 'class', 'max-warning', 'max-error'];
        yield 'instability namespace' => ['coupling.instability', 'namespace', 'max-warning', 'max-error'];
    }

    #[Test]
    #[DataProvider('nestedBands')]
    public function itPrefixesBothBandRecordsAtEveryHierarchicalLevel(string $producer, string $level, string $warning, string $error): void
    {
        try {
            self::buildLayers([self::file([$producer => [$level => [$warning => 10000, $error => 1]]])]);
            self::fail('The nested band was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(\sprintf('Warning threshold 10000 must be less than or equal to error threshold 1. Warning: 10000 from "rules.%s.%s.%s" in configuration file "/project/qmx.yaml"; error: 1 from "rules.%s.%s.%s" in configuration file "/project/qmx.yaml".', $producer, $level, $warning, $producer, $level, $error), $refusal->summary());
            self::assertSame(['rules', $producer, $level, $error], $refusal->position()?->segments);
        }
    }

    #[Test]
    public function itPublishesCurrentClassActivityFromTheBuiltMutedLevelOptions(): void
    {
        $prepared = self::prepareLayers([self::file(['complexity.npath' => ['class' => ['threshold' => 22]]])]);
        $registry = new RuleOptionsRegistry();
        $registry->replace($prepared);
        $execution = new RuleExecution([[
            'metadata' => new RuleMetadata('complexity.npath', NpathComplexityOptions::class, '', [], false),
            'create' => static function () use ($registry): NpathComplexityRule {
                $options = $registry->optionsFor('complexity.npath', NpathComplexityOptions::class);
                if (!$options instanceof NpathComplexityOptions) {
                    throw new LogicException('The NPath lookup received another producer.');
                }
                return new NpathComplexityRule($options);
            },
        ]], self::createStub(ProfilerInterface::class), $registry);
        self::assertTrue($execution->levelActivity()->toMap()['complexity.npath']['class']);
        $disabled = self::prepareLayers([self::file(['complexity.npath' => ['class' => ['enabled' => false, 'threshold' => 22]]])]);
        $registry->replace($disabled);
        self::assertFalse($execution->levelActivity()->toMap()['complexity.npath']['class']);
    }

    #[Test]
    public function itKeepsTheRootOffSwitchAboveAuthoredMutedLevelBands(): void
    {
        $options = self::buildLayers([self::file(['complexity.npath' => ['enabled' => false, 'class' => ['threshold' => 22]]])])->for('complexity.npath');
        self::assertInstanceOf(NpathComplexityOptions::class, $options);
        self::assertFalse($options->class->enabled);
        self::assertFalse($options->callable->enabled);
        self::assertSame(22, $options->class->maxWarning);
        self::assertSame(22, $options->class->maxError);
    }

    /** @param array<string, mixed> $rules */
    private static function file(array $rules): AuthoredLayer
    {
        return new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/project/qmx.yaml'), AuthoredNode::fromPlain(['rules' => $rules]));
    }

    /** @param list<AuthoredLayer> $layers */
    private static function buildLayers(array $layers): ResolvedRuleOptions
    {
        return self::prepareLayers($layers)->resolvedOptions
            ?? throw new LogicException('The prepared fixture must contain resolved options.');
    }

    /** @param list<AuthoredLayer> $layers */
    private static function prepareLayers(array $layers): FindingConfiguration
    {
        $metadata = [
            new RuleMetadata('complexity.ccn', ComplexityOptions::class, '', [], false),
            new RuleMetadata('complexity.cognitive', CognitiveComplexityOptions::class, '', [], false),
            new RuleMetadata('coupling.cbo', CboOptions::class, '', [], false),
            new RuleMetadata('coupling.instability', InstabilityOptions::class, '', [], false),
            new RuleMetadata('complexity.npath', NpathComplexityOptions::class, '', [], false),
            new RuleMetadata('maintainability.mi', MaintainabilityOptions::class, '', [], false),
        ];
        $execution = ResolvedOptionsFixture::execution($metadata);
        $document = DocumentComposer::compose(new DocumentSchema([
            new RulesSection($execution, 'rules'),
            new RulesSection($execution, 'only_rules'),
            new RulesSection($execution, 'disabled_rules'),
        ]), $layers);
        $resolver = new RuleEnablementResolver();
        $channels = ResolvedOptionsFixture::universe($metadata);
        $stated = $resolver->decide($document, $channels);
        $configuration = new FindingConfiguration($document);
        $options = (new RuleOptionsBuild($execution))->build($configuration, $stated);
        return $configuration->withChannelUniverse($channels)->withResolvedOptions($options)
            ->withEnablement($resolver->conclude($stated, $options));
    }
}
