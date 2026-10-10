<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit\Normalization;

use LogicException;
use PhpToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenNormalizer;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenStream;

#[CoversClass(TokenNormalizer::class)]
#[CoversClass(TokenStream::class)]
final class TokenNormalizerTest extends TestCase
{
    private TokenNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new TokenNormalizer();
    }

    #[Test]
    public function itStripsWhitespaceAndComments(): void
    {
        $code = <<<'PHP'
<?php
// comment
/* block comment */
$a = 1;
PHP;

        $tokens = $this->normalizer->normalize($code);

        $values = $tokens->values;

        self::assertNotContains('// comment', $values);
        self::assertNotContains('/* block comment */', $values);
    }

    #[Test]
    public function itNormalizesVariables(): void
    {
        $code = '<?php $longVariableName = $anotherVar;';

        $tokens = $this->normalizer->normalize($code);

        self::assertSame(['$_', '=', '$_', ';'], $tokens->values);
    }

    #[Test]
    public function itNormalizesStringLiterals(): void
    {
        $code = "<?php \$x = 'hello world';";

        $tokens = $this->normalizer->normalize($code);

        self::assertSame(['$_', '=', "'_'", ';'], $tokens->values);
    }

    #[Test]
    public function itNormalizesNumbers(): void
    {
        $code = '<?php $x = 42; $y = 3.14;';

        $tokens = $this->normalizer->normalize($code);

        self::assertSame(['$_', '=', '0', ';', '$_', '=', '0', ';'], $tokens->values);
    }

    #[Test]
    public function itPreservesStructuralTokens(): void
    {
        $code = '<?php function foo() { return true; }';

        $tokens = $this->normalizer->normalize($code);

        $values = $tokens->values;

        self::assertContains('function', $values);
        self::assertContains('return', $values);
        self::assertContains('{', $values);
        self::assertContains('}', $values);
        self::assertContains('(', $values);
        self::assertContains(')', $values);
    }

    #[Test]
    public function itKeepsOneInlineHtmlDigestWithOriginalByteAndLineSpans(): void
    {
        $source = "<?php echo 1; ?>\n<div>\nRaw</div>";
        $stream = $this->normalizer->normalize($source);
        $html = array_values(array_filter($stream->values, static fn(string $value): bool => str_starts_with($value, 'html:')));

        self::assertCount(1, $html);
        self::assertSame('html:' . hash('xxh128', '<div> Raw</div>'), $html[0]);
        $index = array_search($html[0], $stream->values, true);
        self::assertIsInt($index);
        self::assertSame(strpos($source, '<div>'), $stream->startByte($index));
        self::assertSame(\strlen($source), $stream->endByte($index));
        self::assertSame(2, $stream->startLine($index));
        self::assertSame(3, $stream->endLine($index));
    }

    #[Test]
    public function itCollapsesOnlyAsciiWhitespaceInsideHtmlAndKeepsInvalidUtf8OutOfTokenValues(): void
    {
        $withAsciiWhitespace = $this->normalizer->normalize("A\t\n\v B<?php");
        $collapsed = $this->normalizer->normalize('A B<?php');
        $nonAsciiSpace = $this->normalizer->normalize("A\xc2\xa0B<?php");
        $rawCp1251 = $this->normalizer->normalize("\xcf\xf0<?php");

        self::assertSame($collapsed->values, $withAsciiWhitespace->values);
        self::assertNotSame($collapsed->values, $nonAsciiSpace->values);
        self::assertSame('html:' . hash('xxh128', "\xcf\xf0"), $rawCp1251->values[0]);
        self::assertIsString(json_encode($rawCp1251->values, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function itFoldsKeywordCastMagicConstantAndBuiltinLiteralCaseButKeepsIdentifiers(): void
    {
        $upper = $this->normalizer->normalize('<?php FuNcTiOn Foo() { ReTuRn TRUE; } (INT)$x; __dIr__; STRTOUPPER(1);');
        $lower = $this->normalizer->normalize('<?php function Foo() { return true; } (int)$x; __DIR__; STRTOUPPER(1);');
        $differentIdentifier = $this->normalizer->normalize('<?php function foo() { return true; } (int)$x; __DIR__; strtoupper(1);');

        self::assertSame($lower->values, $upper->values);
        self::assertNotSame($lower->values, $differentIdentifier->values);
        self::assertContains('STRTOUPPER', $upper->values);
    }

    #[Test]
    public function itProducesSameTokensForIdenticalStructureWithDifferentVariablesAndLiterals(): void
    {
        // Only variables and literals differ — should produce same tokens
        $code1 = '<?php $foo = $bar + 1; $baz = "hello";';
        $code2 = '<?php $qux = $abc + 99; $xyz = "world";';

        $tokens1 = $this->normalizer->normalize($code1);
        $tokens2 = $this->normalizer->normalize($code2);

        $values1 = $tokens1->values;
        $values2 = $tokens2->values;

        self::assertSame($values1, $values2);
    }

    #[Test]
    public function itDoesNotNormalizeFunctionNames(): void
    {
        // Function names (T_STRING) are preserved — different names ≠ duplicate
        $code1 = '<?php function foo() {}';
        $code2 = '<?php function bar() {}';

        $tokens1 = $this->normalizer->normalize($code1);
        $tokens2 = $this->normalizer->normalize($code2);

        $values1 = $tokens1->values;
        $values2 = $tokens2->values;

        self::assertNotSame($values1, $values2);
    }

    #[Test]
    public function itProducesDifferentTokensForDifferentStructure(): void
    {
        $code1 = '<?php function foo($bar) { return $bar + 1; }';
        $code2 = '<?php function foo($bar) { echo $bar; }';

        $tokens1 = $this->normalizer->normalize($code1);
        $tokens2 = $this->normalizer->normalize($code2);

        $values1 = $tokens1->values;
        $values2 = $tokens2->values;

        self::assertNotSame($values1, $values2);
    }

    #[Test]
    public function itReturnsEmptyTokensForEmptyFile(): void
    {
        $tokens = $this->normalizer->normalize('<?php');

        self::assertSame([], $tokens->values);
        self::assertSame(0, $tokens->count());
    }

    #[Test]
    public function itPreservesLineNumbers(): void
    {
        $code = <<<'PHP'
<?php

$x = 1;
$y = 2;
PHP;

        $tokens = $this->normalizer->normalize($code);

        // First non-skipped token should have line 3 ($x)
        self::assertGreaterThan(0, $tokens->count());
        self::assertSame(3, $tokens->startLine(0));
    }

    #[Test]
    public function itAssignsASingleCharacterTokenTheLineItStandsOnAfterAMultiLineGap(): void
    {
        // The whitespace before `}` starts on line 2 and spans four line
        // breaks, so a line inherited from the previous array token lands
        // four lines too early.
        $tokens = $this->normalizer->normalize("<?php\nfoo();\n\n\n\n}\n");

        $closing = array_keys($tokens->values, '}', true);

        self::assertCount(1, $closing);
        self::assertSame(6, $tokens->startLine($closing[0]));
    }

    #[Test]
    public function itAssignsAnOpeningBraceOnItsOwnLineThatLine(): void
    {
        $code = <<<'PHP'
<?php
function foo(): array
{
    return [];
}
PHP;

        $tokens = $this->normalizer->normalize($code);

        $lines = [];
        foreach ($tokens->values as $index => $value) {
            if ($value === '{' || $value === '}' || $value === ';') {
                $lines[] = $value . '@' . $tokens->startLine($index);
            }
        }

        self::assertSame(['{@3', ';@4', '}@5'], $lines);
    }

    #[Test]
    public function itKeepsASingleCharacterTokenOnTheLineOfAPrecedingTokenThatSharesIt(): void
    {
        $tokens = $this->normalizer->normalize("<?php\n\$a = 1;\n\$b = 2;\n");

        $semicolons = [];
        foreach (array_keys($tokens->values, ';', true) as $index) {
            $semicolons[] = $tokens->startLine($index);
        }

        self::assertSame([2, 3], $semicolons);
    }

    #[Test]
    public function itKeepsOriginalCoordinatesAndCoveredLinesAlignedAcrossPhpBarriers(): void
    {
        $source = "<?php\nconst X = 'a\nb' ?>\nignored\n<?php\n// ignored\n\$x = 9;\n";
        $tokens = $this->normalizer->normalize($source);

        self::assertSame(['const', 'X', '=', "'_'", 'html:232024ebfa870075eec12fa52c8fa11c', '$_', '=', '0', ';'], $tokens->values);
        self::assertSame(9, $tokens->count());
        self::assertSame([2, 2, 2, 2, 4, 7, 7, 7, 7], self::coordinates($tokens, 'startLine'));
        self::assertSame([2, 2, 2, 3, 4, 7, 7, 7, 7], self::coordinates($tokens, 'endLine'));
        self::assertSame([1, 1, 1, 2, 3, 4, 4, 4, 4], self::coordinates($tokens, 'coveredPrefix'));
        self::assertSame('111100000', $tokens->dataMask);
        self::assertSame([6, 12, 14, 16, 25, 50, 53, 55, 56], self::coordinates($tokens, 'startByte'));
        self::assertSame([11, 13, 15, 21, 33, 52, 54, 56, 57], self::coordinates($tokens, 'endByte'));

        $untagged = (new TokenNormalizer(tagDataDeclarations: false))->normalize($source);
        self::assertSame($tokens->values, $untagged->values);
        self::assertSame(self::coordinates($tokens, 'startByte'), self::coordinates($untagged, 'startByte'));
        self::assertSame(self::coordinates($tokens, 'endByte'), self::coordinates($untagged, 'endByte'));
        self::assertSame('000000000', $untagged->dataMask);
    }

    #[Test]
    #[DataProvider('sourceNewlines')]
    public function itKeepsSourceLinesConsistentWithThePhpTokenizer(string $newline): void
    {
        $source = '<?php' . $newline . '{' . $newline . '$x = "one' . $newline . 'two";'
            . $newline . 'echo $x;' . $newline . '}';
        $tokens = $this->normalizer->normalize($source);
        $native = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn(PhpToken $token): bool => !\in_array($token->id, [\T_OPEN_TAG, \T_WHITESPACE], true),
        ));

        self::assertSame(['{', '$_', '=', "'_'", ';', 'echo', '$_', ';', '}'], $tokens->values);
        self::assertSame(2, $tokens->startLine(0), 'The opening tag must advance the first single-character token');
        self::assertSame(array_map(static fn(PhpToken $token): int => $token->line, $native), self::coordinates($tokens, 'startLine'));
        self::assertSame([2, 3, 3, 4, 4, 5, 5, 5, 6], self::coordinates($tokens, 'endLine'));
        self::assertSame([1, 2, 2, 3, 3, 4, 4, 4, 5], self::coordinates($tokens, 'coveredPrefix'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sourceNewlines(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
        yield 'CR' => ["\r"];
    }

    #[Test]
    public function itKeepsCoordinatesAlignedWithNativeTokensAcrossStorageWidths(): void
    {
        foreach ([str_repeat(' ', 300), str_repeat(' ', 70000), str_repeat("\n", 300), str_repeat("\n", 70000)] as $padding) {
            $source = '<?php ' . $padding . 'echo 1;';
            $stream = $this->normalizer->normalize($source);
            $native = array_values(array_filter(PhpToken::tokenize($source), static fn(PhpToken $token): bool => \in_array($token->text, ['echo', '1', ';'], true)));
            self::assertSame(['echo', '0', ';'], $stream->values);
            self::assertSame('000', $stream->dataMask);
            self::assertCount($stream->count(), $native);
            foreach ($native as $index => $token) {
                self::assertSame([$token->line, $token->line, 1, $token->pos, $token->pos + \strlen($token->text)], [
                    $stream->startLine($index), $stream->endLine($index), $stream->coveredPrefix($index), $stream->startByte($index), $stream->endByte($index),
                ]);
            }
        }
    }

    #[Test]
    public function itKeepsRetainedCoordinatesWithinTheAllocationBudget(): void
    {
        $source = "<?php\n" . str_repeat("echo 1;\n", 2000);
        $warm = $this->normalizer->normalize($source);
        self::assertSame(6000, $warm->count());
        unset($warm);
        $before = memory_get_usage();
        $streams = [];
        for ($file = 0; $file < 20; $file++) {
            $streams[] = $this->normalizer->normalize($source);
        }
        $retained = memory_get_usage() - $before;
        self::assertLessThanOrEqual(12 * 1024 * 1024, $retained, 'Retained token coordinates must not become five PHP integer arrays');
        self::assertCount(20, $streams);
        self::assertSame(2001, $streams[19]->endLine(5999));
        self::assertSame(\strlen($source) - 1, $streams[19]->endByte(5999));
    }

    /** @return list<int> */
    private static function coordinates(TokenStream $stream, string $method): array
    {
        $coordinates = [];
        for ($index = 0; $index < $stream->count(); $index++) {
            $coordinates[] = match ($method) {
                'startLine' => $stream->startLine($index),
                'endLine' => $stream->endLine($index),
                'coveredPrefix' => $stream->coveredPrefix($index),
                'startByte' => $stream->startByte($index),
                'endByte' => $stream->endByte($index),
                default => throw new LogicException('Unknown coordinate field'),
            };
        }

        return $coordinates;
    }

}
