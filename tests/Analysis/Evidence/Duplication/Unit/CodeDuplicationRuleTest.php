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
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Severity;

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
    public function nameAndDescriptionAreCorrect(): void
    {
        $rule = $this->createRule();

        self::assertSame('duplication.code-duplication', $rule->getName());
        self::assertSame('Detects duplicated code blocks', $rule->getDescription());
        self::assertSame(RuleCategory::Duplication, $rule->getCategory());
    }

    #[Test]
    public function optionsClassIsCorrect(): void
    {
        self::assertSame(CodeDuplicationOptions::class, CodeDuplicationRule::getOptionsClass());
    }

    #[Test]
    public function disabledRuleReturnsNoViolations(): void
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
    public function noDuplicatesProducesNoViolations(): void
    {
        $rule = $this->createRule();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function duplicateBlockProducesViolation(): void
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

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);

        $v = $violations[0];
        self::assertSame('duplication.code-duplication', $v->ruleName);
        self::assertSame('src/A.php', $v->location->pathString());
        self::assertSame(10, $v->location->line);
        self::assertSame(Severity::Warning, $v->severity);
        self::assertSame(16, $v->metricValue);
        self::assertSame(MetricSubject::aggregate(SymbolPath::forProject())->toCanonical(), $v->subject->toCanonical());
        self::assertSame(SymbolPath::forProject()->toCanonical(), $v->symbolPath->toCanonical());
        self::assertNotNull($v->occurrenceKey);
        self::assertStringContainsString('16 lines', $v->message);
        self::assertStringContainsString('2 occurrences', $v->message);
        self::assertStringContainsString('src/B.php:30-45', $v->message);
    }

    #[Test]
    public function duplicateBlockWithHintIncludesHintInMessage(): void
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

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString(
            ': "function processItems($items) { $result = [];"',
            $violations[0]->message,
        );
        self::assertStringContainsString('src/B.php:30-45', $violations[0]->message);
    }

    #[Test]
    public function duplicateBlockWithoutHintOmitsHintFromMessage(): void
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

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        // No hint means no quotes in the message
        self::assertStringNotContainsString('"', $violations[0]->message);
        self::assertStringContainsString('(16 lines, 2 occurrences) — also at', $violations[0]->message);
    }

    #[Test]
    public function largeDuplicateIsError(): void
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

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    #[Test]
    public function multipleBlocksProduceMultipleViolations(): void
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

        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
    }

    #[Test]
    public function multipleLocationsInMessage(): void
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

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('3 occurrences', $violations[0]->message);
        self::assertStringContainsString('b.php:5-14', $violations[0]->message);
        self::assertStringContainsString('c.php:20-29', $violations[0]->message);
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
    public function optionsFromArray(): void
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
    public function optionsSeverityWithDefaults(): void
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
    public function optionsSeverityWithCustomThresholds(): void
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

    private function analyzeBlock(
        CodeDuplicationRule $rule,
        MetricRepositoryInterface $repository,
        DuplicateBlock $block,
    ): string {
        $this->resultProvider->replace([$block]);

        return $rule->analyze(new AnalysisContext($repository))[0]->getFingerprint();
    }
}
