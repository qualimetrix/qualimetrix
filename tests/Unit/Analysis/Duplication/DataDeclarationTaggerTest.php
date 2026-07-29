<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Duplication;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Duplication\DataDeclarationTagger;
use Qualimetrix\Analysis\Duplication\NormalizedToken;
use Qualimetrix\Analysis\Duplication\TokenNormalizer;

#[CoversClass(DataDeclarationTagger::class)]
#[CoversClass(TokenNormalizer::class)]
#[CoversClass(NormalizedToken::class)]
final class DataDeclarationTaggerTest extends TestCase
{
    private TokenNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new TokenNormalizer();
    }

    #[Test]
    public function itTagsEntireConstArrayDeclarationAsData(): void
    {
        $code = <<<'PHP'
<?php

final class Registry
{
    private const array MAP = [
        'a' => true,
        'b' => true,
    ];
}
PHP;

        $tokens = $this->normalizer->normalize($code);

        $constIdx = $this->indexOfType($tokens, \T_CONST);
        $semicolonIdx = $this->indexOfFirstValueAfter($tokens, $constIdx, ';');

        for ($i = $constIdx; $i <= $semicolonIdx; $i++) {
            self::assertTrue($tokens[$i]->isData, "Token at {$i} ('{$tokens[$i]->value}') should be tagged as data");
        }
    }

    #[Test]
    public function itTagsPropertyArrayInitializerAsData(): void
    {
        $code = <<<'PHP'
<?php

final class Config
{
    private static array $defaults = [
        'x' => 1,
        'y' => 2,
    ];
}
PHP;

        $tokens = $this->normalizer->normalize($code);

        $privateIdx = $this->indexOfType($tokens, \T_PRIVATE);
        $semicolonIdx = $this->indexOfFirstValueAfter($tokens, $privateIdx, ';');

        for ($i = $privateIdx; $i <= $semicolonIdx; $i++) {
            self::assertTrue($tokens[$i]->isData, "Token at {$i} ('{$tokens[$i]->value}') should be tagged as data");
        }
    }

    #[Test]
    public function itDoesNotTagMethodBodyArrayAsData(): void
    {
        $code = <<<'PHP'
<?php

final class Service
{
    public function build(): array
    {
        $result = [
            'x' => 1,
            'y' => 2,
        ];

        return $result;
    }
}
PHP;

        $tokens = $this->normalizer->normalize($code);

        foreach ($tokens as $token) {
            self::assertFalse($token->isData, "Token '{$token->value}' in a method body must not be tagged as data");
        }
    }

    #[Test]
    public function itDoesNotTagBareStaticLocalVariableAsData(): void
    {
        // Bare `static $x = [...]` inside a function body is a local static
        // variable, syntactically indistinguishable from an implicit-public
        // static property without a full parser. Deliberately not matched
        // (documented false negative, not a false positive).
        $code = <<<'PHP'
<?php

function cachedList(): array
{
    static $cache = [
        'a' => 1,
        'b' => 2,
    ];

    return $cache;
}
PHP;

        $tokens = $this->normalizer->normalize($code);

        foreach ($tokens as $token) {
            self::assertFalse($token->isData, "Token '{$token->value}' should not be tagged (bare static local var, no visibility keyword)");
        }
    }

    #[Test]
    public function itDoesNotTagConstructorPromotedPropertyArrayDefaultAsData(): void
    {
        // The array default terminates with ',' or ')', not ';' — must not
        // be mistaken for a standalone property declaration statement.
        $code = <<<'PHP'
<?php

final class Service
{
    public function __construct(
        private array $config = ['a' => 1, 'b' => 2],
    ) {}
}
PHP;

        $tokens = $this->normalizer->normalize($code);

        foreach ($tokens as $token) {
            self::assertFalse($token->isData, "Token '{$token->value}' (promoted param default) must not be tagged as data");
        }
    }

    #[Test]
    public function itReturnsOriginalTokensWhenNothingIsData(): void
    {
        $code = '<?php $x = 1;';

        $tokens = $this->normalizer->normalize($code);

        foreach ($tokens as $token) {
            self::assertFalse($token->isData);
        }
    }

    #[Test]
    public function itDoesNotTagExecutableCodeAfterACloseTagTerminatedConstDeclaration(): void
    {
        // TokenNormalizer preserves the PHP closing-tag boundary as a
        // PHP_CLOSE_TAG_BARRIER token (see its class docblock) specifically
        // so findStatementEnd() can recognize a `const` statement
        // terminated by a closing tag (rather than `;`) as properly ended
        // right there, instead of scanning across the boundary into the
        // next PHP block and mis-tagging unrelated executable code as data.
        //
        // NB: the closing tag is written literally in the heredoc below —
        // do not put a literal "?" followed by ">" in a // comment in this
        // file, it prematurely ends the comment and exits PHP mode.
        $code = <<<'PHP'
        <?php if ($a): ?>
        <?php const B = 2 ?>
        <?php $y = compute(1, 2); ?>
        <?php endif; ?>
        PHP;

        $tokens = $this->normalizer->normalize($code);

        $constIdx = $this->indexOfType($tokens, \T_CONST);
        $computeIdx = $this->indexOfValue($tokens, 'compute');

        self::assertTrue($tokens[$constIdx]->isData, 'The const declaration itself must still be tagged as data');

        $semicolonIdx = $this->indexOfFirstValueAfter($tokens, $computeIdx, ';');

        for ($i = $computeIdx; $i <= $semicolonIdx; $i++) {
            self::assertFalse($tokens[$i]->isData, "Token at {$i} ('{$tokens[$i]->value}') is executable code and must not be tagged as data");
        }
    }

    #[Test]
    public function itDoesNotTagExecutableCodeAfterACloseTagTerminatedConstDeclarationWhenTheFollowingBlockHasNoVariables(): void
    {
        // Regression for the residual defect a third reviewer found: the
        // previous fix only bailed out findStatementEnd() when it hit a
        // T_VARIABLE token past the PHP closing-tag boundary. That is not
        // enough — if the executable code in the next PHP block has no
        // variables at all, the forward scan for `;` still ran straight
        // through the boundary and tagged the entire following statement
        // (however long) as data, silently hiding real duplication there.
        //
        // TokenNormalizer's PHP_CLOSE_TAG_BARRIER closes this gap
        // unconditionally, regardless of what the next block contains.
        //
        // NB: the closing tag is written literally in the heredoc below —
        // do not put a literal "?" followed by ">" in a // comment in this
        // file, it prematurely ends the comment and exits PHP mode.
        $code = <<<'PHP'
        <?php const X = [1] ?>
        <?php if (ready()) { boot(); } done();
        PHP;

        $tokens = $this->normalizer->normalize($code);

        $constIdx = $this->indexOfType($tokens, \T_CONST);
        $ifIdx = $this->indexOfValue($tokens, 'if');

        self::assertTrue($tokens[$constIdx]->isData, 'The const declaration itself must still be tagged as data');

        for ($i = $ifIdx; $i < \count($tokens); $i++) {
            self::assertFalse($tokens[$i]->isData, "Token at {$i} ('{$tokens[$i]->value}') is executable code in the next PHP block and must not be tagged as data");
        }
    }

    #[Test]
    public function itTagsAPropertyArrayInitializerTerminatedByACloseTagAsData(): void
    {
        // Mirrors the const case above for the property-array-declaration
        // pattern: matchPropertyArrayDeclaration() must accept the
        // PHP_CLOSE_TAG_BARRIER as a valid terminator too, since a PHP
        // closing tag is a legal implicit statement terminator (verified
        // with `php -l`).
        //
        // NB: the closing tag is written literally in the heredoc below —
        // do not put a literal "?" followed by ">" in a // comment in this
        // file, it prematurely ends the comment and exits PHP mode.
        $code = <<<'PHP'
        <?php
        class Config
        {
            private array $x = [1, 2] ?>
        <?php
            public function y(): void { z(); }
        }
        PHP;

        $tokens = $this->normalizer->normalize($code);

        $privateIdx = $this->indexOfType($tokens, \T_PRIVATE);
        $xIdx = $this->indexOfValue($tokens, '$_');
        $closeBracketIdx = $this->indexOfFirstValueAfter($tokens, $xIdx, ']');

        for ($i = $privateIdx; $i <= $closeBracketIdx; $i++) {
            self::assertTrue($tokens[$i]->isData, "Token at {$i} ('{$tokens[$i]->value}') should be tagged as data");
        }

        $zIdx = $this->indexOfValue($tokens, 'z');
        for ($i = $zIdx; $i < \count($tokens); $i++) {
            self::assertFalse($tokens[$i]->isData, "Token at {$i} ('{$tokens[$i]->value}') is executable code and must not be tagged as data");
        }
    }

    #[Test]
    public function itTagsALegacyVarPropertyArrayInitializerAsData(): void
    {
        // PHP's legacy `var` visibility keyword (pre-PHP 5 syntax, still
        // legal) was already in MODIFIER_TYPES for the backward
        // modifier-walk, but was never wired up as a scan entry point —
        // a property declared with `var` instead of `public`/`private`
        // never got a chance to be matched at all. T_VAR is now also a
        // trigger, since `var` is unambiguous — it cannot legally appear
        // anywhere except a property declaration.
        $code = <<<'PHP'
        <?php

        final class LegacyConfig
        {
            var array $defaults = [
                'x' => 1,
            ];
        }
        PHP;

        $tokens = $this->normalizer->normalize($code);

        $varIdx = $this->indexOfType($tokens, \T_VAR);
        $semicolonIdx = $this->indexOfFirstValueAfter($tokens, $varIdx, ';');

        for ($i = $varIdx; $i <= $semicolonIdx; $i++) {
            self::assertTrue($tokens[$i]->isData, "Token at {$i} ('{$tokens[$i]->value}') should be tagged as data");
        }
    }

    #[Test]
    public function itTagsAPhp84AsymmetricVisibilityPropertyArrayInitializerAsData(): void
    {
        // PHP 8.4 asymmetric visibility (`public(set)`, ...) tokenizes as
        // a single T_PUBLIC_SET/T_PROTECTED_SET/T_PRIVATE_SET token
        // (verified via token_get_all()), entirely distinct from plain
        // T_PUBLIC/T_PROTECTED/T_PRIVATE — so without adding these to
        // PROPERTY_DECLARATION_TRIGGER_TYPES the scan would never even
        // start for such a property.
        $code = <<<'PHP'
        <?php

        final class Config
        {
            public(set) array $x = [1, 2];
        }
        PHP;

        $tokens = $this->normalizer->normalize($code);

        $triggerIdx = $this->indexOfType($tokens, \T_PUBLIC_SET);
        $semicolonIdx = $this->indexOfFirstValueAfter($tokens, $triggerIdx, ';');

        for ($i = $triggerIdx; $i <= $semicolonIdx; $i++) {
            self::assertTrue($tokens[$i]->isData, "Token at {$i} ('{$tokens[$i]->value}') should be tagged as data");
        }
    }

    #[Test]
    public function itDoesNotTagAMultiPropertyDeclarationAtAll(): void
    {
        // `private array $a = [1], $b = [2];` — the token right after the
        // first array literal's closing bracket is `,`, not `;`, so
        // matchPropertyArrayDeclaration() rejects the whole statement.
        // Nothing is tagged, not even up to the first comma.
        $code = <<<'PHP'
        <?php

        final class Config
        {
            private array $a = [1], $b = [2];
        }
        PHP;

        $tokens = $this->normalizer->normalize($code);

        foreach ($tokens as $token) {
            self::assertFalse($token->isData, "Token '{$token->value}' should not be tagged — multi-property declarations are not matched at all");
        }
    }

    #[Test]
    public function itHandlesAnEmptySourceStringWithoutError(): void
    {
        $tokens = $this->normalizer->normalize('');

        self::assertSame([], $tokens);
    }

    #[Test]
    public function itHandlesSourceWithNoPhpOpenTagWithoutError(): void
    {
        $tokens = $this->normalizer->normalize('plain text, no PHP here');

        // Pure T_INLINE_HTML is skipped entirely by TokenNormalizer, so no
        // tokens survive to be scanned — this exercises the tagger against
        // an empty token list rather than actually finding a match.
        self::assertSame([], $tokens);
    }

    #[Test]
    public function itHandlesSourceContainingOnlyAnOpenTagWithoutError(): void
    {
        $tokens = $this->normalizer->normalize('<?php');

        self::assertSame([], $tokens);
    }

    /**
     * @param list<NormalizedToken> $tokens
     */
    private function indexOfValue(array $tokens, string $value): int
    {
        foreach ($tokens as $i => $token) {
            if ($token->value === $value) {
                return $i;
            }
        }

        self::fail("No token with value '{$value}' found");
    }

    /**
     * @param list<NormalizedToken> $tokens
     */
    private function indexOfType(array $tokens, int $type): int
    {
        foreach ($tokens as $i => $token) {
            if ($token->type === $type) {
                return $i;
            }
        }

        self::fail("No token of type {$type} found");
    }

    /**
     * @param list<NormalizedToken> $tokens
     */
    private function indexOfFirstValueAfter(array $tokens, int $startIdx, string $value): int
    {
        for ($i = $startIdx; $i < \count($tokens); $i++) {
            if ($tokens[$i]->value === $value) {
                return $i;
            }
        }

        self::fail("No token with value '{$value}' found after index {$startIdx}");
    }
}
