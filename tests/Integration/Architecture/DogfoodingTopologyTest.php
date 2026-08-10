<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Architecture;

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
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Symbol\SymbolPath;
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
            'metrics-foundation',
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

    #[Test]
    public function itKeepsTheExactRootMetricPrimitivesInsideTheFoundationLayer(): void
    {
        $repoRoot = realpath(__DIR__ . '/../../..');
        self::assertIsString($repoRoot, 'Could not resolve repository root.');

        $rootFiles = glob($repoRoot . '/src/Metrics/*.php');
        self::assertIsArray($rootFiles);

        $rootFileNames = array_map(basename(...), $rootFiles);
        sort($rootFileNames);

        self::assertSame(
            [
                'AbstractCollector.php',
                'ResettableVisitorInterface.php',
                'VisitorCallableMetadata.php',
                'VisitorCallableScope.php',
                'VisitorFileEntryScope.php',
                'VisitorMethodContext.php',
                'VisitorMethodTrackingTrait.php',
            ],
            $rootFileNames,
            'Every class directly under src/Metrics/ must be an explicitly reviewed cross-category primitive. '
                . 'Move category-specific code into its subject directory or add a deliberate foundation contract.',
        );

        $registry = $this->loadProjectArchitecture()->registry();
        foreach (['AbstractCollector', 'ResettableVisitorInterface', 'VisitorCallableMetadata', 'VisitorCallableScope', 'VisitorFileEntryScope', 'VisitorMethodContext', 'VisitorMethodTrackingTrait'] as $type) {
            self::assertSame(
                'metrics-foundation',
                $registry->resolveLayer(SymbolPath::forClass('Qualimetrix\\Metrics', $type)),
                "Qualimetrix\\Metrics\\{$type} must be covered by the explicit metrics-foundation layer.",
            );
        }
    }

    #[Test]
    public function metricCategoriesMayDependOnFoundationButFoundationMustNotDependOnCategories(): void
    {
        $policy = $this->loadProjectArchitecture()->policy();

        self::assertTrue(
            $policy->isAllowed('metrics-Complexity', 'metrics-foundation'),
            'Metric categories share the explicitly reviewed cross-category implementation primitives.',
        );
        self::assertFalse(
            $policy->isAllowed('metrics-foundation', 'metrics-Complexity'),
            'The metric foundation must remain independent of every concrete metric category.',
        );
    }

    #[Test]
    public function itPinsTheCompleteRepeatedExpressionAndCredentialSubjectStacks(): void
    {
        $repoRoot = realpath(__DIR__ . '/../../..');
        self::assertIsString($repoRoot, 'Could not resolve repository root.');

        self::assertSame(
            [
                'IdenticalSubExpressionCollector.php',
                'IdenticalSubExpressionFinding.php',
                'IdenticalSubExpressionVisitor.php',
                'RepeatedConditions.php',
                'RepeatedExpressions.php',
            ],
            $this->phpFiles($repoRoot . '/src/Metrics/CodeSmell/RepeatedExpression'),
        );
        self::assertSame(
            [
                'CredentialLiterals.php',
                'CredentialLocation.php',
                'HardcodedCredentialsCollector.php',
                'HardcodedCredentialsVisitor.php',
            ],
            $this->phpFiles($repoRoot . '/src/Metrics/Security/Credential'),
        );
        $codeSmellRemnants = glob($repoRoot . '/src/Metrics/CodeSmell/IdenticalSubExpression*.php');
        $securityRemnants = glob($repoRoot . '/src/Metrics/Security/{CredentialLocation,HardcodedCredentials}*.php', \GLOB_BRACE);
        self::assertIsArray($codeSmellRemnants);
        self::assertIsArray($securityRemnants);
        self::assertSame([], $codeSmellRemnants);
        self::assertSame([], $securityRemnants);

        $imports = [
            'CodeSmell/CodeSmellVisitor.php' => [
                'Qualimetrix\\Metrics\\CodeSmell\\BooleanArgument\\BooleanArgumentSmells',
                'Qualimetrix\\Metrics\\CodeSmell\\ControlFlow\\ControlFlowSmells',
                'Qualimetrix\\Metrics\\CodeSmell\\Debug\\DebugCodeSmells',
                'Qualimetrix\\Metrics\\ResettableVisitorInterface',
                'Qualimetrix\\Metrics\\VisitorMethodTrackingTrait',
            ],
            'CodeSmell/ControlFlow/ControlFlowSmells.php' => ['Qualimetrix\\Metrics\\CodeSmell\\CodeSmellLocation'],
            'CodeSmell/Debug/DebugCodeSmells.php' => ['Qualimetrix\\Metrics\\CodeSmell\\CodeSmellLocation'],
            'CodeSmell/BooleanArgument/BooleanArgumentSmells.php' => ['Qualimetrix\\Metrics\\CodeSmell\\CodeSmellLocation'],
            'CodeSmell/RepeatedExpression/IdenticalSubExpressionCollector.php' => ['Qualimetrix\\Metrics\\AbstractCollector'],
            'CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitor.php' => [
                'Qualimetrix\\Metrics\\ResettableVisitorInterface',
                'Qualimetrix\\Metrics\\VisitorMethodTrackingTrait',
            ],
            'CodeSmell/RepeatedExpression/IdenticalSubExpressionFinding.php' => [],
            'CodeSmell/RepeatedExpression/RepeatedExpressions.php' => [],
            'CodeSmell/RepeatedExpression/RepeatedConditions.php' => [],
            'Security/Credential/HardcodedCredentialsCollector.php' => [
                'Qualimetrix\\Metrics\\AbstractCollector',
                'Qualimetrix\\Metrics\\Security\\SensitiveNameMatcher',
            ],
            'Security/Credential/HardcodedCredentialsVisitor.php' => [
                'Qualimetrix\\Metrics\\ResettableVisitorInterface',
                'Qualimetrix\\Metrics\\Security\\SensitiveNameMatcher',
                'Qualimetrix\\Metrics\\VisitorMethodTrackingTrait',
            ],
            'Security/Credential/CredentialLocation.php' => [],
            'Security/Credential/CredentialLiterals.php' => ['Qualimetrix\\Metrics\\Security\\SensitiveNameMatcher'],
        ];
        foreach ($imports as $path => $expected) {
            $source = file_get_contents($repoRoot . '/src/Metrics/' . $path);
            self::assertIsString($source);
            preg_match_all('/^use\\s+(Qualimetrix\\\\Metrics\\\\[^;]+);$/m', $source, $matches);
            $actual = $matches[1];
            sort($actual);
            sort($expected);
            self::assertSame($expected, $actual, "{$path} must keep its complete R4 Metrics import allow-list.");
        }

        $subjectTypes = [
            'IdenticalSubExpressionCollector',
            'IdenticalSubExpressionFinding',
            'IdenticalSubExpressionVisitor',
            'RepeatedConditions',
            'RepeatedExpressions',
            'CredentialLiterals',
            'CredentialLocation',
            'HardcodedCredentialsCollector',
            'HardcodedCredentialsVisitor',
        ];
        $sameSubjectDependencies = [
            'CodeSmell/ControlFlow/ControlFlowSmells.php' => [],
            'CodeSmell/Debug/DebugCodeSmells.php' => [],
            'CodeSmell/BooleanArgument/BooleanArgumentSmells.php' => [],
            'CodeSmell/RepeatedExpression/IdenticalSubExpressionCollector.php' => ['IdenticalSubExpressionVisitor'],
            'CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitor.php' => ['IdenticalSubExpressionFinding', 'RepeatedConditions', 'RepeatedExpressions'],
            'CodeSmell/RepeatedExpression/IdenticalSubExpressionFinding.php' => [],
            'CodeSmell/RepeatedExpression/RepeatedExpressions.php' => ['IdenticalSubExpressionFinding'],
            'CodeSmell/RepeatedExpression/RepeatedConditions.php' => ['IdenticalSubExpressionFinding', 'RepeatedExpressions'],
            'Security/Credential/HardcodedCredentialsCollector.php' => ['HardcodedCredentialsVisitor'],
            'Security/Credential/HardcodedCredentialsVisitor.php' => ['CredentialLiterals', 'CredentialLocation'],
            'Security/Credential/CredentialLocation.php' => [],
            'Security/Credential/CredentialLiterals.php' => ['CredentialLocation'],
        ];
        foreach ($sameSubjectDependencies as $path => $expected) {
            $source = $this->source($repoRoot, $path);
            self::assertSame(
                $expected,
                $this->sameSubjectReferences($source, $subjectTypes, pathinfo($path, \PATHINFO_FILENAME)),
                "{$path} must keep its complete same-subject dependency allow-list.",
            );
        }
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = glob($directory . '/*.php');
        self::assertIsArray($files);

        $names = array_map(basename(...), $files);
        sort($names);

        return $names;
    }

    private function source(string $repoRoot, string $path): string
    {
        $source = file_get_contents($repoRoot . '/src/Metrics/' . $path);
        self::assertIsString($source);

        return $source;
    }

    /** @param list<string> $candidates
     *  @return list<string> */
    private function sameSubjectReferences(string $source, array $candidates, string $declaredType): array
    {
        $references = [];
        foreach (token_get_all($source) as $token) {
            if (!\is_array($token) || $token[0] !== \T_STRING || $token[1] === $declaredType || !\in_array($token[1], $candidates, true)) {
                continue;
            }
            $references[] = $token[1];
        }
        $references = array_values(array_unique($references));
        sort($references);

        return $references;
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
