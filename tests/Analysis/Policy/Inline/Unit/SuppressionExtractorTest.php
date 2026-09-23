<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveRefusalReason;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionTarget;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Contract\SuppressionExtractor;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(SuppressionExtractor::class)]
final class SuppressionExtractorTest extends TestCase
{
    private SuppressionExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new SuppressionExtractor();
    }

    #[Test]
    public function itExtractsSuppressionTag(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore complexity
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertNull($suppressions[0]->reason);
        self::assertSame(10, $suppressions[0]->line);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
    }

    #[Test]
    public function itExtractsSuppressionWithReason(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore complexity Legacy code, refactoring planned
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertSame('Legacy code, refactoring planned', $suppressions[0]->reason);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
    }

    /**
     * The channel argument and the reason are both bare words, so the file
     * form — the only one whose channel is optional — cannot tell them apart
     * without a separator. `@qmx-ignore-file Generated code` used to be
     * silently inert and now reports `Generated` as a channel addressing
     * nothing, which is why the separator has to exist at all.
     */
    #[Test]
    public function itReadsAFileFormReasonIntroducedByTheSeparatorAsNoChannelFilter(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore-file -- Generated code, do not analyse
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame(SuppressionTarget::NO_RULE_FILTER, $suppressions[0]->rule);
        self::assertTrue($suppressions[0]->target()->appliesToEveryChannel());
        self::assertSame('Generated code, do not analyse', $suppressions[0]->reason);
    }

    /** The separator introduces the reason, so it is not part of it. */
    #[Test]
    public function itKeepsTheSeparatorOutOfTheReason(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore code-smell.goto -- Legacy code, refactoring planned
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('code-smell.goto', $suppressions[0]->rule);
        self::assertSame('Legacy code, refactoring planned', $suppressions[0]->reason);
    }

    /**
     * The explicit `ruleName#violationCode` form is documented for every place
     * that names a channel, this family included. The argument pattern used to
     * stop at the separator, so the second half was dropped without a word and
     * the directive was reported against the truncated first half.
     */
    #[Test]
    public function itKeepsBothHalvesOfAnExplicitChannelPair(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore complexity.ccn#complexity.cyclomatic.callable -- explicit
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.ccn#complexity.cyclomatic.callable', $suppressions[0]->rule);
        // The separator is still inside the grammar of a directive target, so
        // the retired spelling is extracted rather than skipped — which is what
        // lets it be refused by name instead of silently addressing nothing.
        self::assertTrue($suppressions[0]->target()->usesRetiredChannelPair());
        self::assertNull($suppressions[0]->target()->selector());
    }

    /**
     * The directive target is captured whole, level and all.
     *
     * Red on the unfixed pattern: without `:` in the target character class
     * the match stops at the separator, so `coupling.cbo:namespace` arrives as
     * `coupling.cbo` and silences **every** level of the channel — a
     * suppression quietly broader than the one that was written, which is the
     * one outcome worse than either a match or a refusal.
     */
    #[Test]
    public function itKeepsTheLevelHalfOfAChannelLevelPair(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore coupling.cbo:namespace -- levelled
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('coupling.cbo:namespace', $suppressions[0]->rule);
        self::assertSame('levelled', $suppressions[0]->reason);
        self::assertTrue($suppressions[0]->matches('coupling.cbo', SymbolLevel::Namespace_));
        self::assertFalse($suppressions[0]->matches('coupling.cbo', SymbolLevel::Class_));
    }

    #[Test]
    public function itExtractsMultipleSuppressions(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore complexity
             * @qmx-ignore coupling
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(2, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
        self::assertSame('coupling', $suppressions[1]->rule);
        self::assertSame(SuppressionType::Symbol, $suppressions[1]->type);
    }

    #[Test]
    public function itExtractsWildcardSuppression(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore * Ignore all rules
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('*', $suppressions[0]->rule);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
    }

    #[Test]
    public function itExtractsNextLineSuppression(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore-next-line complexity
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertSame(SuppressionType::NextLine, $suppressions[0]->type);
    }

    #[Test]
    public function itExtractsDottedRuleName(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore complexity.cyclomatic.callable Complex logic
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.cyclomatic.callable', $suppressions[0]->rule);
        self::assertSame('Complex logic', $suppressions[0]->reason);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
    }

    #[Test]
    public function itExtractsRuleNameWithDashes(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore code-smell.boolean-argument
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('code-smell.boolean-argument', $suppressions[0]->rule);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
    }

    #[Test]
    public function itReturnsEmptyWhenNoDocComment(): void
    {
        $node = new Class_('Foo');

        $suppressions = $this->extract($node);

        self::assertEmpty($suppressions);
    }

    #[Test]
    public function itReturnsEmptyWhenNoSuppressionTags(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * Regular docblock comment
             * @param string $foo
             * @return void
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertEmpty($suppressions);
    }

    #[Test]
    public function itExtractsFileLevelSuppression(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore-file
             */
            DOC,
            1,
            1,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extractFileLevelSuppressions($node);

        self::assertCount(1, $suppressions);
        self::assertSame('*', $suppressions[0]->rule);
        self::assertSame(SuppressionType::File, $suppressions[0]->type);
    }

    #[Test]
    public function itFileLevelSuppressionReturnsEmptyWhenNotPresent(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore complexity
             */
            DOC,
            1,
            1,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extractFileLevelSuppressions($node);

        self::assertEmpty($suppressions);
    }

    #[Test]
    public function itFileLevelSuppressionWithoutArgumentDefaultsToWildcard(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore-file
             */
            DOC,
            1,
            1,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('*', $suppressions[0]->rule);
        self::assertSame(SuppressionType::File, $suppressions[0]->type);
    }

    #[Test]
    public function itFileLevelSuppressionWithRule(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore-file complexity
             */
            DOC,
            1,
            1,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertSame(SuppressionType::File, $suppressions[0]->type);
    }

    #[Test]
    public function itNextLineSuppressionInMultiLineDocblockUsesEndLine(): void
    {
        // Multi-line docblock: starts at line 10, ends at line 14
        $docComment = new Doc(
            <<<'DOC'
            /**
             * Some description.
             *
             * @qmx-ignore-next-line complexity
             */
            DOC,
            startLine: 10,
            endLine: 14,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame(SuppressionType::NextLine, $suppressions[0]->type);
        // Suppression line should be endLine (14), not startLine (10)
        // so that SuppressionFilter targets endLine + 1 = line 15 (the actual next line after the docblock)
        self::assertSame(14, $suppressions[0]->line);
    }

    #[Test]
    public function itSymbolSuppressionHasEndLineFromNode(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore complexity
             */
            DOC,
            10,
            12,
        );

        $node = new Class_('Foo', [], ['startLine' => 13, 'endLine' => 50]);
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
        self::assertSame(50, $suppressions[0]->binding?->endLine);
    }

    #[Test]
    public function itNextLineSuppressionHasNoEndLine(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore-next-line complexity
             */
            DOC,
            10,
            12,
        );

        $node = new Class_('Foo', [], ['startLine' => 13, 'endLine' => 50]);
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame(SuppressionType::NextLine, $suppressions[0]->type);
        self::assertNull($suppressions[0]->binding?->endLine);
    }

    #[Test]
    public function itFileSuppressionHasNoEndLine(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore-file complexity
             */
            DOC,
            1,
            3,
        );

        $node = new Class_('Foo', [], ['startLine' => 4, 'endLine' => 50]);
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame(SuppressionType::File, $suppressions[0]->type);
        self::assertNull($suppressions[0]->binding?->endLine);
    }

    #[Test]
    public function itIgnoreFileSectionDoesNotMatchAsFileLevel(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore-file-section complexity
             */
            DOC,
            1,
            1,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extractFileLevelSuppressions($node);

        self::assertEmpty($suppressions);
    }

    #[Test]
    public function itIgnoreFileSectionDoesNotMatchAsFileLevelViaExtract(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore-file-section complexity
             */
            DOC,
            1,
            1,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        // Not read as file-level, symbol or next-line — and reported rather
        // than dropped, which is the only way an author hears about a tag
        // nobody reads.
        self::assertCount(1, $suppressions);
        self::assertSame(DirectiveRefusalReason::FormNotRecognised, $suppressions[0]->refusal?->reason);
        self::assertSame('@qmx-ignore-file-section', $suppressions[0]->refusal->tag);
        self::assertFalse($suppressions[0]->matches('complexity', null));
    }

    #[Test]
    public function itIgnoreNextLineExtraWordDoesNotMatch(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore-next-liner complexity
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame(DirectiveRefusalReason::FormNotRecognised, $suppressions[0]->refusal?->reason);
        self::assertFalse($suppressions[0]->matches('complexity', null));
    }

    #[Test]
    public function itReasonContainingAsteriskIsNotTruncated(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore complexity Legacy code * needs refactoring
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertSame('Legacy code * needs refactoring', $suppressions[0]->reason);
    }

    #[Test]
    public function itReasonTrailingDocblockClosingIsStripped(): void
    {
        // Single-line docblock where reason runs into closing */
        $docComment = new Doc(
            '/** @qmx-ignore complexity Some reason */',
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertSame('Some reason', $suppressions[0]->reason);
    }

    #[Test]
    public function itExtractMixedSuppressionTypes(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore complexity
             * @qmx-ignore-next-line coupling
             * @qmx-ignore-file size
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(3, $suppressions);

        self::assertSame(
            [SuppressionType::File, SuppressionType::NextLine, SuppressionType::Symbol],
            array_map(static fn($suppression): SuppressionType => $suppression->type, $suppressions),
        );
        self::assertSame(['size', 'coupling', 'complexity'], array_map(
            static fn($suppression): string => $suppression->rule,
            $suppressions,
        ));
    }

    // ---- Regular comment support tests ----

    #[Test]
    public function itExtractsSuppressionFromLineComment(): void
    {
        $comment = new Comment(
            '// @qmx-ignore complexity.ccn',
            startLine: 10,
            endLine: 10,
        );

        $node = new ClassMethod('doSomething', [], ['startLine' => 11, 'endLine' => 20]);
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.ccn', $suppressions[0]->rule);
        self::assertNull($suppressions[0]->reason);
        self::assertSame(10, $suppressions[0]->line);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
        self::assertSame(20, $suppressions[0]->binding?->endLine);
    }

    #[Test]
    public function itExtractsSuppressionFromBlockComment(): void
    {
        $comment = new Comment(
            '/* @qmx-ignore complexity.ccn */',
            startLine: 10,
            endLine: 10,
        );

        $node = new ClassMethod('doSomething', [], ['startLine' => 11, 'endLine' => 20]);
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.ccn', $suppressions[0]->rule);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
    }

    #[Test]
    public function itExtractsNextLineFromLineComment(): void
    {
        $comment = new Comment(
            '// @qmx-ignore-next-line complexity.ccn',
            startLine: 15,
            endLine: 15,
        );

        $node = new ClassMethod('doSomething', [], ['startLine' => 16, 'endLine' => 25]);
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.ccn', $suppressions[0]->rule);
        self::assertSame(SuppressionType::NextLine, $suppressions[0]->type);
        // Line should be endLine (15) so that filter targets line 16
        self::assertSame(15, $suppressions[0]->line);
    }

    #[Test]
    public function itExtractsFileLevelFromLineComment(): void
    {
        $comment = new Comment(
            '// @qmx-ignore-file',
            startLine: 3,
            endLine: 3,
        );

        $node = new Class_('Foo');
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extractor->extractFileLevelSuppressions($node);

        self::assertCount(1, $suppressions);
        self::assertSame('*', $suppressions[0]->rule);
        self::assertSame(SuppressionType::File, $suppressions[0]->type);
        self::assertSame(3, $suppressions[0]->line);
    }

    #[Test]
    public function itLineCommentWithReason(): void
    {
        $comment = new Comment(
            '// @qmx-ignore complexity.ccn Legacy algorithm, too costly to refactor',
            startLine: 10,
            endLine: 10,
        );

        $node = new ClassMethod('doSomething', [], ['startLine' => 11, 'endLine' => 20]);
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.ccn', $suppressions[0]->rule);
        self::assertSame('Legacy algorithm, too costly to refactor', $suppressions[0]->reason);
    }

    #[Test]
    public function itDocCommentStillWorksAfterRefactor(): void
    {
        $docComment = new Doc(
            '/** @qmx-ignore complexity */',
            startLine: 10,
            endLine: 10,
        );

        $node = new Class_('Foo', [], ['startLine' => 11, 'endLine' => 50]);
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
        self::assertSame(50, $suppressions[0]->binding?->endLine);
    }

    #[Test]
    public function itMixedDocblockAndLineComments(): void
    {
        $lineComment = new Comment(
            '// @qmx-ignore coupling.cbo',
            startLine: 9,
            endLine: 9,
        );

        $docComment = new Doc(
            '/** @qmx-ignore complexity */',
            startLine: 10,
            endLine: 10,
        );

        $node = new Class_('Foo', [], ['startLine' => 11, 'endLine' => 50]);
        // Set both: regular comment + docblock
        $node->setAttribute('comments', [$lineComment, $docComment]);

        $suppressions = $this->extract($node);

        self::assertCount(2, $suppressions);

        $rules = array_map(static fn($s) => $s->rule, $suppressions);
        sort($rules);

        self::assertSame(['complexity', 'coupling.cbo'], $rules);
    }

    #[Test]
    public function itReturnsEmptyWhenNoCommentsAndNoDocblock(): void
    {
        $node = new ClassMethod('doSomething');

        $suppressions = $this->extract($node);

        self::assertEmpty($suppressions);
    }

    #[Test]
    public function itFileLevelFromBlockComment(): void
    {
        $comment = new Comment(
            '/* @qmx-ignore-file complexity */',
            startLine: 2,
            endLine: 2,
        );

        $node = new Class_('Foo');
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extractor->extractFileLevelSuppressions($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertSame(SuppressionType::File, $suppressions[0]->type);
    }

    #[Test]
    public function itNextLineFromMultiLineBlockComment(): void
    {
        $comment = new Comment(
            <<<'COMMENT'
            /*
             * @qmx-ignore-next-line complexity.ccn
             */
            COMMENT,
            startLine: 10,
            endLine: 12,
        );

        $node = new ClassMethod('doSomething', [], ['startLine' => 13, 'endLine' => 25]);
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.ccn', $suppressions[0]->rule);
        self::assertSame(SuppressionType::NextLine, $suppressions[0]->type);
        // Line should be endLine (12) so that filter targets line 13
        self::assertSame(12, $suppressions[0]->line);
    }

    #[Test]
    public function itLineCommentWithoutRuleProducesNoSuppression(): void
    {
        $comment = new Comment(
            '// @qmx-ignore',
            startLine: 10,
            endLine: 10,
        );

        $node = new ClassMethod('doSomething', [], ['startLine' => 11, 'endLine' => 20]);
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extract($node);

        // A channel is the one thing this form cannot leave out, so the tag is
        // refused where it stands instead of silencing nothing in silence.
        self::assertCount(1, $suppressions);
        self::assertSame(DirectiveRefusalReason::FormNotRecognised, $suppressions[0]->refusal?->reason);
        self::assertSame(10, $suppressions[0]->line);
        self::assertFalse($suppressions[0]->matches('complexity', null));
    }

    #[Test]
    public function itBacktickEscapedIgnoreIsNotExtracted(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * Use `@qmx-ignore complexity` to suppress this rule.
             */
            DOC,
            10,
            12,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertEmpty($suppressions);
    }

    #[Test]
    public function itBacktickEscapedIgnoreFileIsNotExtracted(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * Supported tags:
             * - `@qmx-ignore-file [rule] [reason]`
             */
            DOC,
            10,
            13,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertEmpty($suppressions);
    }

    #[Test]
    public function itBacktickEscapedIgnoreFileNotExtractedAtFileLevel(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * Use `@qmx-ignore-file` to suppress all rules in a file.
             */
            DOC,
            1,
            3,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extractFileLevelSuppressions($node);

        self::assertEmpty($suppressions);
    }

    #[Test]
    public function itBacktickEscapedNextLineIsNotExtracted(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * Use `@qmx-ignore-next-line complexity` to suppress one line.
             */
            DOC,
            10,
            12,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertEmpty($suppressions);
    }

    #[Test]
    public function itMixedRealAndBacktickEscapedTags(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @qmx-ignore complexity Real suppression
             * See also `@qmx-ignore coupling` for coupling rules.
             */
            DOC,
            10,
            13,
        );

        $node = new Class_('Foo', [], ['startLine' => 14, 'endLine' => 30]);
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertSame('Real suppression', $suppressions[0]->reason);
    }

    #[Test]
    public function itRefusesADeclarationControlFromTheExplicitPhysicalOnlyPath(): void
    {
        $node = new Class_('Foo');
        $node->setDocComment(new Doc('/** @qmx-ignore complexity */', 1, 1));

        $suppressions = $this->extractor->extractPhysical($node);

        // It used to throw, and the exception was not contained: the file
        // failed to process, so one misplaced annotation cost every metric and
        // every finding in it.
        self::assertCount(1, $suppressions);
        self::assertSame(DirectiveRefusalReason::NoDeclarationToBind, $suppressions[0]->refusal?->reason);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertNull($suppressions[0]->binding);
        self::assertFalse($suppressions[0]->matches('complexity', null));
    }

    #[Test]
    public function itProjectsFileAndNextLineControlsFromThePhysicalPathInFixedOrder(): void
    {
        $node = new Class_('Foo');
        $node->setDocComment(new Doc(
            "/**\n * @qmx-ignore-next-line coupling\n * @qmx-ignore-file size\n */",
            startLine: 10,
            endLine: 13,
        ));

        $suppressions = $this->extractor->extractPhysical($node);

        self::assertSame([SuppressionType::File, SuppressionType::NextLine], array_map(
            static fn($suppression): SuppressionType => $suppression->type,
            $suppressions,
        ));
        self::assertSame([10, 13], array_map(static fn($suppression): int => $suppression->line, $suppressions));
    }

    #[Test]
    public function itSilentlyProjectsOnlyFileControlsFromTheFileOnlyPath(): void
    {
        $node = new Class_('Foo');
        $node->setDocComment(new Doc(
            "/**\n * @qmx-ignore complexity\n * @qmx-ignore-next-line coupling\n * @qmx-ignore-file size\n */",
            startLine: 10,
            endLine: 14,
        ));

        $suppressions = $this->extractor->extractFileLevelSuppressions($node);

        self::assertCount(1, $suppressions);
        self::assertSame(SuppressionType::File, $suppressions[0]->type);
        self::assertSame('size', $suppressions[0]->rule);
    }

    #[Test]
    public function itKeepsATagVisibleAfterAnUnpairedBacktick(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * `unclosed backtick before
             * @qmx-ignore complexity Intentional suppression
             */
            DOC,
            10,
            13,
        );

        $node = new Class_('Foo', [], ['startLine' => 14, 'endLine' => 30]);
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
    }

    #[Test]
    public function itBacktickEscapedIgnoreFileInRegularComment(): void
    {
        $comment = new Comment(
            '// Use `@qmx-ignore-file` to suppress all rules',
            startLine: 1,
            endLine: 1,
        );

        $node = new Class_('Foo');
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extractor->extractFileLevelSuppressions($node);

        self::assertEmpty($suppressions);
    }

    /**
     * The escape promised by AGENTS.md §8 has to hold wherever the prose above
     * it happens to put a backtick. It did not: pairing ran across the whole
     * comment, so one stray backtick made the example's own opening backtick
     * close the stray one and left the tag exposed.
     */
    #[Test]
    public function itKeepsAQuotedExampleQuotedUnderAnUnpairedBacktick(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * A literal ` is allowed in prose.
             * Use `@qmx-ignore complexity.ccn` to silence that rule.
             */
            DOC,
            10,
            14,
        );

        $node = new Class_('Foo', [], ['startLine' => 15, 'endLine' => 30]);
        $node->setDocComment($docComment);

        self::assertEmpty($this->extract($node));
    }

    /** The other direction of the same defect: the live directive was the one that vanished. */
    #[Test]
    public function itReadsARealDirectiveWrittenUnderAnUnpairedBacktick(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * The ` character is special.
             * @qmx-ignore complexity.ccn -- real, with a trailing `code` word
             */
            DOC,
            10,
            14,
        );

        $node = new Class_('Foo', [], ['startLine' => 15, 'endLine' => 30]);
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.ccn', $suppressions[0]->rule);
    }

    #[Test]
    public function itDoesNotReadATagInsideAFencedBlock(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * Example:
             * ```php
             * @qmx-ignore complexity.ccn -- inside a fence
             * ```
             */
            DOC,
            10,
            16,
        );

        $node = new Class_('Foo', [], ['startLine' => 17, 'endLine' => 30]);
        $node->setDocComment($docComment);

        self::assertEmpty($this->extract($node));
    }

    #[Test]
    public function itDoesNotReadATagInsideDoubleBacktickQuoting(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * Write `` `@qmx-ignore complexity.ccn` `` when quoting the tag.
             */
            DOC,
            10,
            13,
        );

        $node = new Class_('Foo', [], ['startLine' => 14, 'endLine' => 30]);
        $node->setDocComment($docComment);

        self::assertEmpty($this->extract($node));
    }

    /**
     * `getDocComment()` answers with the last docblock attached, so the first
     * of two was read by nothing: not by that call, and not by the loop over
     * the comments that are not docblocks.
     */
    #[Test]
    public function itReadsADirectiveInTheFirstOfTwoAdjacentDocblocks(): void
    {
        $node = new Class_('Foo', [], ['startLine' => 20, 'endLine' => 40]);
        $node->setAttribute('comments', [
            new Doc('/** @qmx-ignore complexity.ccn -- first of two */', 10, 10),
            new Doc('/** Ordinary description. */', 11, 11),
        ]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.ccn', $suppressions[0]->rule);
        self::assertSame(10, $suppressions[0]->line);
    }

    #[Test]
    public function itRefusesATagNoGrammarReadsAndNamesItAsWritten(): void
    {
        $node = new Class_('Foo', [], ['startLine' => 20, 'endLine' => 40]);
        $node->setDocComment(new Doc('/** @qmx-ignore-lines complexity.ccn -- a tag that does not exist */', 10, 10));

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame(DirectiveRefusalReason::FormNotRecognised, $suppressions[0]->refusal?->reason);
        self::assertSame('ignore-lines', $suppressions[0]->refusal->form);
        self::assertSame('@qmx-ignore-lines', $suppressions[0]->refusal->tag);
        self::assertFalse($suppressions[0]->matches('complexity.ccn', null));
    }

    /** The other family answers for its own spelling; refusing it here would report one mistake twice. */
    #[Test]
    public function itLeavesTheThresholdTagToItsOwnExtractor(): void
    {
        $node = new Class_('Foo', [], ['startLine' => 20, 'endLine' => 40]);
        $node->setDocComment(new Doc('/** @qmx-threshold complexity.ccn 15 */', 10, 10));

        self::assertEmpty($this->extract($node));
    }

    #[Test]
    public function itRefusesAMisspelledThresholdTag(): void
    {
        $node = new Class_('Foo', [], ['startLine' => 20, 'endLine' => 40]);
        $node->setDocComment(new Doc('/** @qmx-thresold complexity.ccn 15 */', 10, 10));

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('thresold', $suppressions[0]->refusal?->form);
    }

    #[Test]
    public function itReportsARefusedTagOnItsOwnLineRatherThanTheCommentsFirst(): void
    {
        $node = new Class_('Foo', [], ['startLine' => 20, 'endLine' => 40]);
        $node->setDocComment(new Doc(
            "/**\n * Description.\n *\n * @qmx-ignore-lines complexity.ccn\n */",
            startLine: 10,
            endLine: 14,
        ));

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame(13, $suppressions[0]->line);
    }

    /** A quoted mention of a tag nobody reads is documentation like any other. */
    #[Test]
    public function itDoesNotRefuseAQuotedUnknownTag(): void
    {
        $node = new Class_('Foo', [], ['startLine' => 20, 'endLine' => 40]);
        $node->setDocComment(new Doc('/** There is no `@qmx-ignore-lines` tag. */', 10, 10));

        self::assertEmpty($this->extract($node));
    }

    /**
     * A comment terminator is the comment's, not the author's.
     *
     * `*` is a legitimate channel argument — it is how the symbol and
     * next-line forms spell "no rule filter" — and the closing `*` of a
     * docblock stands exactly where that argument would. A grammar that reads
     * one as the other turns a directive that named nothing into the widest
     * suppression there is, and says nothing about it: the run reports no
     * refusal and no unused directive, because as far as it can tell the
     * author asked for silence and got it.
     */
    #[Test]
    #[DataProvider('provideChannellessDeclarationCarriers')]
    public function itRefusesAChannellessDeclarationDirectiveInEveryCommentCarrier(string $text, bool $isDoc): void
    {
        $node = new Class_('Foo', [], ['startLine' => 20, 'endLine' => 40]);
        $node->setAttribute('comments', [$isDoc ? new Doc($text, 10, 10) : new Comment($text, 10, 10)]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions, $text);
        self::assertSame(DirectiveRefusalReason::FormNotRecognised, $suppressions[0]->refusal?->reason, $text);
        self::assertSame('@qmx-ignore', $suppressions[0]->refusal->tag, $text);
        self::assertFalse($suppressions[0]->matches('complexity.ccn', null), $text);
        self::assertFalse($suppressions[0]->target()->appliesToEveryChannel(), $text);
    }

    /** @return iterable<string, array{non-empty-string, bool}> */
    public static function provideChannellessDeclarationCarriers(): iterable
    {
        yield 'line comment' => ['// @qmx-ignore', false];
        yield 'block comment' => ['/* @qmx-ignore */', false];
        yield 'docblock on one line' => ['/** @qmx-ignore */', true];
        yield 'docblock on its own line' => ["/**\n * @qmx-ignore\n */", true];
    }

    /**
     * The argument is written beside the tag, not under it: a docblock's
     * leading asterisk is punctuation, and the tag on the line below is a
     * different tag.
     */
    #[Test]
    public function itDoesNotReadAChannelFromTheLineBelowTheTag(): void
    {
        $node = new Class_('Foo', [], ['startLine' => 20, 'endLine' => 40]);
        $node->setDocComment(new Doc("/**\n * @qmx-ignore\n * @param int \$n\n */", 10, 13));

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame(DirectiveRefusalReason::FormNotRecognised, $suppressions[0]->refusal?->reason);
        self::assertSame(11, $suppressions[0]->line);
    }

    /** The next-line form requires a channel for the same reason, and loses it the same way. */
    #[Test]
    #[DataProvider('provideChannellessNextLineCarriers')]
    public function itRefusesAChannellessNextLineDirectiveInEveryCommentCarrier(string $text, bool $isDoc): void
    {
        $node = new ClassMethod('doSomething', [], ['startLine' => 11, 'endLine' => 20]);
        $node->setAttribute('comments', [$isDoc ? new Doc($text, 10, 10) : new Comment($text, 10, 10)]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions, $text);
        self::assertSame(DirectiveRefusalReason::FormNotRecognised, $suppressions[0]->refusal?->reason, $text);
        self::assertSame('@qmx-ignore-next-line', $suppressions[0]->refusal->tag, $text);
        self::assertFalse($suppressions[0]->matches('complexity.ccn', null), $text);
    }

    /** @return iterable<string, array{non-empty-string, bool}> */
    public static function provideChannellessNextLineCarriers(): iterable
    {
        yield 'line comment' => ['// @qmx-ignore-next-line', false];
        yield 'block comment' => ['/* @qmx-ignore-next-line */', false];
        yield 'docblock on one line' => ['/** @qmx-ignore-next-line */', true];
        yield 'docblock on its own line' => ["/**\n * @qmx-ignore-next-line\n */", true];
    }

    /** The authored `*` is the one spelling of "no rule filter", and it survives in every carrier. */
    #[Test]
    #[DataProvider('provideAuthoredEveryChannelCarriers')]
    public function itStillReadsAnAuthoredEveryChannelStar(string $text, bool $isDoc): void
    {
        $node = new Class_('Foo', [], ['startLine' => 20, 'endLine' => 40]);
        $node->setAttribute('comments', [$isDoc ? new Doc($text, 10, 10) : new Comment($text, 10, 10)]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions, $text);
        self::assertNull($suppressions[0]->refusal, $text);
        self::assertSame(SuppressionTarget::NO_RULE_FILTER, $suppressions[0]->rule, $text);
        self::assertTrue($suppressions[0]->target()->appliesToEveryChannel(), $text);
        self::assertTrue($suppressions[0]->matches('complexity.ccn', null), $text);
        self::assertSame('everything here', $suppressions[0]->reason, $text);
    }

    /** @return iterable<string, array{non-empty-string, bool}> */
    public static function provideAuthoredEveryChannelCarriers(): iterable
    {
        yield 'line comment' => ['// @qmx-ignore * -- everything here', false];
        yield 'block comment' => ['/* @qmx-ignore * -- everything here */', false];
        yield 'docblock on one line' => ['/** @qmx-ignore * -- everything here */', true];
        yield 'docblock on its own line' => ["/**\n * @qmx-ignore * -- everything here\n */", true];
    }

    /**
     * A descendant selector written hard against the terminator is still the
     * selector the author wrote; only an argument that *is* the terminator is
     * not one.
     */
    #[Test]
    public function itStillReadsADescendantSelectorAbuttingTheCommentTerminator(): void
    {
        $node = new Class_('Foo', [], ['startLine' => 20, 'endLine' => 40]);
        $node->setAttribute('comments', [new Comment('/* @qmx-ignore complexity.*/', 10, 10)]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertNull($suppressions[0]->refusal);
        self::assertSame('complexity.*', $suppressions[0]->rule);
        self::assertTrue($suppressions[0]->matches('complexity.ccn', null));
    }

    /** The file form spells "no rule filter" by omission, and the terminator is not an argument. */
    #[Test]
    #[DataProvider('provideBareFileCarriers')]
    public function itStillReadsABareFileDirectiveAsNoRuleFilter(string $text, bool $isDoc): void
    {
        $node = new Class_('Foo', [], ['startLine' => 20, 'endLine' => 40]);
        $node->setAttribute('comments', [$isDoc ? new Doc($text, 10, 10) : new Comment($text, 10, 10)]);

        $suppressions = $this->extractFileLevel($node);

        self::assertCount(1, $suppressions, $text);
        self::assertNull($suppressions[0]->refusal, $text);
        self::assertSame(SuppressionType::File, $suppressions[0]->type, $text);
        self::assertSame(SuppressionTarget::NO_RULE_FILTER, $suppressions[0]->rule, $text);
        self::assertTrue($suppressions[0]->target()->appliesToEveryChannel(), $text);
    }

    /** @return iterable<string, array{non-empty-string, bool}> */
    public static function provideBareFileCarriers(): iterable
    {
        yield 'line comment' => ['// @qmx-ignore-file', false];
        yield 'block comment' => ['/* @qmx-ignore-file */', false];
        yield 'docblock on one line' => ['/** @qmx-ignore-file */', true];
        yield 'docblock on its own line' => ["/**\n * @qmx-ignore-file\n */", true];
    }

    /** A refusal quotes what stands in the source, and the terminator does not stand in it. */
    #[Test]
    public function itNamesARefusedTagWithoutTheCommentTerminator(): void
    {
        $node = new Class_('Foo', [], ['startLine' => 20, 'endLine' => 40]);
        $node->setDocComment(new Doc('/** @qmx-bogus */', 10, 10));

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('', $suppressions[0]->rule);
        self::assertSame('@qmx-bogus', $suppressions[0]->refusal?->tag);
        self::assertStringContainsString('"@qmx-bogus"', $suppressions[0]->refusal->describe($suppressions[0]->rule));
    }

    /** @return list<\Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression> */
    private function extractFileLevel(\PhpParser\Node $node): array
    {
        return $this->extractor->extractFileLevelSuppressions($node);
    }

    /** @return list<\Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression> */
    private function extract(\PhpParser\Node $node): array
    {
        return $this->extractor->extract(
            $node,
            MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Foo.php'))),
            ControlScope::Callable,
        );
    }
}
