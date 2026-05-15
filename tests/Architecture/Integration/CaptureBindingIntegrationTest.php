<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Architecture\Domain\ArchitectureConfigurationHolder;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * End-to-end test for Phase 2 Step E (direction 2b). Loads a YAML config with
 * a captured allow entry ({@code 'app-{module}': ['domain-{module}']}) through
 * the real {@see ArchitectureConfigurationFactory}, runs the real
 * {@see AnalysisPipelineInterface} against a two-module DDD fixture, and
 * asserts the binding-aware allow semantics:
 *
 * - {@code OrderController → Order} (same module Order) is permitted.
 * - {@code InventoryController → Stock} (same module Inventory) is permitted.
 * - {@code OrderController → Stock} (cross module Order → Inventory) is
 *   rejected — captured target {@code domain-{module}} substitutes the
 *   source-side {@code Order} binding, so it expects {@code domain-Order} and
 *   fails to match {@code domain-Inventory}.
 *
 * With {@code allow_cross_instance: true} on the long-form target, the
 * cross-module edge becomes permitted (the policy substitutes an empty
 * binding into the target match call).
 */
#[Group('integration')]
final class CaptureBindingIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/CaptureBindingSample';

    #[Test]
    public function sameModuleEdgesPassWhileCrossModuleEdgesViolate(): void
    {
        $config = self::baseConfig();

        $analysis = $this->runPipelineWithConfig($config);

        $layerViolations = $this->filterByRule($analysis->violations, LayerViolationRule::NAME);

        $messages = array_map(static fn(Violation $v): string => $v->message, $layerViolations);

        // Exactly one cross-module violation: OrderController → Stock.
        $crossModule = array_filter(
            $messages,
            static fn(string $m): bool => str_contains($m, 'app-Order') && str_contains($m, 'domain-Inventory'),
        );
        self::assertCount(1, $crossModule, 'OrderController → Stock must violate the same-module captured allow.');

        // No same-module edges should fire.
        $sameModule = array_filter(
            $messages,
            static fn(string $m): bool => (str_contains($m, 'app-Order') && str_contains($m, 'domain-Order'))
                || (str_contains($m, 'app-Inventory') && str_contains($m, 'domain-Inventory')),
        );
        self::assertSame([], $sameModule, 'Same-module edges must not violate the captured allow.');
    }

    #[Test]
    public function allowCrossInstanceFlagPermitsCrossModuleEdges(): void
    {
        $config = self::baseConfig();
        $config['allow'] = [
            'app-{module}' => [
                ['target' => 'domain-{module}', 'allow_cross_instance' => true],
            ],
        ];

        $analysis = $this->runPipelineWithConfig($config);

        $layerViolations = $this->filterByRule($analysis->violations, LayerViolationRule::NAME);
        self::assertSame([], $layerViolations, 'allow_cross_instance must lift the binding-identity check.');
    }

    #[Test]
    public function allowCrossInstanceSurvivesYamlConfigLoaderNormalization(): void
    {
        // Regression test for the YamlConfigLoader subtree-preservation
        // contract: snake_case long-form keys under architecture.allow.* must
        // survive key normalization untransformed, otherwise the validator
        // never sees them under the keys it expects.
        $yamlPath = tempnam(sys_get_temp_dir(), 'qmx_capture_binding_') . '.yaml';
        file_put_contents($yamlPath, <<<'YAML'
            architecture:
              layers:
                - name: 'app-{module}'
                  patterns: ['Fixtures\CaptureBindingSample\Module\{module}\App\**']
                - name: 'domain-{module}'
                  patterns: ['Fixtures\CaptureBindingSample\Module\{module}\Domain\**']
              allow:
                'app-{module}':
                  - target: 'domain-{module}'
                    allow_cross_instance: true
              coverage: ignore
            YAML);

        try {
            $loaded = (new YamlConfigLoader())->load($yamlPath);
            $analysis = $this->runPipelineWithConfig($loaded['architecture']);

            $layerViolations = $this->filterByRule($analysis->violations, LayerViolationRule::NAME);
            self::assertSame(
                [],
                $layerViolations,
                'YAML-loaded allow_cross_instance must lift the binding-identity check end-to-end.',
            );
        } finally {
            @unlink($yamlPath);
        }
    }

    /**
     * @param array<string, mixed> $configArray
     */
    private function runPipelineWithConfig(array $configArray): AnalysisResult
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
    private static function baseConfig(): array
    {
        return [
            'layers' => [
                [
                    'name' => 'app-{module}',
                    'patterns' => ['Fixtures\\CaptureBindingSample\\Module\\{module}\\App\\**'],
                ],
                [
                    'name' => 'domain-{module}',
                    'patterns' => ['Fixtures\\CaptureBindingSample\\Module\\{module}\\Domain\\**'],
                ],
            ],
            'allow' => [
                'app-{module}' => ['domain-{module}'],
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
