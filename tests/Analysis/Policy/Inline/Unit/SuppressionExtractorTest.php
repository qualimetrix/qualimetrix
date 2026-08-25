<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use LogicException;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionTarget;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Contract\SuppressionExtractor;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
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
             * @qmx-ignore complexity.cyclomatic#complexity.cyclomatic.callable -- explicit
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.cyclomatic#complexity.cyclomatic.callable', $suppressions[0]->rule);
        // The separator is still inside the grammar of a directive target, so
        // the retired spelling is extracted rather than skipped — which is what
        // lets it be refused by name instead of silently addressing nothing.
        self::assertTrue($suppressions[0]->target()->usesRetiredChannelPair());
        self::assertNull($suppressions[0]->target()->selector());
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
        self::assertSame(50, $suppressions[0]->endLine);
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
        self::assertNull($suppressions[0]->endLine);
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
        self::assertNull($suppressions[0]->endLine);
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

        // Should not match as file-level, nor as symbol or next-line
        self::assertEmpty($suppressions);
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

        self::assertEmpty($suppressions);
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
            '// @qmx-ignore complexity.cyclomatic',
            startLine: 10,
            endLine: 10,
        );

        $node = new ClassMethod('doSomething', [], ['startLine' => 11, 'endLine' => 20]);
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.cyclomatic', $suppressions[0]->rule);
        self::assertNull($suppressions[0]->reason);
        self::assertSame(10, $suppressions[0]->line);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
        self::assertSame(20, $suppressions[0]->endLine);
    }

    #[Test]
    public function itExtractsSuppressionFromBlockComment(): void
    {
        $comment = new Comment(
            '/* @qmx-ignore complexity.cyclomatic */',
            startLine: 10,
            endLine: 10,
        );

        $node = new ClassMethod('doSomething', [], ['startLine' => 11, 'endLine' => 20]);
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.cyclomatic', $suppressions[0]->rule);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
    }

    #[Test]
    public function itExtractsNextLineFromLineComment(): void
    {
        $comment = new Comment(
            '// @qmx-ignore-next-line complexity.cyclomatic',
            startLine: 15,
            endLine: 15,
        );

        $node = new ClassMethod('doSomething', [], ['startLine' => 16, 'endLine' => 25]);
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.cyclomatic', $suppressions[0]->rule);
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
            '// @qmx-ignore complexity.cyclomatic Legacy algorithm, too costly to refactor',
            startLine: 10,
            endLine: 10,
        );

        $node = new ClassMethod('doSomething', [], ['startLine' => 11, 'endLine' => 20]);
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.cyclomatic', $suppressions[0]->rule);
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
        self::assertSame(50, $suppressions[0]->endLine);
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
             * @qmx-ignore-next-line complexity.cyclomatic
             */
            COMMENT,
            startLine: 10,
            endLine: 12,
        );

        $node = new ClassMethod('doSomething', [], ['startLine' => 13, 'endLine' => 25]);
        $node->setAttribute('comments', [$comment]);

        $suppressions = $this->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.cyclomatic', $suppressions[0]->rule);
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

        self::assertEmpty($suppressions);
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
    public function itRejectsADeclarationControlFromTheExplicitPhysicalOnlyPath(): void
    {
        $node = new Class_('Foo');
        $node->setDocComment(new Doc('/** @qmx-ignore complexity */', 1, 1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires an explicit declaration binding');

        $this->extractor->extractPhysical($node);
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
