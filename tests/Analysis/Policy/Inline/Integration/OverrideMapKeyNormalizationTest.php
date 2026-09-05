<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Inline\Directive\Audit\AuthoredDirectiveGroup;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\AllowListBuilder;

/**
 * The keys of the run's per-file threshold-override map are already what
 * {@see RelativePath::fromString()} makes of them.
 *
 * {@see AuthoredDirectiveGroup} holds the file as a path and
 * {@see \Qualimetrix\Analysis\Policy\Inline\Directive\Audit\ThresholdDirectiveAudit::without()}
 * indexes the map back by that path's value. That round trip reaches the
 * bucket the run filled only while the producer's keys are normalized; a key
 * of `./src/A.php` would send every counterfactual to an empty bucket and call
 * a live directive inert.
 *
 * **The keys come from a run, not from a literal here.** The audit's own
 * helpers write into the map, so a test that spelled a key itself would assert
 * about its fixture and never reach the producer. What is measured is the map
 * the pipeline produced over a fixture tree, whose one live producer of a key
 * is `CollectionOrchestrator`.
 */
#[Group('integration')]
#[CoversClass(AuthoredDirectiveGroup::class)]
final class OverrideMapKeyNormalizationTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../Fixtures/ThresholdAudit';

    #[Test]
    public function itProducesOverrideMapKeysThatAreAlreadyNormalizedPaths(): void
    {
        $keys = array_keys(self::producedOverrides());

        self::assertNotSame([], $keys, 'a run that produced no override key proves nothing');

        foreach ($keys as $key) {
            self::assertIsString($key);
            self::assertSame(
                $key,
                RelativePath::fromString($key)->value(),
                'the run produced an override-map key that is not its own normalized form',
            );
        }
    }

    /**
     * The same claim from the consumer's end: a group built from a produced key
     * hands back a path whose value indexes that key.
     */
    #[Test]
    public function itRoundTripsAProducedKeyThroughTheAuthoredGroup(): void
    {
        $overrides = self::producedOverrides();

        foreach ($overrides as $key => $bindings) {
            \assert(\is_string($key));
            $first = $bindings[0];
            $authored = array_values(array_filter(
                $bindings,
                static fn(ThresholdOverride $binding): bool => $binding->line === $first->line
                    && $binding->rulePattern === $first->rulePattern,
            ));

            $group = AuthoredDirectiveGroup::of($key, $first->line, $first->rulePattern, $authored);

            self::assertArrayHasKey($group->file->value(), $overrides);
        }
    }

    /**
     * @return array<string, list<ThresholdOverride>>
     */
    private static function producedOverrides(): array
    {
        $container = (new ContainerFactory())->create();

        // The layer policy is bound by the composition root in production; a
        // run that never binds it fails in preparation, which says nothing
        // about override keys.
        $architecture = $container->get(LayerPolicyPreparationInterface::class);
        self::assertInstanceOf(ArchitecturePolicy::class, $architecture);
        $architecture->bind(new ArchitectureConfiguration(
            new LayerRegistry([]),
            AllowListBuilder::policyFromExactMap([]),
            CoverageMode::Ignore,
        ));

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);

        $root = AbsolutePath::fromString(self::FIXTURE);

        return $pipeline->analyze(
            new RunConfiguration([$root], [], $root, GeneratedFilePolicy::Include),
        )->thresholdOverrides;
    }
}
