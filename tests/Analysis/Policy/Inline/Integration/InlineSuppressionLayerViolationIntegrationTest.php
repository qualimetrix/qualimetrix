<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Integration;

use PHPUnit\Framework\Attributes\Group;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Infrastructure\Console\Support\PreparedAnalysisFixture;

/**
 * Verifies that {@code @qmx-ignore architecture.layer-violation} on a source
 * declaration does not drop a finding attributed to an owned target through
 * the same {@see SuppressionFilter} that handles complexity / coupling rules.
 * Architecture findings retain the source use-site location and display,
 * but declaration controls follow their projected target subject.
 *
 * Suppression filtering happens AFTER the analysis pipeline:
 * {@see AnalysisPipelineInterface::analyze()} emits the raw finding
 * set together with per-file suppression tags; the filter layer is
 * responsible for applying them. This test runs the analysis pipeline,
 * loads its emitted suppressions into a fresh {@see SuppressionFilter},
 * and verifies the policy works end-to-end on architecture findings
 * specifically.
 *
 * The fixture pairs two controllers: one carries the source suppression tag,
 * the other doesn't. After analysis + filtering, both must remain because
 * their forbidden dependencies target the owned repository declaration.
 */
#[Group('integration')]
final class InlineSuppressionLayerViolationIntegrationTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Fixtures/IgnoreSample';
    private const string FIXTURE_NAMESPACE = 'Fixtures\\IgnoreSample';

    #[Test]
    public function itDoesNotDropATargetAttributedLayerViolationWhenTheSourceCarriesQmxIgnore(): void
    {
        $analysisResult = $this->analyse([
            'layers' => [
                ['name' => 'controller', 'patterns' => [self::FIXTURE_NAMESPACE . '\\Controller']],
                ['name' => 'service', 'patterns' => [self::FIXTURE_NAMESPACE . '\\Service']],
                ['name' => 'repository', 'patterns' => [self::FIXTURE_NAMESPACE . '\\Repository']],
                ['name' => 'domain', 'patterns' => [self::FIXTURE_NAMESPACE . '\\Domain']],
            ],
            'allow' => [
                'controller' => ['service'],
                'service' => ['repository', 'domain'],
                'repository' => ['domain'],
                'domain' => [],
            ],
            'coverage-gap' => 'ignore',
        ]);

        // Sanity: AnalysisPipeline must surface BOTH controllers as raw
        // findings — suppression is applied downstream, not inside the
        // pipeline. If only one fires here, the fixture is broken, not the
        // suppression filter.
        $rawSources = array_map(
            static fn(Finding $v): string => $v->symbolPath->toString(),
            $this->filterByRule($analysisResult->findings, LayerViolationRule::NAME),
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

        $suppressionFilter = new SuppressionFilter();
        $filtered = $suppressionFilter->apply($analysisResult->findings, $analysisResult->suppressions)->retained;

        $filteredSources = array_map(
            static fn(Finding $v): string => $v->symbolPath->toString(),
            $this->filterByRule($filtered, LayerViolationRule::NAME),
        );

        // The source symbol control is intentionally independent from the
        // owned repository target subject, so both source displays remain.
        self::assertNotEmpty(
            array_filter($filteredSources, static fn(string $s): bool => str_contains($s, 'PolicedController')),
            'After suppression: PolicedController without @qmx-ignore must remain.',
        );
        self::assertNotEmpty(
            array_filter($filteredSources, static fn(string $s): bool => str_contains($s, 'SilencedController')),
            'A source declaration control must not suppress a finding attributed to an owned target. Got sources: '
            . implode(', ', $filteredSources),
        );

        foreach ($this->filterByRule($filtered, LayerViolationRule::NAME) as $finding) {
            self::assertStringContainsString(
                'CustomerRepository',
                $finding->subject->toSymbolPath()->toString(),
                'The owned target declaration must own the finding identity.',
            );
        }
    }

    /** @param array<string, mixed> $architecture */
    private function analyse(array $architecture): \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult
    {
        $root = AbsolutePath::fromString(self::FIXTURE_PATH);
        $fixture = PreparedAnalysisFixture::start($root, [$root], ['architecture' => $architecture, 'include_generated' => true]);
        try {
            $holder = $fixture->container()->get(LayerPolicyPreparationInterface::class);
            self::assertInstanceOf(ArchitecturePolicy::class, $holder);
            $pipeline = $fixture->container()->get(AnalysisPipelineInterface::class);
            self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);
            return $pipeline->analyze($fixture->prepared()->runConfiguration);
        } finally {
            $fixture->close();
        }
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function filterByRule(array $findings, string $ruleName): array
    {
        return array_values(array_filter(
            $findings,
            static fn(Finding $v): bool => $v->ruleName === $ruleName,
        ));
    }
}
