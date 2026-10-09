<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationRule;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationResultProvider;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateBlock;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateBlockFinder;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateLocation;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\Duplication\Support\SplitSameContentFixture;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(CodeDuplicationRule::class)]
#[CoversClass(CodeDuplicationOptions::class)]
#[CoversClass(DuplicateBlock::class)]
#[CoversClass(DuplicateLocation::class)]
#[CoversClass(DuplicateBlockFinder::class)]
final class CodeDuplicationRuleTest extends TestCase
{
    private const string CONTENT_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private DuplicationResultProvider $resultProvider;

    protected function setUp(): void
    {
        $this->resultProvider = new DuplicationResultProvider();
    }

    #[Test]
    public function itReportsEveryCopyWithUniqueIdentityAfterRealFinderEvidence(): void
    {
        $request = SplitSameContentFixture::request();
        $first = \array_slice($request->retokenized->streams[0]->values, SplitSameContentFixture::FIRST_OFFSET, SplitSameContentFixture::CONTENT_LENGTH);
        $second = \array_slice($request->retokenized->streams[0]->values, SplitSameContentFixture::SECOND_OFFSET, SplitSameContentFixture::CONTENT_LENGTH);
        self::assertSame($first, $second);

        $blocks = (new DuplicateBlockFinder())->find($request);
        $hash = hash('sha256', json_encode(['tokenCount' => \count($first), 'tokens' => $first], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        $copies = [];
        $expectedValues = [];
        foreach ($blocks as $block) {
            if ($block->contentHash !== $hash) {
                continue;
            }
            foreach ($block->locations as $location) {
                $where = $location->file->value() . ':' . $location->startLine;
                $copies[] = $where;
                $expectedValues[$where] = $location->codeLines;
            }
        }
        sort($copies);
        self::assertSame(
            ['src/F04.php:20', 'src/F04.php:86', 'src/F05.php:72', 'src/F05.php:98', 'src/F10.php:27', 'src/F10.php:60'],
            $copies,
        );

        $findings = $this->createRule()->analyze($this->contextWithBlocks(self::createStub(MetricRepositoryInterface::class), $blocks));
        $keys = [];
        $fingerprints = [];
        $reportedValues = [];
        foreach ($findings as $finding) {
            $keys[] = $finding->subject->toCanonical() . ':' . $finding->occurrenceKey?->value;
            $fingerprints[] = $finding->getFingerprint();
            $where = $finding->location->pathString() . ':' . $finding->location->line();
            $reportedValues[$where][] = $finding->metricValue;
        }
        foreach ($expectedValues as $where => $value) {
            self::assertContains($value, $reportedValues[$where] ?? [], $where);
        }
        self::assertCount(\count($keys), array_unique($keys), 'Each source copy needs its own subject and occurrence');
        self::assertCount(\count($fingerprints), array_unique($fingerprints));
    }

    #[Test]
    public function itExposesItsRuleNameAndDescription(): void
    {
        $rule = $this->createRule();

        self::assertSame('duplication.clone', $rule->getName());
        self::assertSame('Detects duplicated code blocks', $rule::getDescription());
    }

    #[Test]
    public function itDeclaresCodeDuplicationOptionsAsItsOptionsClass(): void
    {
        self::assertSame(CodeDuplicationOptions::class, CodeDuplicationRule::getOptionsClass());
        self::assertFalse(ThresholdOverrideSupportReader::read(CodeDuplicationRule::class));
        self::assertFalse(CodeDuplicationRule::channelDeclarations()[CodeDuplicationRule::NAME]->usesProducerWarningBoundary);
    }

    #[Test]
    public function itProducesNoFindingsWhenDisabled(): void
    {
        $rule = $this->createRule(new CodeDuplicationOptions(enabled: false));

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = $this->contextWithBlocks(
            $repository,
            [
                new DuplicateBlock(
                    [new DuplicateLocation(RelativePath::fromString('a.php'), 1, 10, 10, null), new DuplicateLocation(RelativePath::fromString('b.php'), 1, 10, 10, null)],
                    50,
                    self::CONTENT_HASH,
                ),
            ],
        );

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itProducesNoFindingsWhenNoDuplicateBlockWasCollected(): void
    {
        $rule = $this->createRule();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    /**
     * Each copy is a finding of its own, located on that copy and naming the
     * other one — so the file a copy lives in is where it is reported.
     */
    #[Test]
    public function itProducesAFindingOnEachCopyNamingTheOtherCopy(): void
    {
        $rule = $this->createRule();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = $this->contextWithBlocks(
            $repository,
            [
                new DuplicateBlock(
                    locations: [
                        new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 25, 16, null),
                        new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45, 16, null),
                    ],
                    tokens: 80,
                    contentHash: self::CONTENT_HASH,
                ),
            ],
        );

        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);

        foreach ($findings as $index => $v) {
            self::assertSame('duplication.clone', $v->ruleName);
            self::assertSame(Severity::Warning, $v->severity);
            self::assertSame(16, $v->metricValue);
            $file = RelativePath::fromString($index === 0 ? 'src/A.php' : 'src/B.php');
            $filePath = SymbolPath::forFile($file);
            self::assertSame(MetricSubject::aggregate($filePath)->toCanonical(), $v->subject->toCanonical());
            self::assertSame($filePath->toCanonical(), $v->symbolPath->toCanonical());
            self::assertNotNull($v->occurrenceKey);
            self::assertStringContainsString('16 code lines', $v->message);
            self::assertStringContainsString('2 occurrences', $v->message);
        }

        [$onA, $onB] = $findings;
        self::assertSame(['src/A.php', 10], [$onA->location->pathString(), $onA->location->line]);
        self::assertStringEndsWith('also at src/B.php:30-45', $onA->message);
        self::assertSame(['src/B.php:30'], self::related($onA));
        self::assertSame(['src/B.php', 30], [$onB->location->pathString(), $onB->location->line]);
        self::assertStringEndsWith('also at src/A.php:10-25', $onB->message);
        self::assertSame(['src/A.php:10'], self::related($onB));
        self::assertNotSame($onA->getFingerprint(), $onB->getFingerprint());
    }

    /**
     * Pins `occurrence` to the channel's frozen spelling read off a finding
     * produced by {@see CodeDuplicationRule::analyze()} itself, so a future
     * regression that swaps the occurrence call site's argument back to
     * `self::NAME` reddens this test once `NAME` and the frozen constant
     * diverge (they will, once the channel is renamed).
     */
    #[Test]
    public function itKeysOccurrenceToTheFrozenChannelSpellingNotToName(): void
    {
        $rule = $this->createRule();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = $this->contextWithBlocks(
            $repository,
            [
                new DuplicateBlock(
                    locations: [
                        new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 25, 16, null),
                        new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45, 16, null),
                    ],
                    tokens: 80,
                    contentHash: self::CONTENT_HASH,
                ),
            ],
        );

        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
        self::assertSame(
            [
                OccurrenceKey::semantic('duplication.code-duplication', ['contentHash' => self::CONTENT_HASH, 'copyInFile' => 0])->value,
                OccurrenceKey::semantic('duplication.code-duplication', ['contentHash' => self::CONTENT_HASH, 'copyInFile' => 0])->value,
            ],
            array_map(static fn($finding): ?string => $finding->occurrenceKey?->value, $findings),
        );
    }

    #[Test]
    public function itUsesTheFileSubjectAndAnOrdinalWithinThatFileForCopyIdentity(): void
    {
        $context = $this->contextWithBlocks(self::createStub(MetricRepositoryInterface::class), [
            self::block(['src/A.php' => [10, 60], 'src/B.php' => [30]]),
        ]);

        [$firstA, $secondA, $firstB] = $this->createRule()->analyze($context);

        self::assertSame('file:src/A.php', $firstA->subject->toCanonical());
        self::assertSame('file:src/A.php', $secondA->subject->toCanonical());
        self::assertSame('file:src/B.php', $firstB->subject->toCanonical());
        self::assertSame($firstA->occurrenceKey?->value, $firstB->occurrenceKey?->value);
        self::assertNotSame($firstA->occurrenceKey?->value, $secondA->occurrenceKey?->value);
        self::assertCount(3, array_unique([$firstA->getFingerprint(), $secondA->getFingerprint(), $firstB->getFingerprint()]));
    }

    #[Test]
    public function itIncludesTheHintSnippetInTheFindingMessage(): void
    {
        $rule = $this->createRule();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = $this->contextWithBlocks(
            $repository,
            [
                new DuplicateBlock(
                    locations: [
                        new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 25, 16, 'function processItems($items) { $result = [];'),
                        new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45, 16, 'function processItems($items) { $result = [];'),
                    ],
                    tokens: 80,
                    contentHash: self::CONTENT_HASH,
                ),
            ],
        );

        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
        self::assertStringContainsString(
            ': "function processItems($items) { $result = [];"',
            $findings[0]->message,
        );
        self::assertStringContainsString('src/B.php:30-45', $findings[0]->message);
    }

    #[Test]
    public function itOmitsTheHintSnippetFromTheMessageWhenNoHintIsGiven(): void
    {
        $rule = $this->createRule();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = $this->contextWithBlocks(
            $repository,
            [
                new DuplicateBlock(
                    locations: [
                        new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 25, 16, null),
                        new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45, 16, null),
                    ],
                    tokens: 80,
                    contentHash: self::CONTENT_HASH,
                ),
            ],
        );

        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
        // No hint means no quotes in the message
        self::assertStringNotContainsString('"', $findings[0]->message);
        self::assertStringContainsString('(16 code lines, 2 occurrences) — also at', $findings[0]->message);
    }

    #[Test]
    public function itClassifiesALargeDuplicateAsAnError(): void
    {
        $rule = $this->createRule();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = $this->contextWithBlocks(
            $repository,
            [
                new DuplicateBlock(
                    locations: [
                        new DuplicateLocation(RelativePath::fromString('a.php'), 1, 60, 60, null),
                        new DuplicateLocation(RelativePath::fromString('b.php'), 1, 60, 60, null),
                    ],
                    tokens: 300,
                    contentHash: self::CONTENT_HASH,
                ),
            ],
        );

        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
        self::assertSame([Severity::Error, Severity::Error], array_column($findings, 'severity'));
    }

    #[Test]
    public function itProducesOneFindingPerCopyOfEveryBlock(): void
    {
        $rule = $this->createRule();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = $this->contextWithBlocks(
            $repository,
            [
                new DuplicateBlock(
                    [new DuplicateLocation(RelativePath::fromString('a.php'), 1, 10, 10, null), new DuplicateLocation(RelativePath::fromString('b.php'), 1, 10, 10, null)],
                    50,
                    self::CONTENT_HASH,
                ),
                new DuplicateBlock(
                    [new DuplicateLocation(RelativePath::fromString('c.php'), 5, 20, 16, null), new DuplicateLocation(RelativePath::fromString('d.php'), 5, 20, 16, null)],
                    80,
                    'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                ),
            ],
        );

        $findings = $rule->analyze($context);

        self::assertSame(
            ['a.php', 'b.php', 'c.php', 'd.php'],
            array_map(static fn($finding): string => $finding->location->pathString(), $findings),
        );
    }

    #[Test]
    public function itListsEveryOtherOccurrenceInTheMessage(): void
    {
        $rule = $this->createRule();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = $this->contextWithBlocks(
            $repository,
            [
                new DuplicateBlock(
                    locations: [
                        new DuplicateLocation(RelativePath::fromString('a.php'), 1, 10, 10, null),
                        new DuplicateLocation(RelativePath::fromString('b.php'), 5, 14, 10, null),
                        new DuplicateLocation(RelativePath::fromString('c.php'), 20, 29, 10, null),
                    ],
                    tokens: 50,
                    contentHash: self::CONTENT_HASH,
                ),
            ],
        );

        $findings = $rule->analyze($context);

        self::assertCount(3, $findings);
        self::assertStringEndsWith('3 occurrences) — also at b.php:5-14, c.php:20-29', $findings[0]->message);
        self::assertStringEndsWith('3 occurrences) — also at a.php:1-10, c.php:20-29', $findings[1]->message);
        self::assertStringEndsWith('3 occurrences) — also at a.php:1-10, b.php:5-14', $findings[2]->message);
        self::assertSame(['a.php:1', 'c.php:20'], self::related($findings[1]));
    }

    #[Test]
    public function itNamesTheFirstTenOtherOccurrencesAndCountsTheRestInTheMessage(): void
    {
        $locations = [];
        for ($copy = 0; $copy < 100; $copy++) {
            $locations[] = new DuplicateLocation(RelativePath::fromString(\sprintf('c%03d.php', $copy)), 1, 10, 10, null);
        }

        $findings = $this->createRule()->analyze($this->contextWithBlocks(
            self::createStub(MetricRepositoryInterface::class),
            [new DuplicateBlock(locations: $locations, tokens: 50, contentHash: self::CONTENT_HASH)],
        ));

        self::assertCount(100, $findings);
        self::assertStringContainsString('100 occurrences', $findings[0]->message);
        self::assertStringEndsWith('— also at c001.php:1-10, c002.php:1-10, c003.php:1-10, c004.php:1-10, c005.php:1-10, c006.php:1-10, c007.php:1-10, c008.php:1-10, c009.php:1-10, c010.php:1-10 and 89 more', $findings[0]->message);
        self::assertStringEndsWith('— also at c000.php:1-10, c001.php:1-10, c002.php:1-10, c003.php:1-10, c004.php:1-10, c006.php:1-10, c007.php:1-10, c008.php:1-10, c009.php:1-10, c010.php:1-10 and 89 more', $findings[5]->message);
        self::assertStringEndsWith('— also at c000.php:1-10, c001.php:1-10, c002.php:1-10, c003.php:1-10, c004.php:1-10, c005.php:1-10, c006.php:1-10, c007.php:1-10, c008.php:1-10, c009.php:1-10 and 89 more', $findings[99]->message);
        self::assertSame(
            ['c000.php:1', 'c001.php:1', 'c002.php:1', 'c003.php:1', 'c004.php:1', 'c006.php:1', 'c007.php:1', 'c008.php:1', 'c009.php:1', 'c010.php:1'],
            self::related($findings[5]),
            'a copy names the same ten others as related locations as in its message',
        );
        self::assertSame($findings[0]->relatedLocations[4], $findings[99]->relatedLocations[5], 'the copies share their locations');
    }

    /**
     * Each copy is told apart from the others, so a consumer comparing
     * fingerprints — GitLab Code Quality, SARIF — sees one entry per copy and
     * a new copy as new; two copies inside one file are told apart as well.
     */
    #[Test]
    public function itGivesEveryCopyOfABlockAnIdentityOfItsOwn(): void
    {
        $fingerprints = $this->fingerprintsByCopy(self::block(['src/A.php' => [10, 60], 'src/B.php' => [30]]));

        self::assertCount(3, $fingerprints);
        self::assertCount(3, array_unique($fingerprints));
    }

    /**
     * Lines added or removed above a copy, or around the other copies, move
     * no identity: a copy is keyed by its file and its place among the
     * block's copies in that file, never by a line number.
     */
    #[Test]
    public function itKeepsACopysIdentityWhenTheLinesAroundItShift(): void
    {
        $before = $this->fingerprintsByCopy(self::block(['src/A.php' => [10, 60], 'src/B.php' => [30]]));
        $after = $this->fingerprintsByCopy(self::block(['src/A.php' => [17, 90], 'src/B.php' => [4]]));

        self::assertSame(array_values($before), array_values($after));
    }

    /**
     * A new copy is the only new identity — the copies already there keep
     * theirs, so a baseline accepting them reports only the new one — and a
     * copy moved to another file is a different copy.
     */
    #[Test]
    public function itGivesOnlyANewCopyANewIdentity(): void
    {
        $accepted = $this->fingerprintsByCopy(self::block(['src/A.php' => [10], 'src/B.php' => [30]]));
        // The new copy sorts before the others, so no identity may count the
        // copies across the whole block
        $grown = $this->fingerprintsByCopy(self::block(['src/0.php' => [5], 'src/A.php' => [10], 'src/B.php' => [30]]));
        $moved = $this->fingerprintsByCopy(self::block(['src/A.php' => [10], 'src/D.php' => [30]]));

        self::assertSame($accepted['src/A.php:10'], $grown['src/A.php:10']);
        self::assertSame($accepted['src/B.php:30'], $grown['src/B.php:30']);
        self::assertNotContains($grown['src/0.php:5'], $accepted);
        self::assertSame($accepted['src/A.php:10'], $moved['src/A.php:10']);
        self::assertNotContains($moved['src/D.php:30'], $accepted);
    }

    /**
     * `min_lines` admits a block by its longest copy, and every copy of an
     * admitted block is reported at its own covered code-line value.
     */
    #[Test]
    public function itReportsACopyShorterThanMinLinesAtItsOwnValue(): void
    {
        $block = new DuplicateBlock(
            locations: [
                new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 25, 16, null),
                new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 33, 4, null),
            ],
            tokens: 80,
            contentHash: self::CONTENT_HASH,
        );
        $context = $this->contextWithBlocks(self::createStub(MetricRepositoryInterface::class), [$block]);

        [$onA, $onB] = $this->createRule()->analyze($context);

        self::assertSame(['src/A.php', 16, Severity::Warning], [$onA->location->pathString(), $onA->metricValue, $onA->severity]);
        self::assertSame(['src/B.php', 4, Severity::Warning], [$onB->location->pathString(), $onB->metricValue, $onB->severity]);
        self::assertStringContainsString('(4 code lines, 2 occurrences)', $onB->message);
    }

    /** A copy below the severity boundary remains a finding with Warning severity. */
    #[Test]
    public function itReportsACopyBelowTheErrorBoundaryAsAWarning(): void
    {
        $block = new DuplicateBlock(
            locations: [
                new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 13, 4, null),
                new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 33, 4, null),
            ],
            tokens: 80,
            contentHash: self::CONTENT_HASH,
        );
        $context = $this->contextWithBlocks(self::createStub(MetricRepositoryInterface::class), [$block]);

        $findings = $this->createRule(new CodeDuplicationOptions(min_lines: 3, error: 10))->analyze($context);

        self::assertCount(2, $findings);
        self::assertSame([Severity::Warning, Severity::Warning], array_map(static fn($finding) => $finding->severity, $findings));
        self::assertSame([4, 4], array_map(static fn($finding) => $finding->metricValue, $findings));
    }

    #[Test]
    public function itKeepsTheBlocksContentInEachCopysIdentity(): void
    {
        $original = $this->fingerprintsByCopy(self::block(['src/A.php' => [10], 'src/B.php' => [30]]));
        $otherContent = $this->fingerprintsByCopy(
            self::block(['src/A.php' => [10], 'src/B.php' => [30]], str_repeat('b', 64)),
        );

        self::assertSame([], array_intersect($original, $otherContent));
    }

    #[Test]
    public function itReadsResolvedDuplicationOptions(): void
    {
        $options = CodeDuplicationOptions::fromResolved(ResolvedOptionsFixture::values(CodeDuplicationOptions::class, [
            'enabled' => false,
            'min_lines' => 10,
            'min_tokens' => 100,
            'error' => 40,
        ]));
        self::assertFalse($options->isEnabled());
        self::assertSame(10, $options->min_lines);
        self::assertSame(100, $options->min_tokens);
        self::assertSame(40, $options->error);

        // camelCase support
        $options = CodeDuplicationOptions::fromResolved(ResolvedOptionsFixture::values(CodeDuplicationOptions::class, [
            'minLines' => 15,
            'minTokens' => 120,
        ]));
        self::assertSame(15, $options->min_lines);
        self::assertSame(120, $options->min_tokens);
    }

    #[Test]
    public function itUsesPositiveResolvedOptionDefaults(): void
    {
        $options = CodeDuplicationOptions::fromResolved(ResolvedOptionsFixture::values(CodeDuplicationOptions::class, []));

        self::assertTrue($options->isEnabled());
        self::assertSame(5, $options->min_lines);
        self::assertSame(70, $options->min_tokens);
        self::assertSame(50, $options->error);
    }

    #[Test]
    public function itClassifiesDuplicateSeverityByCoveredCodeLinesUsingTheDefaultErrorBoundary(): void
    {
        $options = new CodeDuplicationOptions();

        self::assertSame(Severity::Warning, $options->getSeverity(0));
        self::assertSame(Severity::Warning, $options->getSeverity(4));
        self::assertSame(Severity::Warning, $options->getSeverity(49));
        self::assertSame(Severity::Error, $options->getSeverity(50));
        self::assertSame(Severity::Error, $options->getSeverity(100));
    }

    #[Test]
    public function itClassifiesDuplicateSeverityByCoveredCodeLinesUsingOnlyTheErrorBoundary(): void
    {
        $options = new CodeDuplicationOptions(error: 30);

        self::assertSame(Severity::Warning, $options->getSeverity(9));
        self::assertSame(Severity::Warning, $options->getSeverity(10));
        self::assertSame(Severity::Warning, $options->getSeverity(29));
        self::assertSame(Severity::Error, $options->getSeverity(30));
    }

    #[Test]
    public function itRefusesRetiredWarningAndThresholdConfigurationKeysAtTheirAuthoredAddresses(): void
    {
        $metadata = [new RuleMetadata(CodeDuplicationRule::NAME, CodeDuplicationOptions::class, '', [], false)];

        foreach (['warning', 'threshold'] as $key) {
            try {
                ResolvedOptionsFixture::authoredConfiguration(
                    ['rules' => [CodeDuplicationRule::NAME => [$key => 20]]],
                    $metadata,
                );
                self::fail('A retired duplication option must refuse the authored document.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertStringContainsString('rules.duplication.clone.' . $key, $refusal->getMessage());
            }
        }
    }

    #[Test]
    public function itRequiresPositiveAuthoredMinimumsAndErrorBoundary(): void
    {
        $metadata = [new RuleMetadata(CodeDuplicationRule::NAME, CodeDuplicationOptions::class, '', [], false)];

        foreach (['min_lines', 'min_tokens', 'error'] as $key) {
            try {
                ResolvedOptionsFixture::authoredConfiguration(
                    ['rules' => [CodeDuplicationRule::NAME => [$key => 0]]],
                    $metadata,
                );
                self::fail('A zero duplication minimum or error boundary must be refused.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertStringContainsString('rules.duplication.clone.' . $key, $refusal->getMessage());
                self::assertStringContainsString('at least 1', $refusal->getMessage());
            }
        }
    }

    #[Test]
    public function itReusesFileSubjectsWithinOneInvocationWithoutSharingCopyOccurrences(): void
    {
        $a = RelativePath::fromString('src/A.php');
        $b = RelativePath::fromString('src/B.php');
        $blocks = [
            new DuplicateBlock([
                new DuplicateLocation($a, 10, 25, 5, 'first'),
                new DuplicateLocation($a, 60, 80, 2, 'second'),
                new DuplicateLocation($b, 30, 40, 3, 'third'),
            ], 80, self::CONTENT_HASH),
            new DuplicateBlock([
                new DuplicateLocation($a, 90, 100, 7, 'fourth'),
                new DuplicateLocation($b, 100, 110, 4, 'fifth'),
            ], 81, str_repeat('b', 64)),
        ];
        $context = $this->contextWithBlocks(self::createStub(MetricRepositoryInterface::class), $blocks);
        $rule = $this->createRule(new CodeDuplicationOptions(error: 5));
        $findings = $rule->analyze($context);
        self::assertCount(5, $findings);
        self::assertSame($findings[0]->subject, $findings[1]->subject);
        self::assertSame($findings[0]->subject, $findings[3]->subject);
        self::assertSame($findings[2]->subject, $findings[4]->subject);
        self::assertNotSame($findings[0]->subject, $findings[2]->subject);
        self::assertSame($findings[0]->symbolPath, $findings[3]->symbolPath);
        self::assertSame($findings[0]->subject->toSymbolPath(), $findings[0]->symbolPath);
        self::assertSame(['file:src/A.php', 'file:src/A.php', 'file:src/B.php', 'file:src/A.php', 'file:src/B.php'], array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings));
        self::assertSame([10, 60, 30, 90, 100], array_map(static fn($finding): ?int => $finding->location->line, $findings));
        self::assertSame([5, 2, 3, 7, 4], array_column($findings, 'metricValue'));
        self::assertSame([Severity::Error, Severity::Warning, Severity::Warning, Severity::Error, Severity::Warning], array_column($findings, 'severity'));
        self::assertCount(5, array_unique(array_map(static fn($finding): string => $finding->getFingerprint(), $findings)));
        self::assertNotSame($findings[0]->occurrenceKey?->value, $findings[1]->occurrenceKey?->value);
        self::assertNotSame($findings[0]->occurrenceKey?->value, $findings[3]->occurrenceKey?->value);
        foreach (['first', 'second', 'third', 'fourth', 'fifth'] as $index => $hint) {
            self::assertStringContainsString(': "' . $hint . '"', $findings[$index]->message);
        }
        $again = $rule->analyze($context);
        self::assertNotSame($findings[0]->subject, $again[0]->subject);
        self::assertSame($again[0]->subject, $again[3]->subject);
        self::assertSame(array_map(static fn($finding): string => $finding->getFingerprint(), $findings), array_map(static fn($finding): string => $finding->getFingerprint(), $again));
    }

    private function createRule(?CodeDuplicationOptions $options = null): CodeDuplicationRule
    {
        return new CodeDuplicationRule($options ?? new CodeDuplicationOptions(), $this->resultProvider);
    }

    /**
     * @param list<DuplicateBlock> $blocks
     */
    private function contextWithBlocks(MetricRepositoryInterface $repository, array $blocks): AnalysisContext
    {
        $this->resultProvider->replace($blocks);

        return new AnalysisContext($repository);
    }

    /**
     * @return list<string>
     */
    private static function related(\Qualimetrix\Analysis\Finding\Contract\Finding $finding): array
    {
        return array_map(
            static fn($location): string => $location->pathString() . ':' . $location->line,
            $finding->relatedLocations,
        );
    }

    #[Test]
    public function itUsesEachCopysCoveredCodeLinesAndHintInItsFinding(): void
    {
        $block = new DuplicateBlock([
            new DuplicateLocation(RelativePath::fromString('a.php'), 1, 40, 5, 'first source'),
            new DuplicateLocation(RelativePath::fromString('b.php'), 2, 60, 2, 'second source'),
        ], 80, self::CONTENT_HASH);
        $context = $this->contextWithBlocks(self::createStub(MetricRepositoryInterface::class), [$block]);

        $findings = $this->createRule(new CodeDuplicationOptions(error: 5))->analyze($context);

        self::assertSame([5, 2], array_column($findings, 'metricValue'));
        self::assertSame([Severity::Error, Severity::Warning], array_column($findings, 'severity'));
        self::assertStringContainsString('(5 code lines, 2 occurrences): "first source"', $findings[0]->message);
        self::assertStringContainsString('(2 code lines, 2 occurrences): "second source"', $findings[1]->message);
    }

    /** @param array<string, list<int>> $copies */
    private static function block(array $copies, string $contentHash = self::CONTENT_HASH): DuplicateBlock
    {
        $locations = [];
        foreach ($copies as $file => $startLines) {
            foreach ($startLines as $startLine) {
                $locations[] = new DuplicateLocation(RelativePath::fromString($file), $startLine, $startLine + 15, ($startLine + 15) - ($startLine) + 1, null);
            }
        }

        return new DuplicateBlock(locations: $locations, tokens: 80, contentHash: $contentHash);
    }

    /**
     * @return array<string, string> `file:line` of each copy => its finding's fingerprint
     */
    private function fingerprintsByCopy(DuplicateBlock $block): array
    {
        $fingerprints = [];
        $context = $this->contextWithBlocks(self::createStub(MetricRepositoryInterface::class), [$block]);

        foreach ($this->createRule()->analyze($context) as $finding) {
            $fingerprints[$finding->location->pathString() . ':' . $finding->location->line] = $finding->getFingerprint();
        }

        return $fingerprints;
    }
}
