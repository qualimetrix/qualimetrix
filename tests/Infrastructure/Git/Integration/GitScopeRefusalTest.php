<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Git\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Git\GitClient;
use Qualimetrix\Infrastructure\Git\GitScopeRefusedException;
use Qualimetrix\Infrastructure\Git\UnresolvedGitReferenceException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Scopes git will not answer for, and the shape the user is told about them.
 *
 * The rule this pins is the one `src/Infrastructure/Git/README.md` states: a
 * bad scope is an input refusal whatever went wrong behind it. Two shapes used
 * to escape it — `HEAD` returned from validation unchecked, and a range of
 * three endpoints whose parts each resolve — and both surfaced as a Symfony
 * Process dump titled "Internal error" with exit 1, which says the tool is
 * broken rather than the scope.
 *
 * Which exception class carries a refusal is not asserted anywhere here: what
 * the user gets is the exit code, and the CLI's ladder derives it from
 * `InvalidArgumentException`. So the end-to-end cases measure the code through
 * the binary instead of restating the hierarchy.
 */
#[CoversClass(GitClient::class)]
#[CoversClass(GitScopeRefusedException::class)]
final class GitScopeRefusalTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/qmx-git-scope-' . bin2hex(random_bytes(6));

        if (!mkdir($dir) || !is_dir($dir)) {
            throw new RuntimeException('Cannot create the fixture repository: ' . $dir);
        }

        $resolved = realpath($dir);

        if ($resolved === false) {
            throw new RuntimeException('Cannot resolve the fixture repository: ' . $dir);
        }

        $this->repoRoot = $resolved;
        $this->exec('git init');
        $this->exec('git config user.email "test@example.com"');
        $this->exec('git config user.name "Test User"');
        $this->exec('git checkout -b main');
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->repoRoot);
    }

    /**
     * A repository before its first commit is an ordinary state, not an edge:
     * `git init` then `git add` is what a new project looks like for as long
     * as it takes to write the first commit message.
     */
    #[Test]
    public function itRefusesHeadInARepositoryWithNoCommits(): void
    {
        file_put_contents($this->repoRoot . '/a.php', "<?php\n");
        $this->exec('git add -A');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        try {
            $client->getChangedFiles('HEAD');
            self::fail('Expected HEAD to be refused in a repository with no commits.');
        } catch (UnresolvedGitReferenceException $refusal) {
            self::assertStringStartsWith('Git reference "HEAD" does not resolve to a commit.', $refusal->getMessage());
            self::assertStringContainsString('git: ', $refusal->getMessage());
        }
    }

    /**
     * `staged` is the neighbouring scope that must keep working in the same
     * repository: `git diff --cached` compares against the empty tree when
     * there is no HEAD. Without this, refusing HEAD could be over-applied and
     * nothing would notice.
     */
    #[Test]
    public function itStillListsStagedFilesInARepositoryWithNoCommits(): void
    {
        file_put_contents($this->repoRoot . '/a.php', "<?php\n");
        $this->exec('git add -A');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        self::assertSame(['a.php'], array_map(
            static fn($file): string => $file->path->value(),
            $client->getChangedFiles('staged'),
        ));
    }

    /**
     * Every part of `t1..t2..HEAD` resolves, so validating the parts says yes
     * and only the range as a whole is wrong. The count is named in the
     * message because "not a range" alone does not tell the user what to drop.
     */
    #[Test]
    public function itRefusesARangeWithMoreThanTwoEndpoints(): void
    {
        $this->commit('one.php');
        $this->exec('git tag t1');
        $this->commit('two.php');
        $this->exec('git tag t2');
        $this->commit('three.php');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        try {
            $client->getChangedFiles('t1..t2..HEAD');
            self::fail('Expected a three-endpoint range to be refused.');
        } catch (GitScopeRefusedException $refusal) {
            self::assertSame(
                'Git scope "t1..t2..HEAD" is not a range: a range has exactly two endpoints, this one has 3.',
                $refusal->getMessage(),
            );
        }
    }

    /**
     * The backstop under validation, reached by damage validation cannot see:
     * `git rev-parse` resolves HEAD from the refs, while `git diff` also reads
     * the index. A broken index therefore passes the first command and fails
     * the second — which is exactly the situation the old code reported as a
     * bug in this tool.
     */
    #[Test]
    public function itRefusesAValidatedScopeWhoseDiffCommandStillFails(): void
    {
        $this->commit('a.php');
        file_put_contents($this->repoRoot . '/.git/index', 'not an index file');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        try {
            $client->getChangedFiles('HEAD');
            self::fail('Expected a failing diff to be refused.');
        } catch (GitScopeRefusedException $refusal) {
            self::assertStringStartsWith('Git scope "HEAD" could not be listed. git: ', $refusal->getMessage());
            self::assertStringContainsString('.git/index', $refusal->getMessage());
        }
    }

    /**
     * The message shape with nothing to quote. No measured git failure arrives
     * without text, so without this the "no dangling colon" branch would be a
     * claim no test makes.
     */
    #[Test]
    public function itOmitsTheQuoteWhenGitOfferedNoReason(): void
    {
        $refusal = GitScopeRefusedException::commandFailed('HEAD', '');

        self::assertSame('Git scope "HEAD" could not be listed.', $refusal->getMessage());
        self::assertDoesNotMatchRegularExpression('/:\s*$/', $refusal->getMessage());
    }

    /**
     * The end-to-end witness for both shapes: the exit code and the absence of
     * the internal-error envelope, measured through the binary rather than
     * reasoned about from the exception hierarchy. Each used to exit 1 with a
     * Symfony Process dump.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function provideScopesTheCliMustRefuseAsInput(): iterable
    {
        yield 'HEAD before the first commit' => ['HEAD', false];
        yield 'a range with three endpoints' => ['t1..t2..HEAD', true];
    }

    #[Test]
    #[DataProvider('provideScopesTheCliMustRefuseAsInput')]
    public function itExitsWithTheInputErrorCodeForARefusedScope(string $scope, bool $needsHistory): void
    {
        if ($needsHistory) {
            $this->commit('one.php');
            $this->exec('git tag t1');
            $this->commit('two.php');
            $this->exec('git tag t2');
            $this->commit('three.php');
        } else {
            file_put_contents($this->repoRoot . '/a.php', "<?php\n");
            $this->exec('git add -A');
        }

        $binary = \dirname(__DIR__, 4) . '/bin/qmx';
        $process = new Process(
            [\PHP_BINARY, $binary, 'check', '.', '--report=git:' . $scope, '--workers=0', '--no-cache'],
            $this->repoRoot,
        );
        $process->run();

        $combined = $process->getOutput() . $process->getErrorOutput();

        self::assertSame(3, $process->getExitCode(), 'a bad scope is an input refusal: ' . $combined);
        self::assertStringNotContainsString('Internal error', $combined);
        self::assertStringContainsString($scope, $combined);
    }

    private function commit(string $file): void
    {
        file_put_contents($this->repoRoot . '/' . $file, "<?php\n// " . $file . "\n");
        $this->exec('git add -A');
        $this->exec(\sprintf('git commit -m %s', escapeshellarg('add ' . $file)));
    }

    private function exec(string $command): void
    {
        $process = Process::fromShellCommandline($command, $this->repoRoot);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(\sprintf('Command failed: %s — %s', $command, $process->getErrorOutput()));
        }
    }

    private function removeRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? $this->removeRecursive($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
