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
