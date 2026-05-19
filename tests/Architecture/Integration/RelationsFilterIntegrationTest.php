<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Architecture\Processing\ArchitectureProcessorInterface;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

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
 * of the three edges fire violations.
 */
#[Group('integration')]
final class RelationsFilterIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/RelationsSample';

    #[Test]
    public function inheritanceAlias_permitsExtendsAndForbidsStaticCallAndTypeHint(): void
    {
        $config = self::baseConfig();
        $config['allow'] = [
            'domain' => [
                ['target' => 'vendor', 'relations' => ['inheritance']],
            ],
        ];

        $messages = $this->collectViolationMessages($config);

        self::assertEdgeNotViolating($messages, 'OrderExtender', 'BaseEntity', 'inheritance must accept Extends');
        self::assertEdgeViolates($messages, 'PaymentCaller', 'Helper', 'inheritance must reject StaticCall');
        self::assertEdgeViolates($messages, 'ProductTyper', 'Product', 'inheritance must reject TypeHint');
    }

    #[Test]
    public function staticAccessAlias_permitsStaticCallAndForbidsExtendsAndTypeHint(): void
    {
        $config = self::baseConfig();
        $config['allow'] = [
            'domain' => [
                ['target' => 'vendor', 'relations' => ['static_access']],
            ],
        ];

        $messages = $this->collectViolationMessages($config);

        self::assertEdgeViolates($messages, 'OrderExtender', 'BaseEntity', 'static_access must reject Extends');
        self::assertEdgeNotViolating($messages, 'PaymentCaller', 'Helper', 'static_access must accept StaticCall');
        self::assertEdgeViolates($messages, 'ProductTyper', 'Product', 'static_access must reject TypeHint');
    }

    #[Test]
    public function typeReferenceAlias_permitsTypeHintAndForbidsExtendsAndStaticCall(): void
    {
        $config = self::baseConfig();
        $config['allow'] = [
            'domain' => [
                ['target' => 'vendor', 'relations' => ['type_reference']],
            ],
        ];

        $messages = $this->collectViolationMessages($config);

        self::assertEdgeViolates($messages, 'OrderExtender', 'BaseEntity', 'type_reference must reject Extends');
        self::assertEdgeViolates($messages, 'PaymentCaller', 'Helper', 'type_reference must reject StaticCall');
        self::assertEdgeNotViolating($messages, 'ProductTyper', 'Product', 'type_reference must accept TypeHint');
    }

    #[Test]
    public function directValueMix_permitsListedKindsOnly(): void
    {
        // `extends` + `static_call` direct values — picks Extends and StaticCall,
        // leaves TypeHint to violate.
        $config = self::baseConfig();
        $config['allow'] = [
            'domain' => [
                ['target' => 'vendor', 'relations' => ['extends', 'static_call']],
            ],
        ];

        $messages = $this->collectViolationMessages($config);

        self::assertEdgeNotViolating($messages, 'OrderExtender', 'BaseEntity', 'extends must be accepted');
        self::assertEdgeNotViolating($messages, 'PaymentCaller', 'Helper', 'static_call must be accepted');
        self::assertEdgeViolates($messages, 'ProductTyper', 'Product', 'type_hint must be rejected');
    }

    #[Test]
    public function bareTargetWithoutRelations_acceptsEveryEdgeKind(): void
    {
        // Short-form (bare-string) target leaves relations=null on AllowTarget;
        // every dependency type is accepted (Phase-1 BC).
        $config = self::baseConfig();
        $config['allow'] = [
            'domain' => ['vendor'],
        ];

        $messages = $this->collectViolationMessages($config);

        self::assertSame([], $messages, 'bare-string target must not raise any layer violation');
    }

    #[Test]
    public function overlappingTargetsShortFormDominates_unionAcrossSiblings(): void
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

        $messages = $this->collectViolationMessages($config);

        self::assertSame(
            [],
            $messages,
            'bare-string sibling must dominate the relations-restricted sibling under UNION semantics',
        );
    }

    #[Test]
    public function yamlEndToEnd_relationsKeyAndAliasSurvivesYamlConfigLoader(): void
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
              coverage: ignore
            YAML);

        try {
            $loaded = (new YamlConfigLoader())->load($yamlPath);
            $messages = $this->collectViolationMessages($loaded['architecture']);

            self::assertEdgeNotViolating($messages, 'OrderExtender', 'BaseEntity', 'YAML-loaded inheritance alias must accept Extends');
            self::assertEdgeViolates($messages, 'PaymentCaller', 'Helper', 'YAML-loaded inheritance alias must reject StaticCall');
        } finally {
            @unlink($yamlPath);
        }
    }

    /**
     * @param array<string, mixed> $configArray
     *
     * @return list<string> Violation messages for the layer-violation rule only.
     */
    private function collectViolationMessages(array $configArray): array
    {
        $analysis = $this->runPipelineWithConfig($configArray);

        return array_values(array_map(
            static fn(Violation $v): string => $v->message,
            array_filter(
                $analysis->violations,
                static fn(Violation $v): bool => $v->ruleName === LayerViolationRule::NAME,
            ),
        ));
    }

    /**
     * @param array<string, mixed> $configArray
     */
    private function runPipelineWithConfig(array $configArray): AnalysisResult
    {
        $factory = new ArchitectureConfigurationFactory();
        $result = $factory->fromArray($configArray);

        $container = (new ContainerFactory())->create();

        $holder = $container->get(ArchitectureProcessorInterface::class);
        self::assertInstanceOf(ArchitectureProcessorInterface::class, $holder);
        $holder->bind($result->configuration);

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);

        return $pipeline->analyze(AbsolutePath::fromString(self::FIXTURE_PATH));
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
            'coverage' => 'ignore',
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
