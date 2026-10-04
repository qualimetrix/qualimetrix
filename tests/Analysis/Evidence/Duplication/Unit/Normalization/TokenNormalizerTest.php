<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit\Normalization;

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
        self::assertSame(3, $tokens->startLines[0]);
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
        self::assertSame(6, $tokens->startLines[$closing[0]]);
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
                $lines[] = $value . '@' . $tokens->startLines[$index];
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
            $semicolons[] = $tokens->startLines[$index];
        }

        self::assertSame([2, 3], $semicolons);
    }

    #[Test]
    public function itKeepsOriginalCoordinatesAndCoveredLinesAlignedAfterRemovingPhpBarriers(): void
    {
        $source = "<?php\nconst X = 'a\nb' ?>\nignored\n<?php\n// ignored\n\$x = 9;\n";
        $tokens = $this->normalizer->normalize($source);

        self::assertSame(['const', 'X', '=', "'_'", '$_', '=', '0', ';'], $tokens->values);
        self::assertSame(8, $tokens->count());
        self::assertSame([2, 2, 2, 2, 7, 7, 7, 7], $tokens->startLines);
        self::assertSame([2, 2, 2, 3, 7, 7, 7, 7], $tokens->endLines);
        self::assertSame([1, 1, 1, 2, 3, 3, 3, 3], $tokens->coveredPrefix);
        self::assertSame('11110000', $tokens->dataMask);
        self::assertSame([6, 12, 14, 16, 50, 53, 55, 56], $tokens->startBytes);
        self::assertSame([11, 13, 15, 21, 52, 54, 56, 57], $tokens->endBytes);

        $untagged = (new TokenNormalizer(tagDataDeclarations: false))->normalize($source);
        self::assertSame($tokens->values, $untagged->values);
        self::assertSame($tokens->startBytes, $untagged->startBytes);
        self::assertSame($tokens->endBytes, $untagged->endBytes);
        self::assertSame('00000000', $untagged->dataMask);
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
        self::assertSame(2, $tokens->startLines[0], 'The opening tag must advance the first single-character token');
        self::assertSame(array_map(static fn(PhpToken $token): int => $token->line, $native), $tokens->startLines);
        self::assertSame([2, 3, 3, 4, 4, 5, 5, 5, 6], $tokens->endLines);
        self::assertSame([1, 2, 2, 3, 3, 4, 4, 4, 5], $tokens->coveredPrefix);
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
}
