<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Baseline\BaselineEntryParser;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\SuppressionType;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStage;
use Qualimetrix\Core\Violation\Filter\ViolationFilterStageInterface;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Console\CliOnlyNarrowing;
use Qualimetrix\Infrastructure\Console\GitScopeFilterConfig;
use Qualimetrix\Infrastructure\Console\MeasuredViolationSet;
use Qualimetrix\Infrastructure\Console\ViolationFilterOptions;
use Qualimetrix\Infrastructure\Console\ViolationFilterPipeline;
use Qualimetrix\Infrastructure\Git\GitClient;
use Qualimetrix\Infrastructure\Git\GitScope;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The pipeline as a sequence: which stages it runs, in what order, what each
 * of them removes, and which of them the measured set is taken at.
 */
#[CoversClass(ViolationFilterPipeline::class)]
final class ViolationFilterPipelineTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $tempDirs = [];

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
        $pipeline = $this->createPipeline(new AnalysisConfiguration(
            excludePaths: ['vendor'],
            excludeNamespaces: ['App\\Generated'],
        ));

        $options = new ViolationFilterOptions(
            baselinePath: $this->writeBaselineFile([]),
            gitScope: $this->createGitScope(),
        );

        self::assertSame(
            [
                ViolationFilterStage::Suppression,
                ViolationFilterStage::PathExclusion,
                ViolationFilterStage::NamespaceExclusion,
                ViolationFilterStage::Baseline,
                ViolationFilterStage::GitScope,
            ],
            self::stageNames($pipeline->stages($options)),
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

        $stages = $pipeline->stages(new ViolationFilterOptions(
            baselinePath: $this->writeBaselineFile([]),
        ));

        self::assertSame(
            [ViolationFilterStage::Suppression, ViolationFilterStage::Baseline],
            self::stageNames($stages),
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
        $ignored = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService', metricValue: 25);

        $pipeline = $this->createPipeline();
        $pipeline->loadSuppressions([
            'src/Service/UserService.php' => [
                new Suppression(rule: '*', reason: 'Reviewed and accepted', line: 1, type: SuppressionType::File),
            ],
        ]);

        $result = $pipeline->filter([$ignored], new ViolationFilterOptions(
            baselinePath: $this->writeBaselineFile([
                $ignored->subject->toCanonical() => [
                    ['channel' => $ignored->channel()->toKey(), 'magnitudes' => [25], 'count' => 1],
                ],
            ]),
        ));

        self::assertSame([], $result->measuredViolations);
        self::assertSame(1, $result->removedCountBy(ViolationFilterStage::Suppression));
        self::assertSame(0, $result->removedCountBy(ViolationFilterStage::Baseline));
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
        $violation = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService', metricValue: 25);

        $pipeline = $this->createPipeline();
        $pipeline->loadSuppressions([
            'src/Service/UserService.php' => [
                new Suppression(rule: '*', reason: 'Reviewed and accepted', line: 1, type: SuppressionType::File),
            ],
        ]);

        $result = $pipeline->filter([$violation], new ViolationFilterOptions(
            baselinePath: $this->writeBaselineFile([
                $violation->subject->toCanonical() => [
                    ['channel' => $violation->channel()->toKey(), 'magnitudes' => [25], 'count' => 1],
                ],
            ]),
        ));

        self::assertSame([], $result->violations);
        self::assertSame([$violation], $result->removedBy(ViolationFilterStage::Suppression));
        self::assertSame([], $result->removedBy(ViolationFilterStage::Baseline));
    }

    #[Test]
    public function itDoesNotMeasureAFindingRemovedByPathExclusion(): void
    {
        $kept = $this->makeViolation('src/Service/UserService.php');
        $excluded = $this->makeViolation('generated/Proxy.php');

        $pipeline = $this->createPipeline(new AnalysisConfiguration(excludePaths: ['generated']));

        $result = $pipeline->filter([$kept, $excluded], new ViolationFilterOptions());

        self::assertSame([$kept], $result->measuredViolations);
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
        $architecture = $this->makeViolation('src/Foo/Service.php', 'App\\Foo', 'Service', LayerViolationRule::NAME);
        $ordinary = $this->makeViolation('src/Foo/Other.php', 'App\\Foo', 'Other');

        $pipeline = $this->createPipeline(new AnalysisConfiguration(excludeNamespaces: ['App\\Foo']));

        $result = $pipeline->filter([$architecture, $ordinary], new ViolationFilterOptions());

        self::assertSame([$architecture], $result->measuredViolations);
    }

    /**
     * Git scope is presentation only, so it runs after the measured set is
     * taken and cannot make an identity look absent under ADR 0017's pipeline order. This holds under
     * the old order too — it is a regression guard, not evidence of the move.
     */
    #[Test]
    public function itMarksNothingStaleOnAGitScopedRun(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService', metricValue: 25);

        $baselinePath = $this->writeBaselineFile([
            $violation->subject->toCanonical() => [
                ['channel' => $violation->channel()->toKey(), 'magnitudes' => [25], 'count' => 1],
            ],
        ]);

        // Nothing is staged, so the git-scope stage narrows the report to
        // nothing at all.
        $result = $this->createPipeline()->filter([$violation], new ViolationFilterOptions(
            baselinePath: $baselinePath,
            gitScope: $this->createGitScope(),
        ));

        self::assertSame([$violation], $result->measuredViolations);
        self::assertSame([], $result->violations);
        self::assertSame(0, $result->staleEntryCount());
    }

    // -- The baseline stage --

    #[Test]
    public function itAcceptsAGroupItsEntryBounds(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService', metricValue: 25);

        $result = $this->createPipeline()->filter([$violation], new ViolationFilterOptions(
            baselinePath: $this->writeBaselineFile([
                $violation->subject->toCanonical() => [
                    ['channel' => $violation->channel()->toKey(), 'magnitudes' => [25], 'count' => 1],
                ],
            ]),
        ));

        self::assertSame([], $result->violations);
        self::assertSame(1, $result->removedCountBy(ViolationFilterStage::Baseline));
    }

    /**
     * A measured breach reaches the report promoted to Error, carrying what
     * was accepted — end to end through the pipeline, not only through the
     * stage (ADR 0017).
     */
    #[Test]
    public function itReportsAMeasuredBreachAtErrorWithEveryMemberOfTheGroup(): void
    {
        $first = $this->makeViolation(
            'src/Service/UserService.php',
            'App\\Service',
            'UserService',
            'code-smell.goto',
            severity: Severity::Warning,
        );
        $second = $this->makeViolation(
            'src/Service/UserService.php',
            'App\\Service',
            'UserService',
            'code-smell.goto',
            severity: Severity::Warning,
        );

        $result = $this->createPipeline()->filter([$first, $second], new ViolationFilterOptions(
            baselinePath: $this->writeBaselineFile([
                $first->subject->toCanonical() => [
                    ['channel' => $first->channel()->toKey(), 'count' => 1],
                ],
            ]),
        ));

        self::assertCount(2, $result->violations);
        self::assertSame(
            [Severity::Error, Severity::Error],
            array_map(static fn(Violation $v): Severity => $v->severity, $result->violations),
        );
        self::assertSame(1, $result->violations[0]->acceptedLevel?->count);
        self::assertSame(0, $result->removedCountBy(ViolationFilterStage::Baseline));
    }

    /**
     * An entry the mechanism cannot apply says nothing about the debt: the
     * findings keep the severity their own rule gave them (ADR 0017 governing
     * invariant, observed through the pipeline).
     */
    #[Test]
    public function itKeepsTheConfiguredSeverityWhenTheEntryCannotBeApplied(): void
    {
        $violation = $this->makeViolation(
            'src/Service/UserService.php',
            'App\\Service',
            'UserService',
            'code-smell.goto',
            severity: Severity::Warning,
        );

        // A magnitude list on an occurrence channel: the entry claims a
        // boundary the channel's findings cannot be compared against.
        $result = $this->createPipeline()->filter([$violation], new ViolationFilterOptions(
            baselinePath: $this->writeBaselineFile([
                $violation->subject->toCanonical() => [
                    ['channel' => $violation->channel()->toKey(), 'magnitudes' => [1], 'count' => 1],
                ],
            ]),
        ));

        self::assertCount(1, $result->violations);
        self::assertSame(Severity::Warning, $result->violations[0]->severity);
        self::assertNull($result->violations[0]->acceptedLevel);
    }

    #[Test]
    public function itSkipsTheBaselineStageWhenNoPathIsGiven(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $result = $this->createPipeline()->filter([$violation], new ViolationFilterOptions());

        self::assertSame([$violation], $result->violations);
        self::assertSame(0, $result->removedCountBy(ViolationFilterStage::Baseline));
    }

    #[Test]
    public function itSkipsTheBaselineStageWhenThePathIsEmpty(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $result = $this->createPipeline()->filter([$violation], new ViolationFilterOptions(baselinePath: ''));

        self::assertSame([$violation], $result->violations);
        self::assertSame(0, $result->removedCountBy(ViolationFilterStage::Baseline));
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
        $violation = $this->makeViolation('src/Service/UserService.php');
        $subjectKey = self::subjectKey('App\\Nowhere', 'Ghost', 'src/Nowhere/Ghost.php');

        $result = $this->createPipeline()->filter([$violation], new ViolationFilterOptions(
            baselinePath: $this->writeBaselineFile([
                $subjectKey => [
                    ['channel' => 'nonexistent.channel#nonexistent.channel', 'count' => 1],
                ],
            ]),
        ));

        self::assertCount(1, $result->inertEntries);
        self::assertSame($subjectKey, $result->inertEntries[0]->subjectKey);
    }

    #[Test]
    public function itHasNoInertEntriesWithoutABaseline(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $result = $this->createPipeline()->filter([$violation], new ViolationFilterOptions());

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
        $violation = $this->makeViolation('src/Service/UserService.php');

        $result = $this->createPipeline()->filter([$violation], new ViolationFilterOptions(
            baselinePath: $this->writeBaselineFile([]),
        ));

        self::assertSame(['src'], $result->baselineScope);
    }

    #[Test]
    public function itHasANullBaselineScopeWithoutABaseline(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $result = $this->createPipeline()->filter([$violation], new ViolationFilterOptions());

        self::assertNull($result->baselineScope);
    }

    /**
     * A stale entry on an unrelated symbol is reported and changes nothing
     * else — the case v5 also handled, kept as a regression guard.
     */
    #[Test]
    public function itReportsAStaleEntryWithoutDisablingTheRestOfTheBaseline(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService', metricValue: 25);
        $otherSubjectKey = self::subjectKey('App\\Service', 'OtherClass', 'src/Service/OtherClass.php');

        $baselinePath = $this->writeBaselineFile([
            $violation->subject->toCanonical() => [
                ['channel' => $violation->channel()->toKey(), 'magnitudes' => [25], 'count' => 1],
            ],
            $otherSubjectKey => [
                ['channel' => 'code-smell.goto#code-smell.goto', 'count' => 3],
            ],
        ]);

        $result = $this->createPipeline()->filter([$violation], new ViolationFilterOptions($baselinePath));

        self::assertSame([], $result->violations);
        self::assertSame(1, $result->removedCountBy(ViolationFilterStage::Baseline));
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
        $stillFiring = $this->makeViolation(
            'src/Service/UserService.php',
            'App\\Service',
            'UserService',
            metricValue: 25,
        );

        $baselinePath = $this->writeBaselineFile([
            $stillFiring->subject->toCanonical() => [
                ['channel' => $stillFiring->channel()->toKey(), 'magnitudes' => [25], 'count' => 1],
                ['channel' => 'code-smell.goto#code-smell.goto', 'count' => 2],
            ],
        ]);

        $result = $this->createPipeline()->filter([$stillFiring], new ViolationFilterOptions($baselinePath));

        self::assertSame([], $result->violations, 'The surviving entry must still suppress its finding.');
        self::assertSame(1, $result->removedCountBy(ViolationFilterStage::Baseline));
        self::assertSame(1, $result->staleEntryCount());
        self::assertStringContainsString('code-smell.goto', $result->staleEntries[0]->identity->channel->toKey());
    }

    // -- Suppression --

    #[Test]
    public function itRemovesAFindingCoveredByAnIgnoreTag(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $pipeline = $this->createPipeline();
        $pipeline->loadSuppressions([
            'src/Service/UserService.php' => [
                new Suppression(rule: '*', reason: 'Ignoring for now', line: 1, type: SuppressionType::File),
            ],
        ]);

        $result = $pipeline->filter([$violation], new ViolationFilterOptions());

        self::assertSame([], $result->violations);
        self::assertSame(1, $result->removedCountBy(ViolationFilterStage::Suppression));
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

        $result = $pipeline->filter([$measured, $annotated], new ViolationFilterOptions(
            baselinePath: $this->writeBaselineFile([
                $measured->subject->toCanonical() => [
                    ['channel' => $measured->channel()->toKey(), 'count' => 1],
                ],
            ]),
            narrowing: new CliOnlyNarrowing(annotationSuppressionDisabled: true),
        ));

        self::assertSame([$annotated], $result->violations);
        self::assertSame(Severity::Warning, $result->violations[0]->severity);
        self::assertNull($result->violations[0]->acceptedLevel);
        self::assertSame([$measured], $result->measuredViolations);
        self::assertSame(0, $result->staleEntryCount());
        self::assertSame(
            [],
            $result->removedBy(ViolationFilterStage::Suppression),
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
                ['channel' => $measured->channel()->toKey(), 'count' => 1],
            ],
        ]);

        $applied = $this->createPipelineIgnoringLine21()
            ->filter([$measured, $annotated], new ViolationFilterOptions(baselinePath: $baselinePath));

        $disabled = $this->createPipelineIgnoringLine21()->filter(
            [$measured, $annotated],
            new ViolationFilterOptions(
                baselinePath: $baselinePath,
                narrowing: new CliOnlyNarrowing(annotationSuppressionDisabled: true),
            ),
        );

        self::assertSame($applied->measuredViolations, $disabled->measuredViolations);
        self::assertSame([$measured], $disabled->measuredViolations);
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
        $options = new ViolationFilterOptions(
            narrowing: new CliOnlyNarrowing(annotationSuppressionDisabled: true),
        );

        $result = $pipeline->filter([$measured, $annotated], $options);

        self::assertSame([$measured], $result->measuredViolations);
        self::assertSame(
            [ViolationFilterStage::Suppression],
            self::stageNames($pipeline->stages($options)),
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

        $result = $this->createPipelineIgnoringLine21()->filter(
            [$annotated, $measured],
            new ViolationFilterOptions(
                narrowing: new CliOnlyNarrowing(annotationSuppressionDisabled: true),
            ),
        );

        self::assertSame([$measured, $annotated], $result->violations);
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
        $kept = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService', line: 21);
        $byPath = $this->makeViolation('generated/Proxy.php', 'App\\Generated', 'Proxy', line: 21);
        $byNamespace = $this->makeViolation('src/Entity/User.php', 'App\\Entity', 'User', line: 21);

        $pipeline = $this->createPipeline(new AnalysisConfiguration(excludePaths: ['generated']));
        $pipeline->loadSuppressions([
            'src/Service/UserService.php' => [self::ignoreLine20()],
            'generated/Proxy.php' => [self::ignoreLine20()],
            'src/Entity/User.php' => [self::ignoreLine20()],
        ]);

        $result = $pipeline->filter([$kept, $byPath, $byNamespace], new ViolationFilterOptions(
            narrowing: new CliOnlyNarrowing(
                excludeNamespaces: ['App\\Entity'],
                annotationSuppressionDisabled: true,
            ),
        ));

        self::assertSame([$kept], $result->violations);
        self::assertSame([$byPath], $result->removedBy(ViolationFilterStage::PathExclusion));
        self::assertSame([$byNamespace], $result->removedBy(ViolationFilterStage::NamespaceExclusion));
        self::assertSame([], $result->measuredViolations);
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

        $result = $this->createPipelineIgnoringLine21()->filter(
            [$measured, $annotated],
            new ViolationFilterOptions(
                gitScope: $this->createGitScope(),
                narrowing: new CliOnlyNarrowing(annotationSuppressionDisabled: true),
            ),
        );

        self::assertSame([], $result->violations);
        self::assertSame([$measured], $result->measuredViolations);
    }

    // -- Path exclusion --

    #[Test]
    public function itRemovesFindingsUnderAnExcludedPathGivenOnTheCommandLine(): void
    {
        $kept = $this->makeViolation('src/Service/UserService.php');
        $excluded = $this->makeViolation('vendor/library/SomeClass.php');

        $options = new ViolationFilterOptions(
            narrowing: new CliOnlyNarrowing(excludePaths: ['vendor']),
        );

        $result = $this->createPipeline()->filter([$kept, $excluded], $options);

        self::assertSame([$kept], $result->violations);
        self::assertSame(1, $result->removedCountBy(ViolationFilterStage::PathExclusion));
    }

    #[Test]
    public function itRemovesFindingsUnderAnExcludedPathGivenInConfiguration(): void
    {
        $kept = $this->makeViolation('src/Service/UserService.php');
        $excluded = $this->makeViolation('generated/Proxy.php');

        $pipeline = $this->createPipeline(new AnalysisConfiguration(excludePaths: ['generated']));

        $result = $pipeline->filter([$kept, $excluded], new ViolationFilterOptions());

        self::assertSame([$kept], $result->violations);
        self::assertSame(1, $result->removedCountBy(ViolationFilterStage::PathExclusion));
    }

    #[Test]
    public function itMergesConfiguredAndCommandLinePathExclusions(): void
    {
        $kept = $this->makeViolation('src/Service/UserService.php');
        $configured = $this->makeViolation('generated/Proxy.php');
        $flagged = $this->makeViolation('vendor/library/SomeClass.php');

        $pipeline = $this->createPipeline(new AnalysisConfiguration(excludePaths: ['generated']));

        $options = new ViolationFilterOptions(
            narrowing: new CliOnlyNarrowing(excludePaths: ['vendor']),
        );

        $result = $pipeline->filter([$kept, $configured, $flagged], $options);

        self::assertSame([$kept], $result->violations);
        self::assertSame(2, $result->removedCountBy(ViolationFilterStage::PathExclusion));
    }

    #[Test]
    public function itRunsNoPathExclusionStageWhenNoPatternIsConfigured(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $result = $this->createPipeline()->filter([$violation], new ViolationFilterOptions());

        self::assertSame([$violation], $result->violations);
        self::assertSame(0, $result->removedCountBy(ViolationFilterStage::PathExclusion));
    }

    // -- Namespace exclusion --

    #[Test]
    public function itRemovesFindingsInAnExcludedNamespace(): void
    {
        $kept = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService');
        $excluded = $this->makeViolation('src/Generated/Proxy.php', 'App\\Generated', 'Proxy');

        $pipeline = $this->createPipeline(new AnalysisConfiguration(excludeNamespaces: ['App\\Generated']));

        $result = $pipeline->filter([$kept, $excluded], new ViolationFilterOptions());

        self::assertSame([$kept], $result->violations);
        self::assertSame(1, $result->removedCountBy(ViolationFilterStage::NamespaceExclusion));
    }

    #[Test]
    public function itRemovesFindingsInChildNamespacesOfAnExcludedOne(): void
    {
        $kept = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService');
        $excluded = $this->makeViolation('src/Generated/Sub/Proxy.php', 'App\\Generated\\Sub', 'Proxy');

        $pipeline = $this->createPipeline(new AnalysisConfiguration(excludeNamespaces: ['App\\Generated']));

        $result = $pipeline->filter([$kept, $excluded], new ViolationFilterOptions());

        self::assertSame([$kept], $result->violations);
        self::assertSame(1, $result->removedCountBy(ViolationFilterStage::NamespaceExclusion));
    }

    #[Test]
    public function itKeepsAFindingThatHasNoNamespaceToExclude(): void
    {
        $path = RelativePath::fromString('src/helpers.php');
        $symbol = SymbolPath::forFile($path);
        $fileLevel = new Violation(
            location: new Location($path, 10),
            subject: MetricSubject::aggregate($symbol),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'CCN too high',
            severity: Severity::Error,
        );

        $pipeline = $this->createPipeline(new AnalysisConfiguration(excludeNamespaces: ['App']));

        $result = $pipeline->filter([$fileLevel], new ViolationFilterOptions());

        self::assertSame([$fileLevel], $result->violations);
        self::assertSame(0, $result->removedCountBy(ViolationFilterStage::NamespaceExclusion));
    }

    #[Test]
    public function itMergesConfiguredAndCommandLineNamespaceExclusions(): void
    {
        $kept = $this->makeViolation('src/Service/UserService.php', 'App\\Service', 'UserService');
        $configured = $this->makeViolation('src/Generated/Proxy.php', 'App\\Generated', 'Proxy');
        $flagged = $this->makeViolation('src/Entity/User.php', 'App\\Entity', 'User');

        $pipeline = $this->createPipeline(new AnalysisConfiguration(excludeNamespaces: ['App\\Generated']));

        $options = new ViolationFilterOptions(
            narrowing: new CliOnlyNarrowing(excludeNamespaces: ['App\\Entity']),
        );

        $result = $pipeline->filter([$kept, $configured, $flagged], $options);

        self::assertSame([$kept], $result->violations);
        self::assertSame(2, $result->removedCountBy(ViolationFilterStage::NamespaceExclusion));
    }

    #[Test]
    public function itRunsNoNamespaceExclusionStageWhenNoPrefixIsConfigured(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $result = $this->createPipeline()->filter([$violation], new ViolationFilterOptions());

        self::assertSame([$violation], $result->violations);
        self::assertSame(0, $result->removedCountBy(ViolationFilterStage::NamespaceExclusion));
    }

    #[Test]
    public function itKeepsArchitectureRuleViolationsInExcludedNamespaces(): void
    {
        $architecture = $this->makeViolation('src/Foo/Service.php', 'App\\Foo', 'Service', LayerViolationRule::NAME);
        $ordinary = $this->makeViolation('src/Foo/Other.php', 'App\\Foo', 'Other');

        $pipeline = $this->createPipeline(new AnalysisConfiguration(excludeNamespaces: ['App\\Foo']));

        $result = $pipeline->filter([$architecture, $ordinary], new ViolationFilterOptions());

        self::assertSame([$architecture], $result->violations);
        self::assertSame(1, $result->removedCountBy(ViolationFilterStage::NamespaceExclusion));
    }

    // -- Git scope --

    #[Test]
    public function itRunsNoGitScopeStageWhenTheRunIsNotScoped(): void
    {
        $violation = $this->makeViolation('src/Service/UserService.php');

        $result = $this->createPipeline()->filter([$violation], new ViolationFilterOptions());

        self::assertSame([$violation], $result->violations);
        self::assertSame(0, $result->removedCountBy(ViolationFilterStage::GitScope));
    }

    // -- Helper methods --

    /**
     * @param list<ViolationFilterStageInterface> $stages
     *
     * @return list<ViolationFilterStage>
     */
    private static function stageNames(array $stages): array
    {
        return array_map(
            static fn(ViolationFilterStageInterface $stage): ViolationFilterStage => $stage->stage(),
            $stages,
        );
    }

    /**
     * Two findings on one occurrence channel of one symbol, one of which an
     * `@qmx-ignore-next-line` covers — the smallest fixture in which "the
     * group the ceiling measures" and "the findings analysis produced" differ.
     *
     * Warning severity, so a promotion to Error is visible as a change of
     * severity rather than as a no-op.
     *
     * @return array{Violation, Violation} the measured one, then the annotated one
     */
    private function makeAnnotatedPair(): array
    {
        return [
            $this->makeViolation(
                'src/Service/UserService.php',
                'App\\Service',
                'UserService',
                'code-smell.goto',
                severity: Severity::Warning,
                line: 10,
            ),
            $this->makeViolation(
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
    private function createPipelineIgnoringLine21(): ViolationFilterPipeline
    {
        $pipeline = $this->createPipeline();
        $pipeline->loadSuppressions([
            'src/Service/UserService.php' => [self::ignoreLine20()],
        ]);

        return $pipeline;
    }

    private function makeViolation(
        string $file,
        string $namespace = 'App',
        string $class = 'TestClass',
        string $ruleName = 'complexity.cyclomatic',
        int|float|null $metricValue = null,
        Severity $severity = Severity::Error,
        int $line = 10,
    ): Violation {
        $path = RelativePath::fromString($file);
        $symbol = SymbolPath::forClass($namespace, $class);

        return new Violation(
            location: new Location($path, $line),
            subject: MetricSubject::declaration(new DeclarationPath($symbol, $path, $line)),
            symbolPath: $symbol,
            ruleName: $ruleName,
            violationCode: $ruleName === 'code-smell.goto' ? $ruleName : $ruleName . '.callable',
            message: 'CCN too high',
            severity: $severity,
            metricValue: $metricValue,
        );
    }

    private function createPipeline(?AnalysisConfiguration $configuration = null): ViolationFilterPipeline
    {
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn($configuration ?? new AnalysisConfiguration());

        $declarations = StubChannelDeclarationRegistry::withDefaults();

        return new ViolationFilterPipeline(
            new BaselineLoader(new BaselineEntryParser($declarations)),
            $declarations,
            new MeasuredViolationSet(
                self::createStub(AnalysisPipelineInterface::class),
                new SuppressionFilter(),
                $configProvider,
            ),
        );
    }

    /**
     * A scope over an empty git repository: nothing is staged, so the stage
     * narrows the report to nothing.
     */
    private function createGitScope(): GitScopeFilterConfig
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

        return new GitScopeFilterConfig(
            gitClient: new GitClient($projectRoot),
            reportScope: new GitScope('staged'),
            strictMode: false,
            projectRoot: $projectRoot,
        );
    }

    /**
     * Writes a temporary version 11 baseline JSON file.
     *
     * @param array<string, list<array<string, mixed>>> $entries subject key => list of entry objects
     *                                                           (each in the `channel`/`magnitudes`/`count` shape)
     */
    private function writeBaselineFile(array $entries): string
    {
        $tmpBase = (string) tempnam(sys_get_temp_dir(), 'qmx_baseline_');
        $path = $tmpBase . '.json';
        $this->tempFiles[] = $tmpBase;
        $this->tempFiles[] = $path;

        $data = [
            'version' => 11,
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
            new DeclarationPath($symbol, RelativePath::fromString($file), 0),
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
