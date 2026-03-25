<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Duplication;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Duplication\NormalizedToken;
use Qualimetrix\Analysis\Duplication\TokenNormalizer;

#[CoversClass(TokenNormalizer::class)]
#[CoversClass(NormalizedToken::class)]
final class TokenNormalizerTest extends TestCase
{
    private TokenNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new TokenNormalizer();
    }

    public function testStripsWhitespaceAndComments(): void
    {
        $code = <<<'PHP'
<?php
// comment
/* block comment */
$a = 1;
PHP;

        $tokens = $this->normalizer->normalize($code);

        $values = array_map(fn(NormalizedToken $t) => $t->value, $tokens);

        self::assertNotContains('// comment', $values);
        self::assertNotContains('/* block comment */', $values);
    }

    public function testNormalizesVariables(): void
    {
        $code = '<?php $longVariableName = $anotherVar;';

        $tokens = $this->normalizer->normalize($code);

        $variables = array_filter($tokens, fn(NormalizedToken $t) => $t->type === \T_VARIABLE);
        foreach ($variables as $var) {
            self::assertSame('$_', $var->value);
        }
    }

    public function testNormalizesStringLiterals(): void
    {
        $code = "<?php \$x = 'hello world';";

        $tokens = $this->normalizer->normalize($code);

        $strings = array_filter($tokens, fn(NormalizedToken $t) => $t->type === \T_CONSTANT_ENCAPSED_STRING);
        foreach ($strings as $str) {
            self::assertSame("'_'", $str->value);
        }
    }

    public function testNormalizesNumbers(): void
    {
        $code = '<?php $x = 42; $y = 3.14;';

        $tokens = $this->normalizer->normalize($code);

        $numbers = array_filter($tokens, fn(NormalizedToken $t) => \in_array($t->type, [\T_LNUMBER, \T_DNUMBER], true));
        foreach ($numbers as $num) {
            self::assertSame('0', $num->value);
        }
    }

    public function testPreservesStructuralTokens(): void
    {
        $code = '<?php function foo() { return true; }';

        $tokens = $this->normalizer->normalize($code);

        $values = array_map(fn(NormalizedToken $t) => $t->value, $tokens);

        self::assertContains('function', $values);
        self::assertContains('return', $values);
        self::assertContains('{', $values);
        self::assertContains('}', $values);
        self::assertContains('(', $values);
        self::assertContains(')', $values);
    }

    public function testIdenticalStructureWithDifferentVariablesAndLiterals(): void
    {
        // Only variables and literals differ — should produce same tokens
        $code1 = '<?php $foo = $bar + 1; $baz = "hello";';
        $code2 = '<?php $qux = $abc + 99; $xyz = "world";';

        $tokens1 = $this->normalizer->normalize($code1);
        $tokens2 = $this->normalizer->normalize($code2);

        $values1 = array_map(fn(NormalizedToken $t) => $t->value, $tokens1);
        $values2 = array_map(fn(NormalizedToken $t) => $t->value, $tokens2);

        self::assertSame($values1, $values2);
    }

    public function testFunctionNamesAreNotNormalized(): void
    {
        // Function names (T_STRING) are preserved — different names ≠ duplicate
        $code1 = '<?php function foo() {}';
        $code2 = '<?php function bar() {}';

        $tokens1 = $this->normalizer->normalize($code1);
        $tokens2 = $this->normalizer->normalize($code2);

        $values1 = array_map(fn(NormalizedToken $t) => $t->value, $tokens1);
        $values2 = array_map(fn(NormalizedToken $t) => $t->value, $tokens2);

        self::assertNotSame($values1, $values2);
    }

    public function testDifferentStructureProducesDifferentTokens(): void
    {
        $code1 = '<?php function foo($bar) { return $bar + 1; }';
        $code2 = '<?php function foo($bar) { echo $bar; }';

        $tokens1 = $this->normalizer->normalize($code1);
        $tokens2 = $this->normalizer->normalize($code2);

        $values1 = array_map(fn(NormalizedToken $t) => $t->value, $tokens1);
        $values2 = array_map(fn(NormalizedToken $t) => $t->value, $tokens2);

        self::assertNotSame($values1, $values2);
    }

    public function testEmptyFileReturnsEmptyTokens(): void
    {
        $tokens = $this->normalizer->normalize('<?php');

        self::assertSame([], $tokens);
    }

    public function testPreservesLineNumbers(): void
    {
        $code = <<<'PHP'
<?php

$x = 1;
$y = 2;
PHP;

        $tokens = $this->normalizer->normalize($code);

        // First non-skipped token should have line 3 ($x)
        self::assertGreaterThan(0, \count($tokens));
        self::assertSame(3, $tokens[0]->line);
    }
}
