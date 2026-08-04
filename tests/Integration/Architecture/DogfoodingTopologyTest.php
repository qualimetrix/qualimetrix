<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition;
use Qualimetrix\Configuration\Discovery\ComposerReader;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Configuration\Pipeline\Stage\PresetStage;
use Qualimetrix\Configuration\Preset\PresetResolver;
use Qualimetrix\Core\Comparison\ComparisonStatus;
use Qualimetrix\Core\Comparison\ResolutionReason;
use Qualimetrix\Core\Coverage\CoverageDeviationReporterInterface;
use Qualimetrix\Core\Coverage\RunCoverageInterface;
use Qualimetrix\Core\Coverage\ScopeCoverage;
use Qualimetrix\Core\Coverage\ScopeCoverageStatus;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Observation\DebtObservation;
use Qualimetrix\Core\Observation\OccurrenceKey;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Regression guard for the project's own {@code qmx.yaml} architecture
 * topology. The repository's dogfooding config replaces the former
 * {@code deptrac.yaml}; this test pins the shape so a future edit that
 * collapses Analysis/Infrastructure sub-layers back into a flat
 * {@code analysis} / {@code infrastructure} layer (or removes the
 * {@code relations:} filter on {@code infra-di → metrics-*}) fails
 * here instead of silently weakening enforcement.
 *
 * The test does NOT re-test {@see ArchitectureConfigurationFactory} or
 * {@see \Qualimetrix\Architecture\Domain\Layer\LayerPolicy} mechanics — only
 * the contract this project commits to in its own dogfooding config.
 */
#[CoversClass(ArchitectureConfigurationFactory::class)]
#[CoversClass(ConfigurationPipeline::class)]
final class DogfoodingTopologyTest extends TestCase
{
    /**
     * Every layer name we commit to in {@code qmx.yaml}. Adding a new
     * Analysis/Infrastructure sub-namespace? Add it here and in the YAML.
     *
     * @return list<string>
     */
    private static function expectedLayerNames(): array
    {
        return [
            'core',
            'configuration',
            'architecture-domain',
            'architecture-configuration',
            'architecture-processing',
            'architecture-rules',
            'metrics-{Category}',
            'rules',
            'reporting',
            'baseline',
            'analysis-exception',
            'analysis-discovery',
            'analysis-namespace',
            'analysis-repository',
            'analysis-duplication',
            'analysis-aggregator',
            'analysis-ruleexecution',
            'analysis-collection',
            'analysis-lifecycle',
            'analysis-pipeline',
            'infra-serializer',
            'infra-logging',
            'infra-profiler',
            'infra-rule',
            'infra-git',
            'infra-cache',
            'infra-ast',
            'infra-parallel',
            'infra-console',
            'infra-di',
        ];
    }

    #[Test]
    public function dogfoodingConfigDeclaresAllSubLayers(): void
    {
        $arch = $this->loadProjectArchitecture();

        $declared = array_map(self::entryName(...), $arch->entries());
        foreach (self::expectedLayerNames() as $expected) {
            self::assertContains(
                $expected,
                $declared,
                "qmx.yaml is missing the '{$expected}' layer — sub-layer split removed?",
            );
        }
    }

    #[Test]
    public function dogfoodingConfigDoesNotReintroduceFlatParentLayers(): void
    {
        $arch = $this->loadProjectArchitecture();

        $declared = array_map(self::entryName(...), $arch->entries());

        // A flat 'analysis' / 'infrastructure' / 'metrics' layer would silently
        // mask every cross-sublayer edge that the current split catches. The
        // same holds for 'architecture' since ADR 0016 expired the internal
        // freedom ADR 0010 Part 5 granted for the pilot migration.
        self::assertNotContains('analysis', $declared);
        self::assertNotContains('infrastructure', $declared);
        self::assertNotContains('metrics', $declared);
        self::assertNotContains('architecture', $declared);
    }

    /**
     * The Architecture slice's internal DAG, as a complete decision table over
     * every ordered pair of its four sub-layers. Exhaustive on purpose: pinning
     * only the interesting pairs leaves the rest free to drift back toward the
     * mutually-reachable blob ADR 0010 Part 5 used to permit.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideArchitectureInternalEdges(): iterable
    {
        $allowed = [
            'architecture-domain' => [],
            'architecture-configuration' => ['architecture-domain'],
            'architecture-processing' => ['architecture-domain'],
            'architecture-rules' => ['architecture-domain', 'architecture-processing'],
        ];

        foreach (array_keys($allowed) as $source) {
            foreach (array_keys($allowed) as $target) {
                if ($source === $target) {
                    continue;
                }
                yield "{$source} → {$target}" => [
                    $source,
                    $target,
                    \in_array($target, $allowed[$source], true),
                ];
            }
        }
    }

    #[Test]
    #[DataProvider('provideArchitectureInternalEdges')]
    public function itPinsEveryInternalEdgeOfTheArchitectureSlice(
        string $source,
        string $target,
        bool $expected,
    ): void {
        $policy = $this->loadProjectArchitecture()->policy();

        // ADR 0016 supersedes ADR 0010 Part 5: the slice's internals are a DAG
        // rooted at Domain (Domain → nothing, Configuration/Processing → Domain,
        // Rules → Domain + Processing) and every edge is enforced, not assumed.
        self::assertSame(
            $expected,
            $policy->isAllowed($source, $target),
            $expected
                ? "qmx.yaml must keep the {$source} → {$target} edge — it is part of the "
                    . 'documented internal DAG of the Architecture slice.'
                : "qmx.yaml must forbid {$source} → {$target} — the internal freedom of "
                    . 'ADR 0010 Part 5 expired with the pilot migration (ADR 0016).',
        );
    }

    #[Test]
    public function itKeepsEveryArchitectureClassInsideASubLayer(): void
    {
        // The sub-layer patterns are `Qualimetrix\Architecture\{Sub}\**`, so a
        // class placed directly in `src/Architecture/` would belong to no layer
        // at all and — with `coverage: ignore` — be silently exempt from every
        // edge check. The flat `Qualimetrix\Architecture\**` pattern used to
        // catch it; nothing does now except this guard.
        $repoRoot = realpath(__DIR__ . '/../../..');
        self::assertIsString($repoRoot, 'Could not resolve repository root.');

        $strays = glob($repoRoot . '/src/Architecture/*.php');
        self::assertSame(
            [],
            $strays === false ? [] : $strays,
            'Classes directly under src/Architecture/ match no sub-layer pattern. '
            . 'Move the class into Domain/, Configuration/, Processing/ or Rules/, '
            . 'or declare a new sub-layer in qmx.yaml.',
        );
    }

    #[Test]
    public function analysisDiscoveryMustNotReachAnalysisPipeline(): void
    {
        $policy = $this->loadProjectArchitecture()->policy();

        self::assertFalse(
            $policy->isAllowed('analysis-discovery', 'analysis-pipeline'),
            'qmx.yaml must keep analysis-discovery isolated from analysis-pipeline — '
            . 'the sub-layer split is the whole reason deptrac was retired.',
        );
        self::assertTrue(
            $policy->isAllowed('analysis-pipeline', 'analysis-discovery'),
            'Pipeline orchestrates Discovery (the documented direction), so the '
            . 'reverse edge must remain allowed — guards against an accidental '
            . 'symmetric allow-list collapse.',
        );
    }

    /**
     * The name of the run-coverage layer, and every layer that must be allowed
     * to reach it. Decided by the package that owns {@code qmx.yaml}; pinned
     * here so the decision cannot drift once the layer is activated.
     *
     * @return list<string>
     */
    private static function coverageInboundEdges(): array
    {
        return [
            'analysis-pipeline',
            'analysis-collection',
            'analysis-ruleexecution',
            'infra-console',
            'infra-di',
            'infra-parallel',
        ];
    }

    private const string COVERAGE_LAYER = 'analysis-coverage';

    /**
     * The outbound allow-list `analysis-coverage` is decided to carry once
     * activated. Pinned separately from {@see coverageInboundEdges()} so an
     * exhaustive decision table can be built over it in
     * {@see provideCoverageOutboundEdges()}.
     *
     * @return list<string>
     */
    private static function coverageOutboundAllowList(): array
    {
        return ['core', 'configuration', 'analysis-exception'];
    }

    /**
     * True as soon as {@code src/Analysis/Coverage/} holds at least one PHP
     * class, at any depth. Must be recursive: the YAML pattern this layer
     * will match, {@code Qualimetrix\Analysis\Coverage\**}, matches classes
     * in subdirectories too. A shallow, top-level-only check would let a
     * package land its first class in a subdirectory while this guard still
     * asserts the layer must stay undeclared — the one mechanism that forces
     * activation would then never fire.
     *
     * Contrast with {@see itKeepsEveryArchitectureClassInsideASubLayer()},
     * whose top-level-only glob is deliberately shallow: it hunts stray
     * classes placed directly under `src/Architecture/`, outside any
     * sub-layer pattern.
     */
    private static function coverageNamespaceHasClasses(string $repoRoot): bool
    {
        $dir = $repoRoot . '/src/Analysis/Coverage';
        if (!is_dir($dir)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                return true;
            }
        }

        return false;
    }

    /**
     * Guards the hand-off for the {@code Qualimetrix\Analysis\Coverage}
     * namespace, which does not exist yet.
     *
     * The layer's name, its outbound allow-list, and its full inbound edge set
     * are already decided and recorded in {@code qmx.yaml} as an inactive
     * block. They cannot be activated before the namespace holds a class:
     * {@code unreachable_layer_severity: error} turns a layer matching nothing
     * into a build error, and the rule has no per-layer opt-out.
     *
     * So this test flips by itself. While the directory is empty it asserts the
     * layer stays undeclared; the moment a class lands there it demands the
     * declaration and every inbound edge, printing exactly what to paste. The
     * outbound direction is not checked here — see
     * {@see itPinsTheCoverageLayerOutboundAllowList()} for that decision table.
     */
    #[Test]
    public function itAdmitsTheCoverageLayerExactlyWhenItsNamespaceExists(): void
    {
        $repoRoot = realpath(__DIR__ . '/../../..');
        self::assertIsString($repoRoot, 'Could not resolve repository root.');

        $namespaceExists = self::coverageNamespaceHasClasses($repoRoot);

        $arch = $this->loadProjectArchitecture();
        $declared = array_map(self::entryName(...), $arch->entries());

        if (!$namespaceExists) {
            self::assertNotContains(
                self::COVERAGE_LAYER,
                $declared,
                'qmx.yaml declares the ' . self::COVERAGE_LAYER . ' layer while '
                . 'src/Analysis/Coverage/ is empty. A layer that matches no class is an '
                . 'architecture.unreachable-layer error under this project\'s own config, so the '
                . 'declaration must land together with the first class.',
            );

            return;
        }

        self::assertContains(
            self::COVERAGE_LAYER,
            $declared,
            'src/Analysis/Coverage/ now holds classes, so qmx.yaml must declare the layer. '
            . 'Activate every analysis-coverage marker in qmx.yaml:' . \PHP_EOL
            . '  - name: analysis-coverage' . \PHP_EOL
            . "    patterns: ['Qualimetrix\\Analysis\\Coverage\\**']" . \PHP_EOL
            . '  analysis-coverage: [' . implode(', ', self::coverageOutboundAllowList()) . ']' . \PHP_EOL
            . '  … plus analysis-coverage in the allow-lists of: '
            . implode(', ', self::coverageInboundEdges()),
        );

        $policy = $arch->policy();
        foreach (self::coverageInboundEdges() as $source) {
            self::assertTrue(
                $policy->isAllowed($source, self::COVERAGE_LAYER),
                "qmx.yaml must allow {$source} → " . self::COVERAGE_LAYER . '. The inbound edge set is '
                . 'owned by the package that owns qmx.yaml precisely so that later packages do not '
                . 'inherit a layer violation they have no right to fix.',
            );
        }

        self::assertFalse(
            $policy->isAllowed('baseline', self::COVERAGE_LAYER),
            'baseline must NOT reach ' . self::COVERAGE_LAYER . '. Baseline consumes the coverage '
            . 'contract from Core; an edge to an Analysis sub-layer is the upward dependency that '
            . 'putting the contract in Core exists to prevent.',
        );
    }

    /**
     * Every layer name a fully-activated `analysis-coverage` might reach,
     * paired with whether {@see coverageOutboundAllowList()} actually permits
     * it. Exhaustive over {@see expectedLayerNames()} so a drifted outbound
     * entry fails here even though it would leave both
     * `itAdmitsTheCoverageLayerExactlyWhenItsNamespaceExists()` (inbound only)
     * and self-analysis (the drifted edge is now allowed, so nothing reports
     * a violation) green.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function provideCoverageOutboundEdges(): iterable
    {
        $allowed = self::coverageOutboundAllowList();

        foreach (self::expectedLayerNames() as $target) {
            if ($target === self::COVERAGE_LAYER) {
                continue;
            }

            yield self::COVERAGE_LAYER . " → {$target}" => [$target, \in_array($target, $allowed, true)];
        }
    }

    #[Test]
    #[DataProvider('provideCoverageOutboundEdges')]
    public function itPinsTheCoverageLayerOutboundAllowList(string $target, bool $expected): void
    {
        $repoRoot = realpath(__DIR__ . '/../../..');
        self::assertIsString($repoRoot, 'Could not resolve repository root.');

        if (!self::coverageNamespaceHasClasses($repoRoot)) {
            self::markTestSkipped(
                self::COVERAGE_LAYER . ' is not active yet — src/Analysis/Coverage/ is empty. '
                . 'This outbound decision table only binds once the layer is declared.',
            );
        }

        $policy = $this->loadProjectArchitecture()->policy();

        self::assertSame(
            $expected,
            $policy->isAllowed(self::COVERAGE_LAYER, $target),
            $expected
                ? 'qmx.yaml must allow ' . self::COVERAGE_LAYER . " → {$target} — it is part of the "
                    . 'documented outbound allow-list of the run-coverage centre.'
                : 'qmx.yaml must forbid ' . self::COVERAGE_LAYER . " → {$target} — the coverage centre "
                    . 'reads the discovery inventory, exclusion configuration, and parse/worker failures '
                    . 'only; anything wider is undeclared scope creep.',
        );
    }

    /**
     * Every contract a Core-only consumer must be able to name.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function provideCoreOnlyConsumableContracts(): iterable
    {
        $contracts = [
            DebtObservation::class,
            OccurrenceKey::class,
            RunCoverageInterface::class,
            CoverageDeviationReporterInterface::class,
            ScopeCoverage::class,
            ScopeCoverageStatus::class,
            ComparisonStatus::class,
            ResolutionReason::class,
        ];

        foreach ($contracts as $contract) {
            yield $contract => [$contract];
        }
    }

    /**
     * {@code baseline: [core]} is the whole reason the observation, coverage,
     * and comparison contracts sit in Core rather than next to whichever
     * component produces them. A contract that drifts out of Core silently
     * becomes unreachable from Baseline and Reporting alike.
     *
     * @param class-string $contract
     */
    #[Test]
    #[DataProvider('provideCoreOnlyConsumableContracts')]
    public function itKeepsRatchetContractsReachableFromCoreOnlyLayers(string $contract): void
    {
        self::assertStringStartsWith(
            'Qualimetrix\\Core\\',
            $contract,
            "{$contract} must live in Core: Baseline and Reporting are allowed [core] alone, so a "
            . 'contract placed anywhere else is an upward dependency they cannot express.',
        );

        $policy = $this->loadProjectArchitecture()->policy();
        self::assertTrue(
            $policy->isAllowed('baseline', 'core'),
            'baseline: [core] must stay satisfiable — it is the constraint that decided where the '
            . 'observation and coverage contracts live.',
        );
        self::assertTrue(
            $policy->isAllowed('reporting', 'core'),
            'reporting: [core] must stay satisfiable — every formatter names the comparison status.',
        );
    }

    #[Test]
    public function infraDiMayReferenceMetricCollectorsButMustNotExtendThem(): void
    {
        $policy = $this->loadProjectArchitecture()->policy();

        self::assertTrue(
            $policy->isAllowed('infra-di', 'metrics-Complexity', DependencyType::TypeHint),
            'DI configurator wires collectors via type references — type_reference '
            . 'must remain in the relations: filter for infra-di → metrics-*.',
        );
        self::assertFalse(
            $policy->isAllowed('infra-di', 'metrics-Complexity', DependencyType::Extends),
            'DI configurator must NEVER extend a collector — inheritance must stay '
            . 'out of the relations: filter for infra-di → metrics-*.',
        );
    }

    private static function entryName(LayerDefinition|TemplateLayerDefinition $entry): string
    {
        return $entry instanceof TemplateLayerDefinition ? $entry->nameTemplate : $entry->name;
    }

    private function loadProjectArchitecture(): ArchitectureConfiguration
    {
        $repoRoot = realpath(__DIR__ . '/../../..');
        self::assertIsString($repoRoot, 'Could not resolve repository root.');
        self::assertFileExists($repoRoot . '/qmx.yaml', 'Project qmx.yaml is missing.');

        $loader = new YamlConfigLoader();
        $resolver = new PresetResolver();
        $composerReader = new ComposerReader();

        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage(new DefaultsStage());
        $pipeline->addStage(new ComposerDiscoveryStage($composerReader));
        $pipeline->addStage(new PresetStage($loader, $resolver));
        $pipeline->addStage(new ConfigFileStage($loader));
        $pipeline->addStage(new CliStage());

        $definition = new InputDefinition([
            new InputArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, '', []),
            new InputOption('preset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('format', null, InputOption::VALUE_REQUIRED),
            new InputOption('cache-dir', null, InputOption::VALUE_REQUIRED),
            new InputOption('no-cache', null, InputOption::VALUE_NONE),
            new InputOption('disable-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('only-rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('fail-on', null, InputOption::VALUE_REQUIRED),
            new InputOption('exclude-health', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('include-generated', null, InputOption::VALUE_NONE),
            new InputOption('workers', null, InputOption::VALUE_REQUIRED),
        ]);
        $input = new ArrayInput([], $definition);

        return $pipeline->resolve(new ConfigurationContext($input, $repoRoot))->architecture;
    }
}
