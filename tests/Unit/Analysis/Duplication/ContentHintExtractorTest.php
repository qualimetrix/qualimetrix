<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Duplication;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Duplication\ContentHintExtractor;

#[CoversClass(ContentHintExtractor::class)]
final class ContentHintExtractorTest extends TestCase
{
    private ContentHintExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ContentHintExtractor();
    }

    #[Test]
    public function extractsFirstMeaningfulLines(): void
    {
        $source = <<<'PHP'
        <?php
        function processItems($items) {
            $result = [];
            foreach ($items as $item) {
                $result[] = $item->transform();
            }
            return $result;
        }
        PHP;

        $hint = $this->extractor->extract($source, 2, 8);

        self::assertNotNull($hint);
        self::assertStringContainsString('function processItems', $hint);
    }

    #[Test]
    public function skipsBlankLines(): void
    {
        $source = "\n\n\nfunction foo() {\n    return 1;\n}\n";

        $hint = $this->extractor->extract($source, 1, 6);

        self::assertNotNull($hint);
        self::assertStringContainsString('function foo()', $hint);
    }

    #[Test]
    public function skipsBraceOnlyLines(): void
    {
        $source = "{\n{\n    \$x = 1;\n    \$y = 2;\n}\n}\n";

        $hint = $this->extractor->extract($source, 1, 6);

        self::assertNotNull($hint);
        self::assertStringContainsString('$x = 1', $hint);
    }

    #[Test]
    public function truncatesLongHintWithEllipsis(): void
    {
        $source = "<?php\nfunction veryLongFunctionNameThatExceedsTheMaximumAllowedHintLength(\$parameterOne, \$parameterTwo, \$parameterThree, \$parameterFour) {\n    return true;\n}\n";

        $hint = $this->extractor->extract($source, 2, 4);

        self::assertNotNull($hint);
        self::assertLessThanOrEqual(83, \strlen($hint)); // 80 + "..." = max 83 in practice
        self::assertStringEndsWith('...', $hint);
    }

    #[Test]
    public function shortHintNotTruncated(): void
    {
        $source = "<?php\n\$x = 1;\n\$y = 2;\n";

        $hint = $this->extractor->extract($source, 2, 3);

        self::assertNotNull($hint);
        self::assertStringNotContainsString('...', $hint);
        self::assertSame('$x = 1; $y = 2;', $hint);
    }

    #[Test]
    public function returnsNullForEmptyBlock(): void
    {
        $source = "\n\n\n\n\n";

        $hint = $this->extractor->extract($source, 1, 5);

        self::assertNull($hint);
    }

    #[Test]
    public function returnsNullForBraceOnlyBlock(): void
    {
        $source = "{\n}\n{\n}\n";

        $hint = $this->extractor->extract($source, 1, 4);

        self::assertNull($hint);
    }

    #[Test]
    public function returnsNullForInvalidStartLine(): void
    {
        $source = "<?php\n\$x = 1;\n";

        self::assertNull($this->extractor->extract($source, 0, 2));
        self::assertNull($this->extractor->extract($source, 100, 200));
    }

    #[Test]
    public function collapsesMultipleWhitespace(): void
    {
        $source = "<?php\n\$x   =   1;\n  \$y    =    2;\n";

        $hint = $this->extractor->extract($source, 2, 3);

        self::assertNotNull($hint);
        // Should not contain multiple consecutive spaces
        self::assertDoesNotMatchRegularExpression('/\s{2,}/', $hint);
    }

    #[Test]
    public function handlesArrayConstantBlock(): void
    {
        $source = <<<'PHP'
        <?php
        return [
            'complexity' => ['warning' => 15, 'error' => 30],
            'coupling' => ['warning' => 10, 'error' => 20],
            'cohesion' => ['warning' => 0.5, 'error' => 0.3],
        ];
        PHP;

        $hint = $this->extractor->extract($source, 2, 6);

        self::assertNotNull($hint);
        self::assertStringContainsString('return [', $hint);
    }

    #[Test]
    public function extractsMaxThreeMeaningfulLines(): void
    {
        $source = "<?php\nline1_code();\nline2_code();\nline3_code();\nline4_code();\nline5_code();\n";

        $hint = $this->extractor->extract($source, 2, 6);

        self::assertNotNull($hint);
        self::assertStringContainsString('line1_code()', $hint);
        self::assertStringContainsString('line2_code()', $hint);
        self::assertStringContainsString('line3_code()', $hint);
        // line4 should NOT be included (max 3 meaningful lines)
        self::assertStringNotContainsString('line4_code()', $hint);
    }

    #[Test]
    public function handlesSingleLongLine(): void
    {
        $longLine = '$result = array_map(fn($item) => $item->transform()->validate()->serialize()->compress()->encrypt(), $items);';
        $source = "<?php\n{$longLine}\n";

        $hint = $this->extractor->extract($source, 2, 2);

        self::assertNotNull($hint);
        self::assertLessThanOrEqual(83, \strlen($hint));
        self::assertStringEndsWith('...', $hint);
    }

    #[Test]
    public function handlesSpecialCharacters(): void
    {
        $source = "<?php\n\$x = 'hello \"world\"';\n\$y = \"it's \\\\done\";\n";

        $hint = $this->extractor->extract($source, 2, 3);

        self::assertNotNull($hint);
        // Should contain the code as-is (no escaping needed for display)
        self::assertStringContainsString('hello', $hint);
    }

    #[Test]
    public function endLineClampedToFileLength(): void
    {
        $source = "<?php\n\$x = 1;\n";

        // endLine beyond file length should not crash
        $hint = $this->extractor->extract($source, 2, 1000);

        self::assertNotNull($hint);
        self::assertStringContainsString('$x = 1', $hint);
    }
}
