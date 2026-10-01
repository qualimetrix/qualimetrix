<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document\WrittenFile;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\ArchitectureDocument;

/**
 * End-to-end test for Phase 2 Step G (direction 4: dependency-type filter).
 * Loads a YAML config with a {@code relations:} long-form allow target through
 * the real {@see ArchitectureConfigurationFactory}, runs the real
 * {@see AnalysisPipelineInterface} against a domain-vs-vendor fixture, and
 * asserts that only the listed edge kinds cross the boundary.
 *
 * The fixture creates three domain → vendor edges of three distinct
 * {@code DependencyType} kinds:
 *
 * - {@code OrderExtender extends BaseEntity}            — {@code Extends}
 * - {@code PaymentCaller calls Helper::pay()}           — {@code StaticCall}
 * - {@code ProductTyper return-types Product}           — {@code TypeHint}
 *
 * Each test case picks a different {@code relations:} list and asserts which
 * of the three edges fire findings.
 */
#[Group('integration')]
final class RelationsFilterIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/RelationsSample';

    #[Test]
    public function itLetsTheInheritanceAliasPermitExtendsAndForbidStaticCallAndTypeHint(): void
    {
        $config = self::baseConfig();
        $config['allow'] = [
            'domain' => [
                ['target' => 'vendor', 'relations' => ['inheritance']],
            ],
        ];

        $messages = $this->collectFindingMessages($config);

        self::assertEdgeNotViolating($messages, 'OrderExtender', 'BaseEntity', 'inheritance must accept Extends');
        self::assertEdgeViolates($messages, 'PaymentCaller', 'Helper', 'inheritance must reject StaticCall');
        self::assertEdgeViolates($messages, 'ProductTyper', 'Product', 'inheritance must reject TypeHint');
    }

    #[Test]
    public function itLetsTheStaticAccessAliasPermitStaticCallAndForbidExtendsAndTypeHint(): void
    {
        $config = self::baseConfig();
        $config['allow'] = [
            'domain' => [
                ['target' => 'vendor', 'relations' => ['static_access']],
            ],
        ];

        $messages = $this->collectFindingMessages($config);

        self::assertEdgeViolates($messages, 'OrderExtender', 'BaseEntity', 'static_access must reject Extends');
        self::assertEdgeNotViolating($messages, 'PaymentCaller', 'Helper', 'static_access must accept StaticCall');
        self::assertEdgeViolates($messages, 'ProductTyper', 'Product', 'static_access must reject TypeHint');
    }

    #[Test]
    public function itLetsTheTypeReferenceAliasPermitTypeHintAndForbidExtendsAndStaticCall(): void
    {
        $config = self::baseConfig();
        $config['allow'] = [
            'domain' => [
                ['target' => 'vendor', 'relations' => ['type_reference']],
            ],
        ];

        $messages = $this->collectFindingMessages($config);

        self::assertEdgeViolates($messages, 'OrderExtender', 'BaseEntity', 'type_reference must reject Extends');
        self::assertEdgeViolates($messages, 'PaymentCaller', 'Helper', 'type_reference must reject StaticCall');
        self::assertEdgeNotViolating($messages, 'ProductTyper', 'Product', 'type_reference must accept TypeHint');
    }

    #[Test]
    public function itPermitsOnlyTheListedDependencyKindsForAMixOfDirectValues(): void
    {
        // `extends` + `static_call` direct values — picks Extends and StaticCall,
        // leaves TypeHint to violate.
        $config = self::baseConfig();
        $config['allow'] = [
            'domain' => [
                ['target' => 'vendor', 'relations' => ['extends', 'static_call']],
            ],
        ];

        $messages = $this->collectFindingMessages($config);

        self::assertEdgeNotViolating($messages, 'OrderExtender', 'BaseEntity', 'extends must be accepted');
        self::assertEdgeNotViolating($messages, 'PaymentCaller', 'Helper', 'static_call must be accepted');
        self::assertEdgeViolates($messages, 'ProductTyper', 'Product', 'type_hint must be rejected');
    }

    #[Test]
    public function itAcceptsEveryEdgeKindForABareTargetWithoutRelations(): void
    {
        // Short-form (bare-string) target leaves relations=null on AllowTarget;
        // every dependency type is accepted (Phase-1 BC).
        $config = self::baseConfig();
        $config['allow'] = [
            'domain' => ['vendor'],
        ];

        $messages = $this->collectFindingMessages($config);

        self::assertSame([], $messages, 'bare-string target must not raise any layer violation');
    }

    #[Test]
    public function itLetsTheBareStringSiblingDominateUnderUnionSemantics(): void
    {
        // UNION semantics inside one source's target list: a bare-string target
        // rescues edge kinds the long-form sibling's relations list rejects.
        $config = self::baseConfig();
        $config['allow'] = [
            'domain' => [
                ['target' => 'vendor', 'relations' => ['inheritance']],
                'vendor',
            ],
        ];

        $messages = $this->collectFindingMessages($config);

        self::assertSame(
            [],
            $messages,
            'bare-string sibling must dominate the relations-restricted sibling under UNION semantics',
        );
    }

    #[Test]
    public function itPreservesTheRelationsKeyAndItsAliasThroughYamlConfigLoader(): void
    {
        // Regression test for YamlConfigLoader normalization: the `relations:`
        // list and its `inheritance` alias must reach AllowValidator without
        // being silently rewritten to camelCase or dropped.
        $yamlPath = tempnam(sys_get_temp_dir(), 'qmx_relations_') . '.yaml';
        file_put_contents($yamlPath, <<<'YAML'
            architecture:
              layers:
                - name: domain
                  patterns: ['Fixtures\RelationsSample\Domain\**']
                - name: vendor
                  patterns: ['Fixtures\RelationsSample\Vendor\**']
              allow:
                domain:
                  - target: vendor
                    relations: [inheritance]
              coverage-gap: ignore
            YAML);

        try {
            $loaded = WrittenFile::compose($yamlPath);
            $messages = $this->collectFindingMessages($loaded);

            self::assertEdgeNotViolating($messages, 'OrderExtender', 'BaseEntity', 'YAML-loaded inheritance alias must accept Extends');
            self::assertEdgeViolates($messages, 'PaymentCaller', 'Helper', 'YAML-loaded inheritance alias must reject StaticCall');
        } finally {
            @unlink($yamlPath);
        }
    }

    /**
     * @param array<string, mixed>|\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument $configArray
     *
     * @return list<string> Finding messages for the layer-violation rule only.
     */
    private function collectFindingMessages(array|\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument $configArray): array
    {
        $analysis = $this->runPipelineWithConfig($configArray);

        return array_values(array_map(
            static fn(Finding $v): string => $v->message,
            array_filter(
                $analysis->findings,
                static fn(Finding $v): bool => $v->ruleName === LayerViolationRule::NAME,
            ),
        ));
    }

    /**
     * @param array<string, mixed>|\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument $configArray
     */
    private function runPipelineWithConfig(array|\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument $configArray): AnalysisResult
    {
        $factory = new ArchitectureConfigurationFactory();
        $result = $factory->fromResolved($configArray instanceof \Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument ? $configArray : ArchitectureDocument::file($configArray));

        $container = (new ContainerFactory())->create();
        $execution = $container->get(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class);
        self::assertInstanceOf(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class, $execution);
        $registry = $container->get(\Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface::class);
        self::assertInstanceOf(\Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry::class, $registry);
        $document = $configArray instanceof \Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument
            ? $configArray
            : \Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::document([['source' => 'config', 'values' => ['architecture' => $configArray]]], AbsolutePath::fromString(self::FIXTURE_PATH))->resolved();
        $catalog = $container->get(\Qualimetrix\Infrastructure\Rule\ChannelUniverse::class);
        self::assertInstanceOf(\Qualimetrix\Infrastructure\Rule\ChannelUniverse::class, $catalog);
        $channels = $catalog->snapshot(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions([]));
        $registry->replace(\Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::ready(new \Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration($document), $execution->allRules(), channels: $channels));

        $holder = $container->get(ArchitecturePolicyConfiguratorInterface::class);
        self::assertInstanceOf(ArchitecturePolicy::class, $holder);
        $holder->bind($result->configuration);

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);

        $root = AbsolutePath::fromString(self::FIXTURE_PATH);
        return $pipeline->analyze(new \Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration(
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Include,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $root, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [$root], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseConfig(): array
    {
        return [
            'layers' => [
                [
                    'name' => 'domain',
                    'patterns' => ['Fixtures\\RelationsSample\\Domain\\**'],
                ],
                [
                    'name' => 'vendor',
                    'patterns' => ['Fixtures\\RelationsSample\\Vendor\\**'],
                ],
            ],
            'coverage-gap' => 'ignore',
        ];
    }

    /**
     * @param list<string> $messages
     */
    private static function assertEdgeViolates(array $messages, string $sourceClassShortName, string $targetClassShortName, string $hint): void
    {
        $matching = array_filter(
            $messages,
            static fn(string $m): bool => str_contains($m, $sourceClassShortName) && str_contains($m, $targetClassShortName),
        );

        self::assertNotEmpty(
            $matching,
            \sprintf("Expected a layer violation %s → %s. %s. Messages: %s", $sourceClassShortName, $targetClassShortName, $hint, implode(' | ', $messages)),
        );
    }

    /**
     * @param list<string> $messages
     */
    private static function assertEdgeNotViolating(array $messages, string $sourceClassShortName, string $targetClassShortName, string $hint): void
    {
        $matching = array_filter(
            $messages,
            static fn(string $m): bool => str_contains($m, $sourceClassShortName) && str_contains($m, $targetClassShortName),
        );

        self::assertSame(
            [],
            array_values($matching),
            \sprintf("Expected no layer violation %s → %s. %s. Messages: %s", $sourceClassShortName, $targetClassShortName, $hint, implode(' | ', $messages)),
        );
    }
}
