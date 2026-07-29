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
        // TokenNormalizer strips T_CLOSE_TAG/T_OPEN_TAG/T_INLINE_HTML
        // entirely, so a `const` statement terminated by a PHP closing
        // tag (rather than `;`) leaves no in-stream marker for where the
        // declaration ends. findStatementEnd() must bail out (tag nothing)
        // instead of scanning across the tag boundary into the next PHP
        // block and mis-tagging unrelated executable code as data.
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

        $computeIdx = $this->indexOfValue($tokens, 'compute');
        $semicolonIdx = $this->indexOfFirstValueAfter($tokens, $computeIdx, ';');

        for ($i = $computeIdx; $i <= $semicolonIdx; $i++) {
            self::assertFalse($tokens[$i]->isData, "Token at {$i} ('{$tokens[$i]->value}') is executable code and must not be tagged as data");
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
