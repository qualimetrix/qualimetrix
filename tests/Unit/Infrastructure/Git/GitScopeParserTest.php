<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Git;

use AiMessDetector\Infrastructure\Git\GitScope;
use AiMessDetector\Infrastructure\Git\GitScopeParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitScopeParser::class)]
#[CoversClass(GitScope::class)]
final class GitScopeParserTest extends TestCase
{
    private GitScopeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GitScopeParser();
    }

    /**
     * @param non-empty-string $input
     * @param non-empty-string $expectedRef
     */
    #[DataProvider('validGitScopesProvider')]
    public function testParsesValidGitScopes(string $input, string $expectedRef): void
    {
        $scope = $this->parser->parse($input);

        $this->assertNotNull($scope);
        $this->assertSame($expectedRef, $scope->ref);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validGitScopesProvider(): iterable
    {
        yield 'staged' => ['git:staged', 'staged'];
        yield 'HEAD' => ['git:HEAD', 'HEAD'];
        yield 'branch name' => ['git:main', 'main'];
        yield 'two-dot syntax' => ['git:main..HEAD', 'main..HEAD'];
        yield 'three-dot syntax' => ['git:main...HEAD', 'main...HEAD'];
        yield 'commit ref' => ['git:HEAD~3', 'HEAD~3'];
        yield 'commit hash' => ['git:abc123', 'abc123'];
        yield 'complex ref' => ['git:origin/feature/test', 'origin/feature/test'];
    }

    /**
     * @param non-empty-string $input
     */
    #[DataProvider('invalidGitScopesProvider')]
    public function testReturnsNullForInvalidScopes(string $input): void
    {
        $scope = $this->parser->parse($input);

        $this->assertNull($scope);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidGitScopesProvider(): iterable
    {
        yield 'no prefix' => ['staged'];
        yield 'empty' => [''];
        yield 'wrong prefix' => ['svn:main'];
        yield 'only prefix' => ['git:'];
    }

    /**
     * @param non-empty-string $input
     */
    #[DataProvider('validGitScopesProvider')]
    public function testIsValidReturnsTrueForValidScopes(string $input, string $_expectedRef): void
    {
        $this->assertTrue($this->parser->isValid($input));
    }

    /**
     * @param non-empty-string $input
     */
    #[DataProvider('invalidGitScopesProvider')]
    public function testIsValidReturnsFalseForInvalidScopes(string $input): void
    {
        $this->assertFalse($this->parser->isValid($input));
    }
}
