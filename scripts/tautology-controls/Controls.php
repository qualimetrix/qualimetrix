<?php

declare(strict_types=1);

namespace QmxTautologyControls;

/**
 * One control per tautology stage 05 repaired, and the repaired cases the set
 * as a whole has to account for.
 *
 * Each control names the production edit the repaired assertion is supposed to
 * reject. That edit is the whole content of the repair: before it, the case
 * compared an expression with itself and no edit to the product could have
 * moved one side without moving the other.
 *
 * Thirteen of the ledger's fourteen `tautology` rows are here, plus one
 * `name-lies` repair that shares a file with one of them — SymbolInfo's
 * readonly promise — because its edit is disjoint from the tautology's and the
 * bench is the cheapest place to say so. The fourteenth, `R127`, repaired the
 * test of a null dependency graph the product no longer has: with the class
 * gone there is no production edit left for its case to reject.
 */
final class Controls
{
    /**
     * The case each control is required to claim: one per ledger row repaired
     * here, plus SymbolInfo's readonly promise, which shares a file with a
     * tautology and is broken by a different edit.
     *
     * Fourteen of the fifteen were written or rewritten by that repair. The
     * fifteenth, `itUsesLoggerForDebugMessages`, was already there: `R169`'s
     * repair was the removal of five cases that asserted nothing, and this is
     * the case that carries what they were named for.
     *
     * This is the denominator {@see Report::unguarded()} uses — not the
     * population, which carries hundreds of cases this stage never touched.
     *
     * @return list<string>
     */
    public static function repaired(): array
    {
        return [
            'Qualimetrix.Governance.Channel.ProjectScopedChannelRollCallTest::itAsksEveryCapabilityThatDeclaresProjectScopedChannels',
            'Qualimetrix.Tests.Analysis.Evidence.ComputedMetrics.Health.Unit.DecompositionItemTest::itRefusesAWriteToAConstructedItem',
            'Qualimetrix.Tests.Analysis.Evidence.ComputedMetrics.Health.Unit.HealthScoreTest::itRequiresEveryScoreToStateWhatItCovers',
            'Qualimetrix.Tests.Analysis.Evidence.Coupling.Unit.NamespaceInstabilityOptionsTest::itIsDisabledWhenTheEnabledFlagIsFalse',
            'Qualimetrix.Tests.Analysis.Evidence.DependencyModel.Unit.DependencyTest::itRefusesAWriteToAConstructedDependency',
            'Qualimetrix.Tests.Analysis.Finding.Unit.LocationTest::itRefusesAWriteToAConstructedLocation',
            'Qualimetrix.Tests.Analysis.Policy.Baseline.Unit.ChannelRenameMapTest::itAnswersTheSharedCorpusAsDeclared with data set "one-row"',
            'Qualimetrix.Tests.Infrastructure.DependencyInjection.Integration.ContainerFactoryTest::itLetsTheConfigurationPipelineAddressAProducerNoRuleClassDeclares',
            'Qualimetrix.Tests.Infrastructure.DependencyInjection.Unit.CompilerPass.RuleCompilerPassTest::itInjectsIntoEveryConsumerItDeclaresAndIntoNothingElse',
            'Qualimetrix.Tests.Infrastructure.Parallel.Unit.Strategy.AmphpParallelStrategyTest::itUsesLoggerForDebugMessages',
            'Qualimetrix.Tests.Infrastructure.Rule.Unit.RuleRegistryTest::itReadsMetadataOffARuleClassItCouldNotHaveBuilt',
            'Qualimetrix.Tests.Reporting.Unit.FindingProjection.DeclaredChannelFileScopeTest::itMarksAChannelBothCapabilitiesDeclareAsProjectScoped',
            'Qualimetrix.Tests.Unit.Core.Symbol.CallableKindTest::itUsesValuesNoCompositeKeyCanSplitInTheWrongPlace',
            'Qualimetrix.Tests.Unit.Core.Symbol.SymbolInfoTest::itKeepsTheExactSubjectItWasConstructedFrom',
            'Qualimetrix.Tests.Unit.Core.Symbol.SymbolInfoTest::itRefusesAWriteToAConstructedSymbolInfo',
        ];
    }

    /** @return list<Control> */
    public static function all(): array
    {
        return [
            Control::positive(),

            Control::breaking(
                'location-is-readonly',
                'R003',
                'Location refuses a write after construction',
                'src/Analysis/Finding/Contract/Location.php',
                ['final readonly class Location' => 'final class Location'],
                ['Qualimetrix.Tests.Analysis.Finding.Unit.LocationTest::itRefusesAWriteToAConstructedLocation'],
            ),

            Control::breaking(
                'dependency-is-readonly',
                'R126',
                'Dependency refuses a write after construction',
                'src/Analysis/Evidence/DependencyModel/Contract/Dependency.php',
                ['final readonly class Dependency' => 'final class Dependency'],
                ['Qualimetrix.Tests.Analysis.Evidence.DependencyModel.Unit.DependencyTest::itRefusesAWriteToAConstructedDependency'],
            ),

            Control::breaking(
                'symbol-info-is-readonly',
                'R155',
                'SymbolInfo refuses a write after construction',
                'src/Core/Symbol/SymbolInfo.php',
                ['final readonly class SymbolInfo' => 'final class SymbolInfo'],
                ['Qualimetrix.Tests.Unit.Core.Symbol.SymbolInfoTest::itRefusesAWriteToAConstructedSymbolInfo'],
            ),

            Control::breaking(
                'symbol-info-keeps-its-subject',
                'R156',
                'SymbolInfo keeps the exact subject it was constructed from',
                'src/Core/Symbol/SymbolInfo.php',
                ['$this->subject = $symbolPath instanceof MetricSubject ? $symbolPath : null;' => '$this->subject = null;'],
                ['Qualimetrix.Tests.Unit.Core.Symbol.SymbolInfoTest::itKeepsTheExactSubjectItWasConstructedFrom'],
            ),

            Control::breaking(
                'callable-kind-values-stay-usable-as-keys',
                'R153',
                'a CallableKind value cannot carry a separator the composite keys built from it use',
                'src/Core/Symbol/CallableKind.php',
                ["case Method = 'method';" => "case Method = 'met:hod';"],
                ['Qualimetrix.Tests.Unit.Core.Symbol.CallableKindTest::itUsesValuesNoCompositeKeyCanSplitInTheWrongPlace'],
            ),

            Control::breaking(
                'decomposition-item-is-readonly',
                'R090',
                'DecompositionItem refuses a write after construction',
                'src/Analysis/Evidence/ComputedMetrics/Health/Contract/Score/DecompositionItem.php',
                ['final readonly class DecompositionItem' => 'final class DecompositionItem'],
                ['Qualimetrix.Tests.Analysis.Evidence.ComputedMetrics.Health.Unit.DecompositionItemTest::itRefusesAWriteToAConstructedItem'],
            ),

            Control::breaking(
                'health-score-states-its-coverage',
                'R091',
                'a HealthScore cannot be published without saying what it covers (ADR 0062)',
                'src/Analysis/Evidence/ComputedMetrics/Health/Contract/Score/HealthScore.php',
                ['public HealthCoverage $coverage,' => 'public ?HealthCoverage $coverage = null,'],
                ['Qualimetrix.Tests.Analysis.Evidence.ComputedMetrics.Health.Unit.HealthScoreTest::itRequiresEveryScoreToStateWhatItCovers'],
            ),

            Control::breaking(
                'namespace-instability-reads-the-flag',
                'R122',
                'NamespaceInstabilityOptions reads the enabled flag instead of defaulting past it',
                'src/Analysis/Evidence/Coupling/NamespaceInstabilityOptions.php',
                ['enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),' => 'enabled: true,'],
                ['Qualimetrix.Tests.Analysis.Evidence.Coupling.Unit.NamespaceInstabilityOptionsTest::itIsDisabledWhenTheEnabledFlagIsFalse'],
            ),

            Control::breaking(
                'channel-rename-map-reads-its-rows',
                'R270',
                'ChannelRenameMap produces the map the corpus declares, rather than one derived from itself',
                'src/Analysis/Policy/Baseline/ChannelRenameMap.php',
                ['$renames[$old] = $new;' => '$renames[$new] = $new;'],
                [
                    'Qualimetrix.Tests.Analysis.Policy.Baseline.Unit.ChannelRenameMapTest::itAnswersTheSharedCorpusAsDeclared with data set "one-row"',
                    'Qualimetrix.Tests.Analysis.Policy.Baseline.Unit.ChannelRenameMapTest::itAnswersTheSharedCorpusAsDeclared with data set "crlf-line-endings"',
                    'Qualimetrix.Tests.Analysis.Policy.Baseline.Unit.ChannelRenameMapTest::itAnswersTheSharedCorpusAsDeclared with data set "comment-and-blank-lines"',
                ],
            )->alsoReddens(
                'the hand-written map beside the corpus reads the same rows',
                ['Qualimetrix.Tests.Analysis.Policy.Baseline.Unit.ChannelRenameMapTest::itReadsTheRowsItAccepted'],
            ),

            Control::breaking(
                'every-declaring-capability-is-asked',
                'R065',
                'the assembled channel scope asks every capability that declares project-scoped channels',
                'src/Reporting/FindingProjection/DeclaredChannelFileScope.php',
                ['            ...CircularDependencyPreparationInterface::PROJECT_SCOPED_CHANNELS,' . "\n" => ''],
                [
                    // Both halves of the repair: the roll-call reads the
                    // declaring capabilities off the tree, and the unit case
                    // writes out a channel of each capability by hand.
                    'Qualimetrix.Governance.Channel.ProjectScopedChannelRollCallTest::itAsksEveryCapabilityThatDeclaresProjectScopedChannels',
                    'Qualimetrix.Tests.Reporting.Unit.FindingProjection.DeclaredChannelFileScopeTest::itMarksAChannelBothCapabilitiesDeclareAsProjectScoped',
                ],
            ),

            Control::breaking(
                'rule-registry-does-not-instantiate',
                'R186',
                'RuleRegistry reads a rule class\'s metadata without building the rule',
                'src/Infrastructure/Rule/RuleRegistry.php',
                ['$ruleName = RuleNameReader::read($ruleClass);' => '$ruleName = (new $ruleClass())->getName();'],
                ['Qualimetrix.Tests.Infrastructure.Rule.Unit.RuleRegistryTest::itReadsMetadataOffARuleClassItCouldNotHaveBuilt'],
            )->alsoReddens(
                'every other case in the file hands the registry a rule class whose constructor also takes an Options object',
                [
                    'Qualimetrix.Tests.Infrastructure.Rule.Unit.RuleRegistryTest::itCollectsCliAliasesFromAllRulesUsingReflection',
                    'Qualimetrix.Tests.Infrastructure.Rule.Unit.RuleRegistryTest::itThrowsWhenTwoRulesShareACliAlias',
                ],
            )->alsoReddens(
                'the container builds its registry over every real rule class, so a registry that instantiates fails the compile',
                [
                    'Qualimetrix.Tests.Infrastructure.DependencyInjection.Integration.ContainerFactoryTest::itCreatesCompiledContainer',
                    'Qualimetrix.Tests.Infrastructure.DependencyInjection.Integration.ContainerFactoryTest::itHasCheckCommand',
                    'Qualimetrix.Tests.Infrastructure.DependencyInjection.Integration.ContainerFactoryTest::itWiresFileSetInspectionAndTraversalContractsWithoutLegacyCapabilityImports',
                ],
            ),

            Control::breaking(
                'rule-compiler-pass-injects-its-consumers',
                'R184',
                'RuleCompilerPass injects the tagged rules into every consumer it declares',
                'src/Infrastructure/DependencyInjection/CompilerPass/RuleCompilerPass.php',
                ['$container->getDefinition($consumerId)->setArgument($argumentIndex, $rules);' => '$container->getDefinition($consumerId)->setArgument($argumentIndex, []);'],
                ['Qualimetrix.Tests.Infrastructure.DependencyInjection.Unit.CompilerPass.RuleCompilerPassTest::itInjectsIntoEveryConsumerItDeclaresAndIntoNothingElse'],
            )->alsoReddens(
                'the first case in the file reads the same injected argument',
                [
                    'Qualimetrix.Tests.Infrastructure.DependencyInjection.Unit.CompilerPass.RuleCompilerPassTest::itCollectsTaggedRulesIntoRuleExecution',
                    'Qualimetrix.Tests.Infrastructure.DependencyInjection.Integration.ContainerFactoryTest::itInjectsRulesIntoRuleExecution',
                    'Qualimetrix.Tests.Infrastructure.DependencyInjection.Integration.ContainerFactoryTest::itWiresTheDuplicationCapabilityThroughItsContractAndRegistries',
                ],
            ),

            Control::breaking(
                'known-rule-names-carry-the-classless-producers',
                'R183',
                'the rules vocabulary the configuration pipeline validates against carries producers no rule class declares',
                'src/Infrastructure/DependencyInjection/CompilerPass/ChannelDeclarationCompilerPass.php',
                [
                    "->setArgument('\$ruleNames', array_keys(\$thresholdOverrideSupport));"
                        => "->setArgument('\$ruleNames', array_values(array_filter(array_keys(\$thresholdOverrideSupport), static fn(string \$name): bool => !str_starts_with(\$name, 'health.'))));",
                ],
                ['Qualimetrix.Tests.Infrastructure.DependencyInjection.Integration.ContainerFactoryTest::itLetsTheConfigurationPipelineAddressAProducerNoRuleClassDeclares'],
            ),

            Control::breaking(
                'the-parallel-fallback-says-which-one-it-took',
                'R169',
                'the file-count fallback is reported as itself, which is what the five assertion-free setter cases never checked',
                'src/Infrastructure/Parallel/Strategy/AmphpParallelStrategy.php',
                ['if (\count($files) < $this->minFilesForParallel) {' => 'if (false) {'],
                ['Qualimetrix.Tests.Infrastructure.Parallel.Unit.Strategy.AmphpParallelStrategyTest::itUsesLoggerForDebugMessages'],
            ),
        ];
    }
}
