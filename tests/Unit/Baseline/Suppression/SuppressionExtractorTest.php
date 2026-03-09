<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Baseline\Suppression;

use AiMessDetector\Baseline\Suppression\SuppressionExtractor;
use AiMessDetector\Baseline\Suppression\SuppressionType;
use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SuppressionExtractor::class)]
final class SuppressionExtractorTest extends TestCase
{
    private SuppressionExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new SuppressionExtractor();
    }

    public function testExtractsSuppressionTag(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore complexity
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertNull($suppressions[0]->reason);
        self::assertSame(10, $suppressions[0]->line);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
    }

    public function testExtractsSuppressionWithReason(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore complexity Legacy code, refactoring planned
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertSame('Legacy code, refactoring planned', $suppressions[0]->reason);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
    }

    public function testExtractsMultipleSuppressions(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore complexity
             * @aimd-ignore coupling
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(2, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
        self::assertSame('coupling', $suppressions[1]->rule);
        self::assertSame(SuppressionType::Symbol, $suppressions[1]->type);
    }

    public function testExtractsWildcardSuppression(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore * Ignore all rules
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('*', $suppressions[0]->rule);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
    }

    public function testExtractsNextLineSuppression(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore-next-line complexity
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertSame(SuppressionType::NextLine, $suppressions[0]->type);
    }

    public function testExtractsDottedRuleName(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore complexity.cyclomatic.method Complex logic
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity.cyclomatic.method', $suppressions[0]->rule);
        self::assertSame('Complex logic', $suppressions[0]->reason);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
    }

    public function testExtractsRuleNameWithDashes(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore code-smell.boolean-argument
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('code-smell.boolean-argument', $suppressions[0]->rule);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
    }

    public function testReturnsEmptyWhenNoDocComment(): void
    {
        $node = new Class_('Foo');

        $suppressions = $this->extractor->extract($node);

        self::assertEmpty($suppressions);
    }

    public function testReturnsEmptyWhenNoSuppressionTags(): void
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

        $suppressions = $this->extractor->extract($node);

        self::assertEmpty($suppressions);
    }

    public function testExtractsFileLevelSuppression(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore-file
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

    public function testFileLevelSuppressionReturnsEmptyWhenNotPresent(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore complexity
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

    public function testFileLevelSuppressionWithoutArgumentDefaultsToWildcard(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore-file
             */
            DOC,
            1,
            1,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('*', $suppressions[0]->rule);
        self::assertSame(SuppressionType::File, $suppressions[0]->type);
    }

    public function testFileLevelSuppressionWithRule(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore-file complexity
             */
            DOC,
            1,
            1,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame('complexity', $suppressions[0]->rule);
        self::assertSame(SuppressionType::File, $suppressions[0]->type);
    }

    public function testNextLineSuppressionInMultiLineDocblockUsesEndLine(): void
    {
        // Multi-line docblock: starts at line 10, ends at line 14
        $docComment = new Doc(
            <<<'DOC'
            /**
             * Some description.
             *
             * @aimd-ignore-next-line complexity
             */
            DOC,
            startLine: 10,
            endLine: 14,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame(SuppressionType::NextLine, $suppressions[0]->type);
        // Suppression line should be endLine (14), not startLine (10)
        // so that SuppressionFilter targets endLine + 1 = line 15 (the actual next line after the docblock)
        self::assertSame(14, $suppressions[0]->line);
    }

    public function testSymbolSuppressionHasEndLineFromNode(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore complexity
             */
            DOC,
            10,
            12,
        );

        $node = new Class_('Foo', [], ['startLine' => 13, 'endLine' => 50]);
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame(SuppressionType::Symbol, $suppressions[0]->type);
        self::assertSame(50, $suppressions[0]->endLine);
    }

    public function testNextLineSuppressionHasNoEndLine(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore-next-line complexity
             */
            DOC,
            10,
            12,
        );

        $node = new Class_('Foo', [], ['startLine' => 13, 'endLine' => 50]);
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame(SuppressionType::NextLine, $suppressions[0]->type);
        self::assertNull($suppressions[0]->endLine);
    }

    public function testFileSuppressionHasNoEndLine(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore-file complexity
             */
            DOC,
            1,
            3,
        );

        $node = new Class_('Foo', [], ['startLine' => 4, 'endLine' => 50]);
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(1, $suppressions);
        self::assertSame(SuppressionType::File, $suppressions[0]->type);
        self::assertNull($suppressions[0]->endLine);
    }

    public function testExtractMixedSuppressionTypes(): void
    {
        $docComment = new Doc(
            <<<'DOC'
            /**
             * @aimd-ignore complexity
             * @aimd-ignore-next-line coupling
             * @aimd-ignore-file size
             */
            DOC,
            10,
            10,
        );

        $node = new Class_('Foo');
        $node->setDocComment($docComment);

        $suppressions = $this->extractor->extract($node);

        self::assertCount(3, $suppressions);

        // Collect by type for easier assertion (order may vary due to separate regex passes)
        $byType = [];
        foreach ($suppressions as $s) {
            $byType[$s->type->value] = $s;
        }

        self::assertSame('size', $byType['file']->rule);
        self::assertSame('coupling', $byType['next-line']->rule);
        self::assertSame('complexity', $byType['symbol']->rule);
    }
}
