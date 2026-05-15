<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Architecture\Domain\ArchitectureConfigurationHolder;
use Qualimetrix\Architecture\Processing\LayerExpansionException;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * End-to-end test for Phase 2 direction 2 (template layers). Loads a YAML
 * config carrying a {@code domain-&#123;module&#125;} template through the
 * real {@see ArchitectureConfigurationFactory}, runs the real
 * {@see AnalysisPipelineInterface} against a module-structured fixture, and
 * asserts the expansion behaviour:
 *
 * - Concrete layers materialise for each observed module ({@code Audit},
 *   {@code Order}, {@code Reports}) — three classes in three modules
 *   produce three concrete layers.
 * - An empty (typo'd) template emits one {@code architecture.empty-template}
 *   warning.
 * - Layer-violation messages reference the expanded names, not the template.
 * - Setting the {@code max_expanded_layers} ceiling below the observed-tuple
 *   count produces an actionable runtime error.
 */
#[Group('integration')]
final class LayerTemplateExpansionIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/TemplateSample';

    #[Test]
    public function templateExpansion_producesOneConcreteLayerPerObservedModule(): void
    {
        $config = self::baseTemplateConfig();

        $analysis = $this->runPipelineWithConfig($config);

        $emptyTemplates = $this->filterByRule($analysis->violations, LayerViolationRule::EMPTY_TEMPLATE_DIAGNOSTIC_NAME);
        self::assertSame([], $emptyTemplates, 'A non-typo template should expand and not raise empty-template.');

        // Customer depends on Logger (shared) — under allow rules
        // `domain-Order -> shared` this is permitted; ensure no layer-violation
        // fires for the expanded layer pair.
        $allowedEdges = $this->filterByRule($analysis->violations, LayerViolationRule::NAME);
        self::assertSame([], $allowedEdges, 'Allowed edge under expanded names must not produce violations.');
    }

    #[Test]
    public function templateExpansion_emptyTemplateFiresWarning(): void
    {
        $config = self::baseTemplateConfig();
        $config['layers'][] = [
            'name' => 'noop-{module}',
            // Intentional typo: `Mdule` doesn't exist in the fixture.
            'patterns' => ['Fixtures\\TemplateSample\\Mdule\\{module}\\Domain\\**'],
        ];

        $analysis = $this->runPipelineWithConfig($config);

        $emptyTemplates = $this->filterByRule($analysis->violations, LayerViolationRule::EMPTY_TEMPLATE_DIAGNOSTIC_NAME);
        self::assertCount(1, $emptyTemplates, 'A typo template must emit exactly one empty-template diagnostic.');
        self::assertSame(Severity::Warning, $emptyTemplates[0]->severity);
        self::assertStringContainsString('noop-{module}', $emptyTemplates[0]->message);
    }

    #[Test]
    public function templateExpansion_ceilingBelowObservedCountFailsFast(): void
    {
        $config = self::baseTemplateConfig();
        // Fixture has 3 modules — ceiling = 1 must blow up.
        $config['max_expanded_layers'] = 1;

        $this->expectException(LayerExpansionException::class);
        $this->expectExceptionMessage('architecture.max_expanded_layers ceiling of 1');

        $this->runPipelineWithConfig($config);
    }

    #[Test]
    public function templateExpansion_disallowedEdgeUsesExpandedLayerNameInMessage(): void
    {
        $config = self::baseTemplateConfig();
        // Disallow `shared` from `domain-Order` by removing it from allow.
        $config['allow'] = [
            'domain-{module}' => [],
            'shared' => [],
        ];

        $analysis = $this->runPipelineWithConfig($config);

        $layerViolations = $this->filterByRule($analysis->violations, LayerViolationRule::NAME);
        self::assertNotEmpty($layerViolations, 'Expected the Customer -> Logger edge to violate the empty allow list.');

        $messages = array_map(static fn(Violation $v): string => $v->message, $layerViolations);
        $orderViolations = array_filter(
            $messages,
            static fn(string $m): bool => str_contains($m, 'domain-Order'),
        );
        self::assertNotEmpty(
            $orderViolations,
            'Violation messages must reference the expanded layer name "domain-Order" (concrete), not the template "domain-{module}".',
        );
    }

    /**
     * @param array<string, mixed> $configArray
     */
    private function runPipelineWithConfig(array $configArray): \Qualimetrix\Analysis\Pipeline\AnalysisResult
    {
        $factory = new ArchitectureConfigurationFactory();
        $result = $factory->fromArray($configArray);

        $container = (new ContainerFactory())->create();

        $holder = $container->get(ArchitectureConfigurationHolder::class);
        self::assertInstanceOf(ArchitectureConfigurationHolder::class, $holder);
        $holder->set($result->configuration);

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);

        return $pipeline->analyze(self::FIXTURE_PATH);
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseTemplateConfig(): array
    {
        return [
            'layers' => [
                [
                    'name' => 'shared',
                    'patterns' => ['Fixtures\\TemplateSample\\Shared\\**'],
                ],
                [
                    'name' => 'domain-{module}',
                    'patterns' => ['Fixtures\\TemplateSample\\Module\\{module}\\Domain\\**'],
                ],
            ],
            'allow' => [
                // Step D ships expansion but not capture-aware allow-list
                // resolution (that's Step E). For Step D the allow-list
                // works with concrete expanded names. The wildcard glob
                // 'domain-*' lets every concrete domain layer depend on
                // 'shared' without listing each module by hand.
                'domain-*' => ['shared'],
                'shared' => [],
            ],
            'coverage' => 'ignore',
        ];
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function filterByRule(array $violations, string $ruleName): array
    {
        return array_values(array_filter(
            $violations,
            static fn(Violation $v): bool => $v->ruleName === $ruleName,
        ));
    }
}
