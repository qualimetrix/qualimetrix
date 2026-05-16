<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\CoverageMode;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\LayerRegistry;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Architecture\Processing\ArchitectureProcessorInterface;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Architecture\Support\AllowListBuilder;

/**
 * Verifies that {@code @qmx-ignore architecture.layer-violation} on a
 * symbol docblock drops the matching violation through the same
 * {@see SuppressionFilter} that handles complexity / coupling rules.
 * Architecture violations carry per-line locations (the offending
 * dependency expression) so the symbol-level suppression must apply to
 * them too.
 *
 * Suppression filtering happens AFTER the analysis pipeline:
 * {@see AnalysisPipelineInterface::analyze()} emits the raw violation
 * set together with per-file suppression tags; the filter layer is
 * responsible for applying them. This test runs the analysis pipeline,
 * loads its emitted suppressions into a fresh {@see SuppressionFilter},
 * and verifies the policy works end-to-end on architecture violations
 * specifically.
 *
 * The fixture pairs two controllers: one carries the suppression tag,
 * the other doesn't. After analysis + filtering, the suppressed
 * controller must NOT appear among `architecture.layer-violation` sources;
 * the un-suppressed controller must.
 */
#[Group('integration')]
final class InlineSuppressionLayerViolationIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/IgnoreSample';
    private const string FIXTURE_NAMESPACE = 'Fixtures\\IgnoreSample';

    #[Test]
    public function qmxIgnoreOnSymbol_dropsArchitectureLayerViolation(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('controller', new MembershipSpec([self::FIXTURE_NAMESPACE . '\\Controller'])),
            new LayerDefinition('service', new MembershipSpec([self::FIXTURE_NAMESPACE . '\\Service'])),
            new LayerDefinition('repository', new MembershipSpec([self::FIXTURE_NAMESPACE . '\\Repository'])),
            new LayerDefinition('domain', new MembershipSpec([self::FIXTURE_NAMESPACE . '\\Domain'])),
        ]);
        $policy = AllowListBuilder::policyFromExactMap([
            'controller' => ['service'],
            'service' => ['repository', 'domain'],
            'repository' => ['domain'],
            'domain' => [],
        ]);
        $architecture = new ArchitectureConfiguration($registry, $policy, CoverageMode::Ignore);

        $pipeline = $this->createPipelineWithArchitecture($architecture);
        $analysisResult = $pipeline->analyze(self::FIXTURE_PATH);

        // Sanity: AnalysisPipeline must surface BOTH controllers as raw
        // violations — suppression is applied downstream, not inside the
        // pipeline. If only one fires here, the fixture is broken, not the
        // suppression filter.
        $rawSources = array_map(
            static fn(Violation $v): string => $v->symbolPath->toString(),
            $this->filterByRule($analysisResult->violations, LayerViolationRule::NAME),
        );
        self::assertNotEmpty(
            array_filter($rawSources, static fn(string $s): bool => str_contains($s, 'PolicedController')),
            'Pipeline must emit a raw layer-violation for PolicedController.',
        );
        self::assertNotEmpty(
            array_filter($rawSources, static fn(string $s): bool => str_contains($s, 'SilencedController')),
            'Pipeline must emit a raw layer-violation for SilencedController too — '
            . 'the suppression layer runs downstream and must be exercised to drop it.',
        );

        // Load the per-file suppressions the analysis pipeline collected
        // from the fixture docblocks, then run the filter.
        $suppressionFilter = new SuppressionFilter();
        foreach ($analysisResult->suppressions as $file => $fileSuppressions) {
            $suppressionFilter->setSuppressions($file, $fileSuppressions);
        }

        $filtered = array_values(array_filter(
            $analysisResult->violations,
            static fn(Violation $v): bool => $suppressionFilter->shouldInclude($v),
        ));

        $filteredSources = array_map(
            static fn(Violation $v): string => $v->symbolPath->toString(),
            $this->filterByRule($filtered, LayerViolationRule::NAME),
        );

        // After suppression: PolicedController still fires, SilencedController gone.
        self::assertNotEmpty(
            array_filter($filteredSources, static fn(string $s): bool => str_contains($s, 'PolicedController')),
            'After suppression: PolicedController without @qmx-ignore must remain.',
        );
        self::assertEmpty(
            array_filter($filteredSources, static fn(string $s): bool => str_contains($s, 'SilencedController')),
            'After suppression: SilencedController carries `@qmx-ignore architecture.layer-violation` — '
            . 'must NOT appear. Got sources: ' . implode(', ', $filteredSources),
        );
    }

    private function createPipelineWithArchitecture(ArchitectureConfiguration $architecture): AnalysisPipelineInterface
    {
        $container = (new ContainerFactory())->create();

        $holder = $container->get(ArchitectureProcessorInterface::class);
        self::assertInstanceOf(ArchitectureProcessorInterface::class, $holder);
        $holder->bind($architecture);

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);

        return $pipeline;
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
