<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Configuration\Architecture\ArchitectureConfigurationFactory;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Core\Architecture\ArchitectureConfigurationHolder;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Rules\Architecture\LayerViolationRule;

/**
 * End-to-end test for Phase 2 Step F (direction 3 — exclude clause).
 *
 * Runs the real analysis pipeline against {@code tests/Fixtures/ArchitectureExcludeSample}
 * via two complementary paths:
 *
 * 1. Programmatic config through {@see ArchitectureConfigurationFactory::fromArray()}
 *    — exercises the validator + downstream membership/exclusion evaluation
 *    end-to-end without going through YAML normalization.
 * 2. YAML config through {@see YamlConfigLoader} — pins the loader-layer
 *    behavior of the {@code exclude} block (catches the Step E lesson:
 *    every YAML-surfaced feature must be tested through the loader, not just
 *    through {@code fromArray()}).
 *
 * The fixture has every classified class depend on a shared {@code Marker}
 * (which sits in its own self-only allow-list layer). With every layer
 * self-allow-only, a {@code source → marker} edge produces a violation iff
 * the source class is actually assigned to a non-{@code marker} layer.
 * Excluded classes (those filtered by the exclude clause) fall outside
 * every layer under {@code coverage: ignore} and produce no violation —
 * that absence is the evidence of correct exclusion.
 */
#[Group('integration')]
final class LayerExcludeIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../../Fixtures/ArchitectureExcludeSample';
    private const string FIXTURE_NAMESPACE = 'Fixtures\\ArchitectureExcludeSample';

    #[Test]
    public function staticLayerExcludeFiltersSubtreeOutOfMembership(): void
    {
        $analysis = $this->runPipelineWithConfig($this->baseConfig());

        $violations = $this->filterByRule($analysis->violations, LayerViolationRule::NAME);
        $sourceFqns = $this->collectSourceFqns($violations);

        // UserService sits in `service` and depends on Marker — violation
        // surfaces because every layer self-allows only.
        self::assertContains(
            self::FIXTURE_NAMESPACE . '\\Service\\UserService',
            $sourceFqns,
            'UserService must remain assigned to the service layer after exclude evaluation.',
        );

        // OldUserService sits in `Service\Legacy\` which the exclude clause
        // filters out of `service`. With CoverageMode::Ignore, an unassigned
        // class produces no architecture violation.
        self::assertNotContains(
            self::FIXTURE_NAMESPACE . '\\Service\\Legacy\\OldUserService',
            $sourceFqns,
            'OldUserService sits in the excluded Legacy subtree — it must be unassigned.',
        );
    }

    #[Test]
    public function templateLayerExcludeFiltersPerInstance(): void
    {
        $analysis = $this->runPipelineWithConfig($this->baseConfig());

        $violations = $this->filterByRule($analysis->violations, LayerViolationRule::NAME);
        $sourceFqns = $this->collectSourceFqns($violations);

        // Order and Stock are in `module-Order` and `module-Inventory` —
        // assigned and visible as sources of violations.
        self::assertContains(
            self::FIXTURE_NAMESPACE . '\\Module\\Order\\Domain\\Order',
            $sourceFqns,
            'Order must remain assigned to the module-Order layer after exclude evaluation.',
        );
        self::assertContains(
            self::FIXTURE_NAMESPACE . '\\Module\\Inventory\\Domain\\Stock',
            $sourceFqns,
            'Stock must remain assigned to the module-Inventory layer after exclude evaluation.',
        );

        // OrderProxy sits in `Module\Order\Domain\Generated\` — the
        // template's exclude.patterns filters this subtree per-instance
        // (binding `{m}` to `Order`). The class becomes unassigned and
        // produces no architecture violation.
        self::assertNotContains(
            self::FIXTURE_NAMESPACE . '\\Module\\Order\\Domain\\Generated\\OrderProxy',
            $sourceFqns,
            'OrderProxy sits in the excluded module-Order Generated subtree — it must be unassigned.',
        );
    }

    #[Test]
    public function excludeBlockSurvivesYamlConfigLoaderNormalization(): void
    {
        // Step E regression-style guard: an end-to-end test through YAML must
        // accompany every YAML-surfaced feature so the loader's key
        // normalization is exercised.
        $yamlPath = tempnam(sys_get_temp_dir(), 'qmx_exclude_') . '.yaml';
        file_put_contents($yamlPath, <<<'YAML'
            architecture:
              layers:
                - name: service
                  patterns: ['Fixtures\ArchitectureExcludeSample\Service\**']
                  exclude:
                    patterns: ['Fixtures\ArchitectureExcludeSample\Service\Legacy\**']
                - name: 'module-{m}'
                  patterns: ['Fixtures\ArchitectureExcludeSample\Module\{m}\**']
                  exclude:
                    patterns: ['Fixtures\ArchitectureExcludeSample\Module\{m}\Domain\Generated\**']
                - name: marker
                  patterns: ['Fixtures\ArchitectureExcludeSample\Marker\**']
              allow:
                service: []
                'module-{m}': []
                marker: []
              coverage: ignore
            YAML);

        try {
            $loaded = (new YamlConfigLoader())->load($yamlPath);
            $analysis = $this->runPipelineWithConfig($loaded['architecture']);

            $violations = $this->filterByRule($analysis->violations, LayerViolationRule::NAME);
            $sourceFqns = $this->collectSourceFqns($violations);

            // Assigned classes still produce violations.
            self::assertContains(
                self::FIXTURE_NAMESPACE . '\\Service\\UserService',
                $sourceFqns,
            );
            self::assertContains(
                self::FIXTURE_NAMESPACE . '\\Module\\Order\\Domain\\Order',
                $sourceFqns,
            );

            // Excluded classes do not.
            self::assertNotContains(
                self::FIXTURE_NAMESPACE . '\\Service\\Legacy\\OldUserService',
                $sourceFqns,
            );
            self::assertNotContains(
                self::FIXTURE_NAMESPACE . '\\Module\\Order\\Domain\\Generated\\OrderProxy',
                $sourceFqns,
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
    private function baseConfig(): array
    {
        return [
            'layers' => [
                [
                    'name' => 'service',
                    'patterns' => [self::FIXTURE_NAMESPACE . '\\Service\\**'],
                    'exclude' => [
                        'patterns' => [self::FIXTURE_NAMESPACE . '\\Service\\Legacy\\**'],
                    ],
                ],
                [
                    'name' => 'module-{m}',
                    'patterns' => [self::FIXTURE_NAMESPACE . '\\Module\\{m}\\**'],
                    'exclude' => [
                        'patterns' => [self::FIXTURE_NAMESPACE . '\\Module\\{m}\\Domain\\Generated\\**'],
                    ],
                ],
                [
                    'name' => 'marker',
                    'patterns' => [self::FIXTURE_NAMESPACE . '\\Marker\\**'],
                ],
            ],
            'allow' => [
                'service' => [],
                'module-{m}' => [],
                'marker' => [],
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

    /**
     * @param list<Violation> $violations
     *
     * @return list<string>
     */
    private function collectSourceFqns(array $violations): array
    {
        $seen = [];
        foreach ($violations as $violation) {
            $seen[$violation->symbolPath->toString()] = true;
        }

        return array_keys($seen);
    }
}
