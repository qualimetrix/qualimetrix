<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationRule;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicateBlock;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicateLocation;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationResultProvider;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(CodeDuplicationRule::class)]
#[CoversClass(CodeDuplicationOptions::class)]
#[CoversClass(DuplicateBlock::class)]
#[CoversClass(DuplicateLocation::class)]
final class CodeDuplicationRuleTest extends TestCase
{
    private const string CONTENT_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private DuplicationResultProvider $resultProvider;

    protected function setUp(): void
    {
        $this->resultProvider = new DuplicationResultProvider();
    }

    #[Test]
    public function itExposesItsRuleNameAndDescription(): void
    {
        $rule = $this->createRule();

        self::assertSame('duplication.clone', $rule->getName());
        self::assertSame('Detects duplicated code blocks', $rule->getDescription());
    }

    #[Test]
    public function itDeclaresCodeDuplicationOptionsAsItsOptionsClass(): void
    {
        self::assertSame(CodeDuplicationOptions::class, CodeDuplicationRule::getOptionsClass());
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
                    [new DuplicateLocation(RelativePath::fromString('a.php'), 1, 10), new DuplicateLocation(RelativePath::fromString('b.php'), 1, 10)],
                    10,
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
                        new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 25),
                        new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45),
                    ],
                    lines: 16,
                    tokens: 80,
                    contentHash: self::CONTENT_HASH,
                ),
            ],
        );

        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);

        foreach ($findings as $v) {
            self::assertSame('duplication.clone', $v->ruleName);
            self::assertSame(Severity::Warning, $v->severity);
            self::assertSame(16, $v->metricValue);
            self::assertSame(MetricSubject::aggregate(SymbolPath::forProject())->toCanonical(), $v->subject->toCanonical());
            self::assertSame(SymbolPath::forProject()->toCanonical(), $v->symbolPath->toCanonical());
            self::assertNotNull($v->occurrenceKey);
            self::assertStringContainsString('16 lines', $v->message);
            self::assertStringContainsString('2 occurrences', $v->message);
        }

        [$onA, $onB] = $findings;
        self::assertSame(['src/A.php', 10], [$onA->location->pathString(), $onA->location->line]);
        self::assertStringEndsWith('also at src/B.php:30-45', $onA->message);
        self::assertSame(['src/B.php:30'], self::related($onA));
        self::assertSame(['src/B.php', 30], [$onB->location->pathString(), $onB->location->line]);
        self::assertStringEndsWith('also at src/A.php:10-25', $onB->message);
        self::assertSame(['src/A.php:10'], self::related($onB));
        self::assertSame($onA->getFingerprint(), $onB->getFingerprint());
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
                        new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 25),
                        new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45),
                    ],
                    lines: 16,
                    tokens: 80,
                    contentHash: self::CONTENT_HASH,
                ),
            ],
        );

        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
        foreach ($findings as $finding) {
            self::assertSame(
                OccurrenceKey::semantic('duplication.code-duplication', ['contentHash' => self::CONTENT_HASH])->value,
                $finding->occurrenceKey?->value,
            );
        }
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
                        new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 25),
                        new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45),
                    ],
                    lines: 16,
                    tokens: 80,
                    contentHash: self::CONTENT_HASH,
                    hint: 'function processItems($items) { $result = [];',
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
                        new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 25),
                        new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45),
                    ],
                    lines: 16,
                    tokens: 80,
                    contentHash: self::CONTENT_HASH,
                    hint: null,
                ),
            ],
        );

        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
        // No hint means no quotes in the message
        self::assertStringNotContainsString('"', $findings[0]->message);
        self::assertStringContainsString('(16 lines, 2 occurrences) — also at', $findings[0]->message);
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
                        new DuplicateLocation(RelativePath::fromString('a.php'), 1, 60),
                        new DuplicateLocation(RelativePath::fromString('b.php'), 1, 60),
                    ],
                    lines: 60,
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
                    [new DuplicateLocation(RelativePath::fromString('a.php'), 1, 10), new DuplicateLocation(RelativePath::fromString('b.php'), 1, 10)],
                    10,
                    50,
                    self::CONTENT_HASH,
                ),
                new DuplicateBlock(
                    [new DuplicateLocation(RelativePath::fromString('c.php'), 5, 20), new DuplicateLocation(RelativePath::fromString('d.php'), 5, 20)],
                    16,
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
                        new DuplicateLocation(RelativePath::fromString('a.php'), 1, 10),
                        new DuplicateLocation(RelativePath::fromString('b.php'), 5, 14),
                        new DuplicateLocation(RelativePath::fromString('c.php'), 20, 29),
                    ],
                    lines: 10,
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
            $locations[] = new DuplicateLocation(RelativePath::fromString(\sprintf('c%03d.php', $copy)), 1, 10);
        }

        $findings = $this->createRule()->analyze($this->contextWithBlocks(
            self::createStub(MetricRepositoryInterface::class),
            [new DuplicateBlock(locations: $locations, lines: 10, tokens: 50, contentHash: self::CONTENT_HASH)],
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

    #[Test]
    public function itUsesOnlyProjectAndContentForDuplicateGroupIdentity(): void
    {
        $rule = $this->createRule();
        $repository = self::createStub(MetricRepositoryInterface::class);

        $sameContentWithLaterPrimary = new DuplicateBlock(
            locations: [
                new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45),
                new DuplicateLocation(RelativePath::fromString('src/C.php'), 60, 75),
            ],
            lines: 16,
            tokens: 80,
            contentHash: self::CONTENT_HASH,
        );
        $sameContentWithEarlierSibling = new DuplicateBlock(
            locations: [
                new DuplicateLocation(RelativePath::fromString('src/A.php'), 1, 16),
                new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45),
                new DuplicateLocation(RelativePath::fromString('src/C.php'), 60, 75),
            ],
            lines: 16,
            tokens: 80,
            contentHash: self::CONTENT_HASH,
        );
        $differentContent = new DuplicateBlock(
            locations: [
                new DuplicateLocation(RelativePath::fromString('src/A.php'), 1, 16),
                new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45),
            ],
            lines: 16,
            tokens: 80,
            contentHash: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        );

        $fingerprints = array_map(
            fn(DuplicateBlock $block): string => $this->analyzeBlock($rule, $repository, $block),
            [$sameContentWithLaterPrimary, $sameContentWithEarlierSibling, $differentContent],
        );

        self::assertSame($fingerprints[0], $fingerprints[1]);
        self::assertNotSame($fingerprints[0], $fingerprints[2]);
    }

    #[Test]
    public function itParsesSnakeCaseAndCamelCaseOptionKeysFromAnArray(): void
    {
        $options = CodeDuplicationOptions::fromArray([
            'enabled' => false,
            'min_lines' => 10,
            'min_tokens' => 100,
            'warning' => 8,
            'error' => 40,
        ]);
        self::assertFalse($options->isEnabled());
        self::assertSame(10, $options->min_lines);
        self::assertSame(100, $options->min_tokens);
        self::assertSame(8, $options->warning);
        self::assertSame(40, $options->error);

        // camelCase support
        $options = CodeDuplicationOptions::fromArray([
            'minLines' => 15,
            'minTokens' => 120,
        ]);
        self::assertSame(15, $options->min_lines);
        self::assertSame(120, $options->min_tokens);
    }

    #[Test]
    public function itClassifiesDuplicateSeverityByLineCountUsingDefaultThresholds(): void
    {
        $options = new CodeDuplicationOptions();

        self::assertNull($options->getSeverity(0));
        self::assertNull($options->getSeverity(4));
        self::assertSame(Severity::Warning, $options->getSeverity(5));
        self::assertSame(Severity::Warning, $options->getSeverity(49));
        self::assertSame(Severity::Error, $options->getSeverity(50));
        self::assertSame(Severity::Error, $options->getSeverity(100));
    }

    #[Test]
    public function itClassifiesDuplicateSeverityByLineCountUsingCustomThresholds(): void
    {
        $options = new CodeDuplicationOptions(warning: 10, error: 30);

        self::assertNull($options->getSeverity(9));
        self::assertSame(Severity::Warning, $options->getSeverity(10));
        self::assertSame(Severity::Warning, $options->getSeverity(29));
        self::assertSame(Severity::Error, $options->getSeverity(30));
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

    private function analyzeBlock(
        CodeDuplicationRule $rule,
        MetricRepositoryInterface $repository,
        DuplicateBlock $block,
    ): string {
        $this->resultProvider->replace([$block]);

        return $rule->analyze(new AnalysisContext($repository))[0]->getFingerprint();
    }
}
