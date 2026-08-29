<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\FindingProjection\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Git\ReportingGitScopeQuery;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The pipeline as a sequence: which stages it runs, in what order, what each
 * of them removes, and which of them the measured set is taken at.
 */
#[CoversClass(FindingProjector::class)]
final class FindingProjectorTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $tempDirs = [];

    /** @var array<string, list<Suppression>> */
    private array $suppressions = [];

    private FindingProjectionOptions $configuredOptions;

    protected function setUp(): void
    {
        $this->suppressions = [];
        $this->configuredOptions = new FindingProjectionOptions();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        foreach ($this->tempDirs as $dir) {
            self::removeDirectory($dir);
        }
    }

    // -- The stage sequence --

    /**
     * The order is a behavioural contract (ADR 0017), so the assertion reads the
     * pipeline's own stage list. Counters cannot stand in for it: they do not
     * distinguish "the baseline ran fourth" from "the baseline removed
     * nothing".
     */
    #[Test]
    public function itRunsTheBaselineStageImmediatelyBeforeGitScope(): void
    {
        $pipeline = $this->createPipeline(new FindingProjectionOptions(
            excludePaths: ['vendor'],
            excludeNamespaces: ['App\\Generated'],
        ));

        $options = new FindingProjectionOptions(
            baselinePath: $this->writeBaselineFile([]),
            gitScope: $this->createGitScope(),
        );

        $result = $this->project($pipeline, [], $options);
        self::assertSame(
            [
                FindingFilterStage::Suppression,
                FindingFilterStage::PathExclusion,
                FindingFilterStage::NamespaceExclusion,
                FindingFilterStage::Baseline,
                FindingFilterStage::GitScope,
            ],
            array_map(FindingFilterStage::from(...), array_keys($result->removedByStage)),
        );
    }

    /**
     * The pipeline names only the filtering a run performs: a stage whose
     * configuration is empty is absent from the list rather than present as
     * a no-op, and the relative order of the rest is unaffected.
     */
    #[Test]
    public function itOmitsStagesTheRunHasNothingToFeed(): void
    {
        $pipeline = $this->createPipeline();

        $result = $this->project($pipeline, [], new FindingProjectionOptions(
            baselinePath: $this->writeBaselineFile([]),
        ));

        self::assertSame(
            [FindingFilterStage::Suppression, FindingFilterStage::Baseline],
            array_map(FindingFilterStage::from(...), array_keys($result->removedByStage)),
        );
    }

    // -- The measured set (ADR 0017) --

    /**
     * The witness for the stage's move to the end of the pipeline: with the
     * baseline at stage 1 this was false by construction, because the
     * baseline saw the raw analysis output.
     *
     * Both halves matter. The ignored finding is not in the set the ceiling
     * measured, *and* the entry that bounded it is therefore reported as
     * stale — which is what a run does with an identity it did not measure.
     */
    #[Test]
    public function itDoesNotMeasureAFindingRemovedByAnIgnoreTag(): void
    {
        $ignored = $this->makeFinding('src/Service/UserService.php', 'App\\Service', 'UserService', metricValue: 25);

        $pipeline = $this->createPipeline();
        $this->suppressions = [
            'src/Service/UserService.php' => [
                new Suppression(rule: '*', reason: 'Reviewed and accepted', line: 1, type: SuppressionType::File),
            ],
        ];

        $result = $this->project($pipeline, [$ignored], new FindingProjectionOptions(
            baselinePath: $this->writeBaselineFile([
                $ignored->subject->toCanonical() => [
                    ['channel' => $ignored->channel()->code, 'magnitudes' => [25]],
                ],
            ]),
        ));

        self::assertSame([], $result->measuredFindings);
        self::assertSame(1, $result->removedCountBy(FindingFilterStage::Suppression));
        self::assertSame(0, $result->removedCountBy(FindingFilterStage::Baseline));
        self::assertSame(1, $result->staleEntryCount());
    }

    /**
     * The behaviour change the move brings with it: a suppression the user
     * wrote by hand outranks one a tool generated. The finding leaves at the
     * suppression stage, so the baseline never judges it.
     */
    #[Test]
    public function itLetsAnIgnoreTagWinOverAnEntryThatWouldHaveAcceptedTheFinding(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php', 'App\\Service', 'UserService', metricValue: 25);

        $pipeline = $this->createPipeline();
        $this->suppressions = [
            'src/Service/UserService.php' => [
                new Suppression(rule: '*', reason: 'Reviewed and accepted', line: 1, type: SuppressionType::File),
            ],
        ];

        $result = $this->project($pipeline, [$finding], new FindingProjectionOptions(
            baselinePath: $this->writeBaselineFile([
                $finding->subject->toCanonical() => [
                    ['channel' => $finding->channel()->code, 'magnitudes' => [25]],
                ],
            ]),
        ));

        self::assertSame([], $result->findings);
        self::assertSame([$finding], $result->removedBy(FindingFilterStage::Suppression));
        self::assertSame([], $result->removedBy(FindingFilterStage::Baseline));
    }

    #[Test]
    public function itDoesNotMeasureAFindingRemovedByPathExclusion(): void
    {
        $kept = $this->makeFinding('src/Service/UserService.php');
        $excluded = $this->makeFinding('generated/Proxy.php');

        $pipeline = $this->createPipeline(new FindingProjectionOptions(excludePaths: ['generated']));

        $result = $this->project($pipeline, [$kept, $excluded], new FindingProjectionOptions());

        self::assertSame([$kept], $result->measuredFindings);
    }

    /**
     * `exclude_namespaces` does not apply to `architecture.*` at all, so
     * those findings are in the measured set even inside an excluded
     * namespace — and are therefore captured. This is the one documented
     * exception to "an exclusion keeps a finding out of the baseline", and
     * the reason the exclusion case is written on an ordinary channel.
     */
    #[Test]
    public function itMeasuresAnArchitectureFindingInsideAnExcludedNamespace(): void
    {
        // The real channel, not a synthesised descendant of it: immunity is a
        // property the capability declares per channel, so an invented
        // `architecture.layer-violation.callable` is correctly not immune.
        $architecture = $this->makeFinding(
            'src/Foo/Service.php',
            'App\\Foo',
            'Service',
            LayerViolationRule::NAME,
            code: LayerViolationRule::NAME,
        );
        $ordinary = $this->makeFinding('src/Foo/Other.php', 'App\\Foo', 'Other');

        $pipeline = $this->createPipeline(new FindingProjectionOptions(excludeNamespaces: ['App\\Foo']));

        $result = $this->project($pipeline, [$architecture, $ordinary], new FindingProjectionOptions());

        self::assertSame([$architecture], $result->measuredFindings);
    }

    /**
     * Git scope is presentation only, so it runs after the measured set is
     * taken and cannot make an identity look absent under ADR 0017's pipeline order. This holds under
     * the old order too — it is a regression guard, not evidence of the move.
     */
    #[Test]
    public function itMarksNothingStaleOnAGitScopedRun(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php', 'App\\Service', 'UserService', metricValue: 25);

        $baselinePath = $this->writeBaselineFile([
            $finding->subject->toCanonical() => [
                ['channel' => $finding->channel()->code, 'magnitudes' => [25]],
            ],
        ]);

        // Nothing is staged, so the git-scope stage narrows the report to
        // nothing at all.
        $result = $this->project($this->createPipeline(), [$finding], new FindingProjectionOptions(
            baselinePath: $baselinePath,
            gitScope: $this->createGitScope(),
        ));

        self::assertSame([$finding], $result->measuredFindings);
        self::assertSame([], $result->findings);
        self::assertSame(0, $result->staleEntryCount());
    }

    // -- The baseline stage --

    #[Test]
    public function itAcceptsAGroupItsEntryBounds(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php', 'App\\Service', 'UserService', metricValue: 25);

        $result = $this->project($this->createPipeline(), [$finding], new FindingProjectionOptions(
            baselinePath: $this->writeBaselineFile([
                $finding->subject->toCanonical() => [
                    ['channel' => $finding->channel()->code, 'magnitudes' => [25]],
                ],
            ]),
        ));

        self::assertSame([], $result->findings);
        self::assertSame(1, $result->removedCountBy(FindingFilterStage::Baseline));
    }

    /**
     * A measured breach reaches the report promoted to Error, carrying what
     * was accepted — end to end through the pipeline, not only through the
     * stage (ADR 0017).
     */
    #[Test]
    public function itReportsAMeasuredBreachAtErrorWithEveryMemberOfTheGroup(): void
    {
        $first = $this->makeFinding(
            'src/Service/UserService.php',
            'App\\Service',
            'UserService',
            'code-smell.goto',
            severity: Severity::Warning,
        );
        $second = $this->makeFinding(
            'src/Service/UserService.php',
            'App\\Service',
            'UserService',
            'code-smell.goto',
            severity: Severity::Warning,
        );

        $result = $this->project($this->createPipeline(), [$first, $second], new FindingProjectionOptions(
            baselinePath: $this->writeBaselineFile([
                $first->subject->toCanonical() => [
                    ['channel' => $first->channel()->code, 'count' => 1],
                ],
            ]),
        ));

        self::assertCount(2, $result->findings);
        self::assertSame(
            [Severity::Error, Severity::Error],
            array_map(static fn(Finding $v): Severity => $v->severity, $result->findings),
        );
        self::assertSame(1, $result->findings[0]->acceptedLevel?->count);
        self::assertSame(0, $result->removedCountBy(FindingFilterStage::Baseline));
    }

    /**
     * An entry the mechanism cannot apply says nothing about the debt: the
     * findings keep the severity their own rule gave them (ADR 0017 governing
     * invariant, observed through the pipeline).
     */
    #[Test]
    public function itKeepsTheConfiguredSeverityWhenTheEntryCannotBeApplied(): void
    {
        $finding = $this->makeFinding(
            'src/Service/UserService.php',
            'App\\Service',
            'UserService',
            'code-smell.goto',
            severity: Severity::Warning,
        );

        // A magnitude list on an occurrence channel: the entry claims a
        // boundary the channel's findings cannot be compared against.
        $result = $this->project($this->createPipeline(), [$finding], new FindingProjectionOptions(
            baselinePath: $this->writeBaselineFile([
                $finding->subject->toCanonical() => [
                    ['channel' => $finding->channel()->code, 'magnitudes' => [1]],
                ],
            ]),
        ));

        self::assertCount(1, $result->findings);
        self::assertSame(Severity::Warning, $result->findings[0]->severity);
        self::assertNull($result->findings[0]->acceptedLevel);
    }

    #[Test]
    public function itSkipsTheBaselineStageWhenNoPathIsGiven(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php');

        $result = $this->project($this->createPipeline(), [$finding], new FindingProjectionOptions());

        self::assertSame([$finding], $result->findings);
        self::assertSame(0, $result->removedCountBy(FindingFilterStage::Baseline));
    }

    #[Test]
    public function itSkipsTheBaselineStageWhenThePathIsEmpty(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php');

        $result = $this->project($this->createPipeline(), [$finding], new FindingProjectionOptions(baselinePath: ''));

        self::assertSame([$finding], $result->findings);
        self::assertSame(0, $result->removedCountBy(FindingFilterStage::Baseline));
    }

    // -- Inert entries and baseline scope (ADR 0017) --

    /**
     * ADR 0017 requires `check` to be able to name an entry it could not apply —
     * symbol, channel and selector. The pipeline's job is only to deliver
     * the loader's own inert entries unconditionally; it does not filter or
     * interpret them.
     */
    #[Test]
    public function itCollectsInertEntriesFromTheLoadedBaseline(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php');
        $subjectKey = self::subjectKey('App\\Nowhere', 'Ghost', 'src/Nowhere/Ghost.php');

        $result = $this->project($this->createPipeline(), [$finding], new FindingProjectionOptions(
            baselinePath: $this->writeBaselineFile([
                $subjectKey => [
                    ['channel' => 'nonexistent.channel', 'count' => 1],
                ],
            ]),
        ));

        self::assertCount(1, $result->inertEntries);
        self::assertSame($subjectKey, $result->inertEntries[0]->subjectKey);
    }

    #[Test]
    public function itHasNoInertEntriesWithoutABaseline(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php');

        $result = $this->project($this->createPipeline(), [$finding], new FindingProjectionOptions());

        self::assertSame([], $result->inertEntries);
    }

    /**
     * `baselineScope` is what a future scope-guard command compares its own
     * run against (ADR 0017) — the pipeline only has to carry the file's own
     * `scope` field through unchanged.
     */
    #[Test]
    public function itReportsTheLoadedBaselinesScope(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php');

        $result = $this->project($this->createPipeline(), [$finding], new FindingProjectionOptions(
            baselinePath: $this->writeBaselineFile([]),
        ));

        self::assertSame(['src'], $result->baselineScope);
    }

    #[Test]
    public function itHasANullBaselineScopeWithoutABaseline(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php');

        $result = $this->project($this->createPipeline(), [$finding], new FindingProjectionOptions());

        self::assertNull($result->baselineScope);
    }

    /**
     * A stale entry on an unrelated symbol is reported and changes nothing
     * else — the case v5 also handled, kept as a regression guard.
     */
    #[Test]
    public function itReportsAStaleEntryWithoutDisablingTheRestOfTheBaseline(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php', 'App\\Service', 'UserService', metricValue: 25);
        $otherSubjectKey = self::subjectKey('App\\Service', 'OtherClass', 'src/Service/OtherClass.php');

        $baselinePath = $this->writeBaselineFile([
            $finding->subject->toCanonical() => [
                ['channel' => $finding->channel()->code, 'magnitudes' => [25]],
            ],
            $otherSubjectKey => [
                ['channel' => 'code-smell.goto', 'count' => 3],
            ],
        ]);

        $result = $this->project($this->createPipeline(), [$finding], new FindingProjectionOptions($baselinePath));

        self::assertSame([], $result->findings);
        self::assertSame(1, $result->removedCountBy(FindingFilterStage::Baseline));
        self::assertSame(1, $result->staleEntryCount());
        self::assertSame($otherSubjectKey, $result->staleEntries[0]->identity->subjectKey);
    }

    /**
     * The case enabled by ADR 0017's per-identity key, and the one v5's
     * symbol-level predicate could not produce: one channel of a symbol is
     * repaired while another still fires. The repaired entry goes stale, and
     * its neighbour under the same symbol must keep applying (ADR 0017).
     */
    #[Test]
    public function itKeepsApplyingSiblingEntriesWhenOneChannelOfASymbolIsRepaired(): void
    {
        $stillFiring = $this->makeFinding(
            'src/Service/UserService.php',
            'App\\Service',
            'UserService',
            metricValue: 25,
        );

        $baselinePath = $this->writeBaselineFile([
            $stillFiring->subject->toCanonical() => [
                ['channel' => $stillFiring->channel()->code, 'magnitudes' => [25]],
                ['channel' => 'code-smell.goto', 'count' => 2],
            ],
        ]);

        $result = $this->project($this->createPipeline(), [$stillFiring], new FindingProjectionOptions($baselinePath));

        self::assertSame([], $result->findings, 'The surviving entry must still suppress its finding.');
        self::assertSame(1, $result->removedCountBy(FindingFilterStage::Baseline));
        self::assertSame(1, $result->staleEntryCount());
        self::assertStringContainsString('code-smell.goto', $result->staleEntries[0]->identity->channel->code);
    }

    // -- Suppression --

    #[Test]
    public function itRemovesAFindingCoveredByAnIgnoreTag(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php');

        $pipeline = $this->createPipeline();
        $this->suppressions = [
            'src/Service/UserService.php' => [
                new Suppression(rule: '*', reason: 'Ignoring for now', line: 1, type: SuppressionType::File),
            ],
        ];

        $result = $this->project($pipeline, [$finding], new FindingProjectionOptions());

        self::assertSame([], $result->findings);
        self::assertSame(1, $result->removedCountBy(FindingFilterStage::Suppression));
    }

    // -- `--no-suppression-annotations`: report-only, never a wider set --

    /**
     * The flag is not a no-op: the annotated finding reaches the report.
     *
     * And it reaches it **at its own severity, judged by nothing** — the
     * ceiling never measured it, so there is no entry to compare it against.
     * This is where the flag used to drop the suppression stage from the run:
     * the group then measured two members against an entry that bounds one,
     * read as a breach, and promoted both findings to Error on code nobody
     * had touched.
     */
    #[Test]
    public function itReportsAnAnnotatedFindingAtItsOwnSeverityWhenTheRunDisablesAnnotations(): void
    {
        [$measured, $annotated] = $this->makeAnnotatedPair();

        $pipeline = $this->createPipelineIgnoringLine21();

        $result = $this->project($pipeline, [$measured, $annotated], new FindingProjectionOptions(
            baselinePath: $this->writeBaselineFile([
                $measured->subject->toCanonical() => [
                    ['channel' => $measured->channel()->code, 'count' => 1],
                ],
            ]),
            annotationSuppressionDisabled: true,
        ));

        self::assertSame([$annotated], $result->findings);
        self::assertSame(Severity::Warning, $result->findings[0]->severity);
        self::assertNull($result->findings[0]->acceptedLevel);
        self::assertSame([$measured], $result->measuredFindings);
        self::assertSame(0, $result->staleEntryCount());
        self::assertSame(
            [],
            $result->removedBy(FindingFilterStage::Suppression),
            'Nothing was removed from the run, so the run must not report a suppression.',
        );
    }

    /**
     * The invariant of ADR 0017 stated as an equality: a flag may narrow the
     * report, but the set the ceiling measures is the same either way.
     */
    #[Test]
    public function itMeasuresTheSameSetWhetherOrNotAnnotationsAreDisabled(): void
    {
        [$measured, $annotated] = $this->makeAnnotatedPair();

        $baselinePath = $this->writeBaselineFile([
            $measured->subject->toCanonical() => [
                ['channel' => $measured->channel()->code, 'count' => 1],
            ],
        ]);

        $applied = $this->project(
            $this->createPipelineIgnoringLine21(),
            [$measured, $annotated],
            new FindingProjectionOptions(baselinePath: $baselinePath),
        );

        $disabled = $this->project(
            $this->createPipelineIgnoringLine21(),
            [$measured, $annotated],
            new FindingProjectionOptions(
                baselinePath: $baselinePath,
                annotationSuppressionDisabled: true,
            ),
        );

        self::assertSame($applied->measuredFindings, $disabled->measuredFindings);
        self::assertSame([$measured], $disabled->measuredFindings);
    }

    /**
     * The case that made the old shape reachable at all: with no baseline, no
     * git scope and nothing excluded, the suppression stage was the only one
     * in the list — so dropping it left the measured set equal to the raw
     * analysis output. The stage is now unconditional, and the set is taken
     * before the annotated findings rejoin the report.
     */
    #[Test]
    public function itMeasuresTheSetAfterSuppressionWhenNoLaterStageRuns(): void
    {
        [$measured, $annotated] = $this->makeAnnotatedPair();

        $pipeline = $this->createPipelineIgnoringLine21();
        $options = new FindingProjectionOptions(annotationSuppressionDisabled: true);

        $result = $this->project($pipeline, [$measured, $annotated], $options);

        self::assertSame([$measured], $result->measuredFindings);
        self::assertSame(
            [FindingFilterStage::Suppression],
            array_map(FindingFilterStage::from(...), array_keys($result->removedByStage)),
            'Suppression defines the set, so it is in the list whatever the flags say.',
        );
    }

    /**
     * The chosen order, pinned: findings that were never suppressed keep
     * their positions and the restored ones are appended after them, rather
     * than being spliced back where analysis found them. Nothing downstream
     * depends on the interleaving — the formatters group by file — and one
     * documented order is worth more than an incidental one.
     */
    #[Test]
    public function itAppendsRestoredFindingsAfterTheOnesThatWereNeverSuppressed(): void
    {
        [$measured, $annotated] = $this->makeAnnotatedPair();

        $result = $this->project(
            $this->createPipelineIgnoringLine21(),
            [$annotated, $measured],
            new FindingProjectionOptions(annotationSuppressionDisabled: true),
        );

        self::assertSame([$measured, $annotated], $result->findings);
    }

    /**
     * A restored finding is still subject to the exclusions — configured and
     * CLI-supplied alike. Otherwise the flag would quietly reveal what an
     * exclusion took out, which is a second way for one flag to widen what a
     * run reports.
     */
    #[Test]
    public function itStillExcludesRestoredFindingsByPathAndNamespace(): void
    {
        $kept = $this->makeFinding('src/Service/UserService.php', 'App\\Service', 'UserService', line: 21);
        $byPath = $this->makeFinding('generated/Proxy.php', 'App\\Generated', 'Proxy', line: 21);
        $byNamespace = $this->makeFinding('src/Entity/User.php', 'App\\Entity', 'User', line: 21);

        $pipeline = $this->createPipeline(new FindingProjectionOptions(excludePaths: ['generated']));
        $this->suppressions = [
            'src/Service/UserService.php' => [self::ignoreLine20()],
            'generated/Proxy.php' => [self::ignoreLine20()],
            'src/Entity/User.php' => [self::ignoreLine20()],
        ];

        $result = $this->project($pipeline, [$kept, $byPath, $byNamespace], new FindingProjectionOptions(
            excludeNamespaces: ['App\\Entity'],
            annotationSuppressionDisabled: true,
        ));

        self::assertSame([$kept], $result->findings);
        self::assertSame([$byPath], $result->removedBy(FindingFilterStage::PathExclusion));
        self::assertSame([$byNamespace], $result->removedBy(FindingFilterStage::NamespaceExclusion));
        self::assertSame([], $result->measuredFindings);
    }

    /**
     * Git scope runs last and narrows everything the run reports, restored
     * findings included — the flag reveals annotations, not files outside the
     * scope.
     */
    #[Test]
    public function itStillNarrowsRestoredFindingsToTheGitScope(): void
    {
        [$measured, $annotated] = $this->makeAnnotatedPair();

        $result = $this->project(
            $this->createPipelineIgnoringLine21(),
            [$measured, $annotated],
            new FindingProjectionOptions(
                gitScope: $this->createGitScope(),
                annotationSuppressionDisabled: true,
            ),
        );

        self::assertSame([], $result->findings);
        self::assertSame([$measured], $result->measuredFindings);
    }

    // -- Path exclusion --

    #[Test]
    public function itRemovesFindingsUnderAnExcludedPathGivenOnTheCommandLine(): void
    {
        $kept = $this->makeFinding('src/Service/UserService.php');
        $excluded = $this->makeFinding('vendor/library/SomeClass.php');

        $options = new FindingProjectionOptions(excludePaths: ['vendor']);

        $result = $this->project($this->createPipeline(), [$kept, $excluded], $options);

        self::assertSame([$kept], $result->findings);
        self::assertSame(1, $result->removedCountBy(FindingFilterStage::PathExclusion));
    }

    #[Test]
    public function itRemovesFindingsUnderAnExcludedPathGivenInConfiguration(): void
    {
        $kept = $this->makeFinding('src/Service/UserService.php');
        $excluded = $this->makeFinding('generated/Proxy.php');

        $pipeline = $this->createPipeline(new FindingProjectionOptions(excludePaths: ['generated']));

        $result = $this->project($pipeline, [$kept, $excluded], new FindingProjectionOptions());

        self::assertSame([$kept], $result->findings);
        self::assertSame(1, $result->removedCountBy(FindingFilterStage::PathExclusion));
    }

    #[Test]
    public function itMergesConfiguredAndCommandLinePathExclusions(): void
    {
        $kept = $this->makeFinding('src/Service/UserService.php');
        $configured = $this->makeFinding('generated/Proxy.php');
        $flagged = $this->makeFinding('vendor/library/SomeClass.php');

        $pipeline = $this->createPipeline(new FindingProjectionOptions(excludePaths: ['generated']));

        $options = new FindingProjectionOptions(excludePaths: ['vendor']);

        $result = $this->project($pipeline, [$kept, $configured, $flagged], $options);

        self::assertSame([$kept], $result->findings);
        self::assertSame(2, $result->removedCountBy(FindingFilterStage::PathExclusion));
    }

    #[Test]
    public function itRunsNoPathExclusionStageWhenNoPatternIsConfigured(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php');

        $result = $this->project($this->createPipeline(), [$finding], new FindingProjectionOptions());

        self::assertSame([$finding], $result->findings);
        self::assertSame(0, $result->removedCountBy(FindingFilterStage::PathExclusion));
    }

    // -- Namespace exclusion --

    #[Test]
    public function itRemovesFindingsInAnExcludedNamespace(): void
    {
        $kept = $this->makeFinding('src/Service/UserService.php', 'App\\Service', 'UserService');
        $excluded = $this->makeFinding('src/Generated/Proxy.php', 'App\\Generated', 'Proxy');

        $pipeline = $this->createPipeline(new FindingProjectionOptions(excludeNamespaces: ['App\\Generated']));

        $result = $this->project($pipeline, [$kept, $excluded], new FindingProjectionOptions());

        self::assertSame([$kept], $result->findings);
        self::assertSame(1, $result->removedCountBy(FindingFilterStage::NamespaceExclusion));
    }

    #[Test]
    public function itRemovesFindingsInChildNamespacesOfAnExcludedOne(): void
    {
        $kept = $this->makeFinding('src/Service/UserService.php', 'App\\Service', 'UserService');
        $excluded = $this->makeFinding('src/Generated/Sub/Proxy.php', 'App\\Generated\\Sub', 'Proxy');

        $pipeline = $this->createPipeline(new FindingProjectionOptions(excludeNamespaces: ['App\\Generated']));

        $result = $this->project($pipeline, [$kept, $excluded], new FindingProjectionOptions());

        self::assertSame([$kept], $result->findings);
        self::assertSame(1, $result->removedCountBy(FindingFilterStage::NamespaceExclusion));
    }

    #[Test]
    public function itKeepsAFindingThatHasNoNamespaceToExclude(): void
    {
        $path = RelativePath::fromString('src/helpers.php');
        $symbol = SymbolPath::forFile($path);
        $fileLevel = new Finding(
            location: new Location($path, 10),
            subject: MetricSubject::aggregate($symbol),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'CCN too high',
            severity: Severity::Error,
        );

        $pipeline = $this->createPipeline(new FindingProjectionOptions(excludeNamespaces: ['App']));

        $result = $this->project($pipeline, [$fileLevel], new FindingProjectionOptions());

        self::assertSame([$fileLevel], $result->findings);
        self::assertSame(0, $result->removedCountBy(FindingFilterStage::NamespaceExclusion));
    }

    #[Test]
    public function itMergesConfiguredAndCommandLineNamespaceExclusions(): void
    {
        $kept = $this->makeFinding('src/Service/UserService.php', 'App\\Service', 'UserService');
        $configured = $this->makeFinding('src/Generated/Proxy.php', 'App\\Generated', 'Proxy');
        $flagged = $this->makeFinding('src/Entity/User.php', 'App\\Entity', 'User');

        $pipeline = $this->createPipeline(new FindingProjectionOptions(excludeNamespaces: ['App\\Generated']));

        $options = new FindingProjectionOptions(excludeNamespaces: ['App\\Entity']);

        $result = $this->project($pipeline, [$kept, $configured, $flagged], $options);

        self::assertSame([$kept], $result->findings);
        self::assertSame(2, $result->removedCountBy(FindingFilterStage::NamespaceExclusion));
    }

    #[Test]
    public function itRunsNoNamespaceExclusionStageWhenNoPrefixIsConfigured(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php');

        $result = $this->project($this->createPipeline(), [$finding], new FindingProjectionOptions());

        self::assertSame([$finding], $result->findings);
        self::assertSame(0, $result->removedCountBy(FindingFilterStage::NamespaceExclusion));
    }

    #[Test]
    public function itKeepsArchitectureRuleFindingsInExcludedNamespaces(): void
    {
        // The real channel, not a synthesised descendant of it: immunity is a
        // property the capability declares per channel, so an invented
        // `architecture.layer-violation.callable` is correctly not immune.
        $architecture = $this->makeFinding(
            'src/Foo/Service.php',
            'App\\Foo',
            'Service',
            LayerViolationRule::NAME,
            code: LayerViolationRule::NAME,
        );
        $ordinary = $this->makeFinding('src/Foo/Other.php', 'App\\Foo', 'Other');

        $pipeline = $this->createPipeline(new FindingProjectionOptions(excludeNamespaces: ['App\\Foo']));

        $result = $this->project($pipeline, [$architecture, $ordinary], new FindingProjectionOptions());

        self::assertSame([$architecture], $result->findings);
        self::assertSame(1, $result->removedCountBy(FindingFilterStage::NamespaceExclusion));
    }

    // -- Git scope --

    #[Test]
    public function itRunsNoGitScopeStageWhenTheRunIsNotScoped(): void
    {
        $finding = $this->makeFinding('src/Service/UserService.php');

        $result = $this->project($this->createPipeline(), [$finding], new FindingProjectionOptions());

        self::assertSame([$finding], $result->findings);
        self::assertSame(0, $result->removedCountBy(FindingFilterStage::GitScope));
    }

    // -- Helper methods --

    /**
     * Two findings on one occurrence channel of one symbol, one of which an
     * `@qmx-ignore-next-line` covers — the smallest fixture in which "the
     * group the ceiling measures" and "the findings analysis produced" differ.
     *
     * Warning severity, so a promotion to Error is visible as a change of
     * severity rather than as a no-op.
     *
     * @return array{Finding, Finding} the measured one, then the annotated one
     */
    private function makeAnnotatedPair(): array
    {
        return [
            $this->makeFinding(
                'src/Service/UserService.php',
                'App\\Service',
                'UserService',
                'code-smell.goto',
                severity: Severity::Warning,
                line: 10,
            ),
            $this->makeFinding(
                'src/Service/UserService.php',
                'App\\Service',
                'UserService',
                'code-smell.goto',
                severity: Severity::Warning,
                line: 21,
            ),
        ];
    }

    private static function ignoreLine20(): Suppression
    {
        return new Suppression(rule: '*', reason: 'Reviewed and accepted', line: 20, type: SuppressionType::NextLine);
    }

    /**
     * A pipeline whose suppression filter covers line 21 of the pair's file
     * and nothing else.
     */
    private function createPipelineIgnoringLine21(): FindingProjector
    {
        $pipeline = $this->createPipeline();
        $this->suppressions = [
            'src/Service/UserService.php' => [self::ignoreLine20()],
        ];

        return $pipeline;
    }

    private function makeFinding(
        string $file,
        string $namespace = 'App',
        string $class = 'TestClass',
        string $ruleName = 'complexity.cyclomatic',
        int|float|null $metricValue = null,
        Severity $severity = Severity::Error,
        int $line = 10,
        ?string $code = null,
    ): Finding {
        $path = RelativePath::fromString($file);
        $symbol = SymbolPath::forClass($namespace, $class);

        return new Finding(
            location: new Location($path, $line),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, $path, DeclarationOrdinal::fromRank(0))),
            symbolPath: $symbol,
            ruleName: $ruleName,
            code: $code ?? $ruleName,
            message: 'CCN too high',
            severity: $severity,
            metricValue: $metricValue,
        );
    }

    private function createPipeline(?FindingProjectionOptions $configuration = null): FindingProjector
    {
        $this->configuredOptions = $configuration ?? new FindingProjectionOptions();

        $declarations = StubChannelDeclarationRegistry::withDefaults();

        return new FindingProjector(
            new SuppressionFilter(),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            $declarations,
            new ReportingGitScopeQuery(),
        );
    }

    /** @param list<Finding> $findings */
    private function project(
        FindingProjector $projector,
        array $findings,
        FindingProjectionOptions $options,
    ): FindingProjectionResult {
        $options = new FindingProjectionOptions(
            baselinePath: $options->baselinePath ?? $this->configuredOptions->baselinePath,
            excludePaths: array_values(array_unique([...$this->configuredOptions->excludePaths, ...$options->excludePaths])),
            excludeNamespaces: array_values(array_unique([...$this->configuredOptions->excludeNamespaces, ...$options->excludeNamespaces])),
            annotationSuppressionDisabled: $options->annotationSuppressionDisabled,
            gitScope: $options->gitScope,
        );

        return $projector->project($findings, $this->suppressions, $options);
    }

    /**
     * A scope over an empty git repository: nothing is staged, so the stage
     * narrows the report to nothing.
     */
    private function createGitScope(): GitScopeRequest
    {
        $dir = sys_get_temp_dir() . '/qmx-pipeline-git-' . uniqid();
        mkdir($dir, 0777, true);
        $realPath = realpath($dir);
        if ($realPath === false) {
            throw new RuntimeException('Failed to resolve path: ' . $dir);
        }

        $this->tempDirs[] = $realPath;

        foreach (['git init', 'git config user.email "test@example.com"', 'git config user.name "Test User"'] as $command) {
            $process = Process::fromShellCommandline($command, $realPath);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new RuntimeException(\sprintf('Command failed: %s', $process->getErrorOutput()));
            }
        }

        $projectRoot = AbsolutePath::fromString($realPath);

        return new GitScopeRequest(
            reference: 'staged',
            projectRoot: $projectRoot,
            includeParentNamespaces: true,
        );
    }

    /**
     * Writes a temporary version 11 baseline JSON file.
     *
     * @param array<string, list<array<string, mixed>>> $entries subject key => list of entry objects
     *                                                           (each in the `channel`/`magnitudes` or
     *                                                           `channel`/`count` shape)
     */
    private function writeBaselineFile(array $entries): string
    {
        $tmpBase = (string) tempnam(sys_get_temp_dir(), 'qmx_baseline_');
        $path = $tmpBase . '.json';
        $this->tempFiles[] = $tmpBase;
        $this->tempFiles[] = $path;

        $data = [
            'version' => 13,
            'generated' => (new DateTimeImmutable())->format('c'),
            'scope' => ['src'],
            'entries' => $entries,
        ];

        file_put_contents($path, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));

        return $path;
    }

    private static function subjectKey(string $namespace, string $class, string $file): string
    {
        $symbol = SymbolPath::forClass($namespace, $class);

        return MetricSubject::declaration(
            DeclarationPath::of($symbol, RelativePath::fromString($file), DeclarationOrdinal::fromRank(0)),
        )->toCanonical();
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
