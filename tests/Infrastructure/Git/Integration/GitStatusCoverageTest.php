<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Git\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Git\ChangedFile;
use Qualimetrix\Infrastructure\Git\ChangeStatus;
use Qualimetrix\Infrastructure\Git\GitClient;
use ReflectionMethod;
use RuntimeException;
use Stringable;
use Symfony\Component\Process\Process;

/**
 * What happens to a status letter the change model does not name.
 *
 * Every row git writes is either carried into the answer or reported, and
 * these cases are the second half of that sentence. The letters are reached
 * the way a user reaches them — a type change and an unresolved conflict are
 * both ordinary states of a working tree — except for the catch-all, which no
 * healthy git produces and which is therefore fed to the parser directly.
 */
#[CoversClass(GitClient::class)]
final class GitStatusCoverageTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/qmx-git-status-' . bin2hex(random_bytes(6));

        if (!mkdir($dir) || !is_dir($dir)) {
            throw new RuntimeException('Cannot create the fixture tree: ' . $dir);
        }

        $resolved = realpath($dir);

        if ($resolved === false) {
            throw new RuntimeException('Cannot resolve the fixture tree: ' . $dir);
        }

        $this->repoRoot = $resolved;
        $this->exec('git init -b main');
        $this->exec('git config user.email "test@example.com"');
        $this->exec('git config user.name "Test User"');
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->repoRoot);
    }

    /**
     * A symlink replaced by a regular file. The new entry is a PHP file the
     * run reads and reports on, so the row has to reach the answer: this is
     * the direction where the change model losing the row loses real code.
     */
    #[Test]
    public function itCarriesASymlinkReplacedByAFile(): void
    {
        file_put_contents($this->repoRoot . '/b.php', "<?php\n\nclass B {}\n");
        symlink('b.php', $this->repoRoot . '/a.php');
        $this->exec('git add -A');
        $this->exec('git commit -m initial');

        unlink($this->repoRoot . '/a.php');
        file_put_contents($this->repoRoot . '/a.php', "<?php\n\nclass A {}\n");
        $this->exec('git add -A');

        $logger = new StatusRecordingLogger();
        $changed = (new GitClient(AbsolutePath::fromString($this->repoRoot), $logger))->getChangedFiles('staged');

        self::assertCount(1, $changed);
        self::assertSame('a.php', $changed[0]->path->value());
        self::assertSame(ChangeStatus::TypeChanged, $changed[0]->status);
        self::assertFalse($changed[0]->isDeleted(), 'a type change is not a deletion');
        self::assertSame([], $logger->warnings(), 'a carried row is not a skipped one');
    }

    /**
     * The other direction. The row still reaches the answer: what the entry
     * became is discovery's question, not the git boundary's, and the boundary
     * answering it by dropping the row is what hid the change.
     */
    #[Test]
    public function itCarriesAFileReplacedBySymlink(): void
    {
        file_put_contents($this->repoRoot . '/a.php', "<?php\n\nclass A {}\n");
        file_put_contents($this->repoRoot . '/b.php', "<?php\n\nclass B {}\n");
        $this->exec('git add -A');
        $this->exec('git commit -m initial');

        unlink($this->repoRoot . '/a.php');
        symlink('b.php', $this->repoRoot . '/a.php');
        $this->exec('git add -A');

        $changed = (new GitClient(AbsolutePath::fromString($this->repoRoot)))->getChangedFiles('staged');

        self::assertSame(
            ['a.php'],
            array_map(static fn(ChangedFile $file): string => $file->path->value(), $changed),
        );
        self::assertSame(ChangeStatus::TypeChanged, $changed[0]->status);
    }

    /**
     * An unresolved conflict. The index holds several versions of the file at
     * once, so there is no one change to hand downstream — but the row is
     * refused by name, with the path, rather than dropped.
     *
     * Measured alongside: the same repository under `git:HEAD` reports the
     * file as `M`, so only the staged scope reaches this branch.
     */
    #[Test]
    public function itRefusesAnUnmergedEntryAndNamesIt(): void
    {
        file_put_contents($this->repoRoot . '/c.php', "<?php\n\n// base\n");
        $this->exec('git add -A');
        $this->exec('git commit -m initial');
        $this->exec('git checkout -b side');
        file_put_contents($this->repoRoot . '/c.php', "<?php\n\n// side\n");
        $this->exec('git commit -am side');
        $this->exec('git checkout main');
        file_put_contents($this->repoRoot . '/c.php', "<?php\n\n// main\n");
        $this->exec('git commit -am main');
        $this->execAllowingFailure('git merge side');

        $logger = new StatusRecordingLogger();
        $changed = (new GitClient(AbsolutePath::fromString($this->repoRoot), $logger))->getChangedFiles('staged');

        self::assertSame([], $changed);

        $warnings = $logger->warnings();
        self::assertCount(1, $warnings, 'a refused row must leave exactly one trace');
        self::assertStringContainsString('Skipped 1 changed file(s)', $warnings[0]);
        self::assertStringContainsString('unmerged', $warnings[0]);
        self::assertStringContainsString('c.php', $warnings[0]);
    }

    /**
     * The catch-all. `X` is documented by git as its own bug and a later git
     * may add a letter nobody has seen yet; neither can be produced on demand,
     * so the stream is handed to the parser directly. What is asserted is that
     * the letter itself reaches the reader — the only thing this build knows
     * about such a row.
     */
    #[Test]
    public function itQuotesBackAStatusLetterItDoesNotKnow(): void
    {
        $logger = new StatusRecordingLogger();
        $client = new GitClient(AbsolutePath::fromString($this->repoRoot), $logger);

        $parse = new ReflectionMethod($client, 'parseNameStatus');
        /** @var list<ChangedFile> $changed */
        $changed = $parse->invoke($client, "X\0strange.php\0");

        self::assertSame([], $changed);

        $warnings = $logger->warnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('"X"', $warnings[0]);
        self::assertStringContainsString('strange.php', $warnings[0]);
    }

    /**
     * The source name of a rename, held to the same standard as the new one.
     * The file is analysed — its new name is carriable — so the row is kept;
     * what the warning covers is the half of it that was dropped.
     */
    #[Test]
    public function itSaysWhenARenameSourceNameCannotBeCarried(): void
    {
        $source = 'old\\name.php';
        file_put_contents(
            $this->repoRoot . '/' . $source,
            "<?php\n\nclass Moved { public function work(): string { return 'unchanged body'; } }\n",
        );
        $this->exec('git add -A');
        $this->exec('git commit -m initial');
        $this->exec(\sprintf('git mv %s %s', escapeshellarg($source), escapeshellarg('New.php')));
        $this->exec('git add -A');

        $logger = new StatusRecordingLogger();
        $changed = (new GitClient(AbsolutePath::fromString($this->repoRoot), $logger))->getChangedFiles('staged');

        self::assertCount(1, $changed, 'the rename must still be reported — its new name is carriable');
        self::assertSame('New.php', $changed[0]->path->value());
        self::assertNull($changed[0]->oldPath);

        $warnings = $logger->warnings();
        self::assertCount(1, $warnings, 'the lost source name must leave exactly one trace');
        self::assertStringContainsString('Kept 1 changed file(s)', $warnings[0]);
        self::assertStringContainsString('source name', $warnings[0]);
        self::assertStringContainsString($source, $warnings[0]);
    }

    /**
     * The end-to-end witness: before this, a PHP file that had replaced a
     * symlink was invisible to `--report=git:*`, and the run reported nothing
     * and exited 0 while the same tree without `--report` reported the
     * violation. The assertion is on the finding, not on the warning, because
     * the loss here is of analysed code rather than of a diagnostic.
     */
    #[Test]
    public function itReportsAFindingInATypeChangedFileThroughTheCli(): void
    {
        file_put_contents($this->repoRoot . '/b.php', "<?php\n\nclass B {}\n");
        symlink('b.php', $this->repoRoot . '/a.php');
        $this->exec('git add -A');
        $this->exec('git commit -m initial');

        unlink($this->repoRoot . '/a.php');
        file_put_contents(
            $this->repoRoot . '/a.php',
            "<?php\n\nclass A { public function run(string \$code): void { eval(\$code); } }\n",
        );
        $this->exec('git add -A');

        $process = new Process(
            [
                \PHP_BINARY,
                \dirname(__DIR__, 4) . '/bin/qmx',
                'check', '.', '--report=git:staged', '--format=json', '--workers=0', '--no-cache',
            ],
            $this->repoRoot,
        );
        $process->run();

        $report = json_decode($process->getOutput(), true);

        self::assertIsArray($report, 'the run did not produce a JSON report: ' . $process->getErrorOutput());
        // A fatal prints a valid report envelope carrying `error` and exits 1,
        // so the shape is checked before the code.
        self::assertArrayNotHasKey('error', $report, 'the run failed instead of reporting');

        $violations = $report['violations'] ?? null;

        self::assertIsArray($violations);
        self::assertContains(
            'a.php',
            array_column($violations, 'file'),
            'the type-changed file produced no finding',
        );
        self::assertSame(2, $process->getExitCode(), 'findings in the type-changed file must fail the run');
    }

    private function exec(string $command): void
    {
        $process = Process::fromShellCommandline($command, $this->repoRoot);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(\sprintf('Command failed: %s — %s', $command, $process->getErrorOutput()));
        }
    }

    /** A conflicting merge is the point of the fixture, so its exit code is expected rather than checked. */
    private function execAllowingFailure(string $command): void
    {
        Process::fromShellCommandline($command, $this->repoRoot)->run();
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

/**
 * Keeps what the client said, so a case can assert a diagnostic exists rather
 * than assume it does.
 */
final class StatusRecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if ((string) $level === 'warning') {
            $this->warnings[] = (string) $message;
        }
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
