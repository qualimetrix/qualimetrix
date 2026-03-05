<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Baseline\Suppression;

use AiMessDetector\Baseline\Suppression\SuppressionExtractor;
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
        self::assertSame('coupling', $suppressions[1]->rule);
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
}
