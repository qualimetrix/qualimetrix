<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Git;

use AiMessDetector\Infrastructure\Git\GitRepositoryLocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitRepositoryLocator::class)]
final class GitRepositoryLocatorTest extends TestCase
{
    private GitRepositoryLocator $locator;

    protected function setUp(): void
    {
        $this->locator = new GitRepositoryLocator();
    }

    #[Test]
    public function findsGitDirInCurrentRepository(): void
    {
        $result = $this->locator->findGitDir();

        self::assertNotNull($result, 'Expected to find .git directory (tests run inside a git repo)');
    }

    #[Test]
    public function returnsAbsolutePathAsString(): void
    {
        $result = $this->locator->findGitDir();

        self::assertNotNull($result);
        self::assertStringStartsWith('/', $result, 'Path should be absolute');
    }

    #[Test]
    public function pathContainsGitReference(): void
    {
        $result = $this->locator->findGitDir();

        self::assertNotNull($result);
        // Regular repos end with .git; worktrees may have .git in the path
        self::assertStringContainsString('.git', $result);
    }

    #[Test]
    public function acceptsExplicitWorkingDirectory(): void
    {
        // Use the project root as explicit working directory
        $projectRoot = \dirname(__DIR__, 4);
        $result = $this->locator->findGitDir($projectRoot);

        self::assertNotNull($result, 'Expected to find .git directory from project root');
        self::assertStringContainsString('.git', $result);
    }

    #[Test]
    public function returnsNullForNonGitDirectory(): void
    {
        // Use a path that is guaranteed not to be inside a git repository
        $result = $this->locator->findGitDir('/');

        self::assertNull($result);
    }

    #[Test]
    public function returnsNullForNonExistentDirectory(): void
    {
        $result = $this->locator->findGitDir('/nonexistent/path/that/does/not/exist');

        self::assertNull($result);
    }
}
