<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Analysis\Run\Pipeline\DirectiveAuditReport;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\AllowListBuilder;

/**
 * The whole seam, with the production container and the production rules: a
 * fixture carrying one live and one dead threshold directive on the same
 * anchor, audited through the pipeline's second entry point.
 *
 * The pair is what makes the run evidence. A detector that answered "inert"
 * for everything — because it removed nothing, or removed the wrong thing, or
 * compared nothing — passes a single-directive fixture and fails here.
 *
 * It is also the only place where the audit's own control meets every rule the
 * project ships: the sweep re-executes all of them on an unchanged context and
 * refuses to answer if that does not reproduce the run.
 */
#[Group('integration')]
#[CoversClass(AnalysisPipeline::class)]
final class DirectiveAuditPipelineTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../Policy/Inline/Fixtures/ThresholdAudit';

    #[Test]
    public function itSeparatesTheLiveDirectiveFromTheDeadOneOnOneAnchor(): void
    {
        $thresholds = self::thresholdVerdicts(self::audit());

        self::assertSame(
            [
                'code-smell.long-parameter-list' => DirectiveEffect::Effective,
                'complexity.cyclomatic' => DirectiveEffect::Inert,
            ],
            $thresholds,
        );
    }

    /**
     * The suppression half arrives through the same call, from the same run.
     * The fixture carries no `@qmx-ignore`, so the assertion is about the
     * report's shape rather than its content: a caller asking one question
     * gets one answer covering both tags.
     */
    #[Test]
    public function itReportsWhatTheRunMeasuredAlongsideTheVerdicts(): void
    {
        $report = self::audit();

        self::assertTrue($report->coverage->isComplete());
        self::assertCount(1, $report->coverage->analyzedFiles);
        self::assertGreaterThan(0, $report->producedFindings);
    }

    private static function audit(): DirectiveAuditReport
    {
        $container = (new ContainerFactory())->create();

        // The layer policy is bound by the composition root in production; a
        // run that never binds it fails in preparation, which says nothing
        // about directives. An empty registry that ignores coverage is the
        // smallest binding that lets every other rule run.
        $architecture = $container->get(LayerPolicyPreparationInterface::class);
        self::assertInstanceOf(ArchitecturePolicy::class, $architecture);
        $architecture->bind(new ArchitectureConfiguration(
            new LayerRegistry([]),
            AllowListBuilder::policyFromExactMap([]),
            CoverageMode::Ignore,
        ));

        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipeline::class, $pipeline);

        $root = AbsolutePath::fromString(self::FIXTURE);

        return $pipeline->auditDirectives(
            new RunConfiguration([$root], [], $root, GeneratedFilePolicy::Include),
        );
    }

    /** @return array<string, DirectiveEffect> */
    private static function thresholdVerdicts(DirectiveAuditReport $report): array
    {
        $verdicts = [];

        foreach ($report->verdicts as $verdict) {
            \assert($verdict instanceof DirectiveVerdict);
            if ($verdict->site->form === 'threshold') {
                $verdicts[$verdict->site->target] = $verdict->effect;
            }
        }

        return $verdicts;
    }
}
