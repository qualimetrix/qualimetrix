<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\CommitSubjectPolicy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

/**
 * The CI half of the rule, executed rather than described.
 *
 * This is the layer that covers what no hook can reach — `--no-verify`, an
 * uninstalled hook, and the clean path of `git revert` and `git cherry-pick`.
 * Its two dangerous answers are both green ones: a range it silently declines
 * to resolve, and an empty range reported as if everything conformed.
 *
 * @see docs/adr/0072-commit-subject-authority.md
 */
final class CommitRangeVerdictTest extends TestCase
{
    private string $repository = '';

    public static function setUpBeforeClass(): void
    {
        // By path, not by autoloader: an isolated scratch project symlinks
        // `vendor/`, so an autoloaded class resolves back to this tree instead
        // of the copy under test.
        require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';
    }

    protected function setUp(): void
    {
        if (self::execute(['git', '--version'], sys_get_temp_dir())[0] !== 0) {
            self::markTestSkipped('git is not available');
        }

        $this->repository = sys_get_temp_dir() . '/qmx-commit-range-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->repository, 0o777, true));

        foreach ([
            ['git', 'init', '-q', '-b', 'main'],
            ['git', 'config', 'user.email', 'test@example.com'],
            ['git', 'config', 'user.name', 'Test'],
            ['git', 'commit', '-q', '--allow-empty', '--no-verify', '-m', 'chore: base'],
        ] as $command) {
            self::assertSame(0, self::execute($command, $this->repository)[0], implode(' ', $command));
        }
    }

    protected function tearDown(): void
    {
        if ($this->repository !== '' && is_dir($this->repository)) {
            self::execute(['rm', '-rf', $this->repository], sys_get_temp_dir());
        }
    }

    #[Test]
    public function itRefusesARangeItCannotResolveRatherThanPassing(): void
    {
        [$exitCode, $output] = $this->judge('0000000000000000000000000000000000000000..HEAD');

        self::assertNotSame(
            0,
            $exitCode,
            'an unresolvable range must be refused; passing on it is the silent skip — '
            . "green because nothing was looked at\n" . $output,
        );
    }

    #[Test]
    public function itRefusesABareRefThatWouldJudgeTheWholeHistory(): void
    {
        [$exitCode, $output] = $this->judge('HEAD');

        self::assertSame(
            2,
            $exitCode,
            "a lost '..' would sweep every reachable subject, including the ones the merge button wrote\n" . $output,
        );
    }

    #[Test]
    public function itDoesNotReportAnEmptyRangeAsConforming(): void
    {
        [$exitCode, $output] = $this->judge('HEAD..HEAD');

        self::assertSame(0, $exitCode, $output);
        self::assertStringNotContainsString(
            'conform',
            $output,
            'an empty range judged nothing, and must not be worded as though everything passed',
        );
    }

    #[Test]
    public function itRefusesANonConformingCommitInTheRange(): void
    {
        $this->commit('Ugly subject nobody judged');

        [$exitCode, $output] = $this->judge('HEAD~1..HEAD');

        self::assertNotSame(0, $exitCode, $output);
        self::assertStringContainsString('Invalid commit message format', $output);
    }

    #[Test]
    public function itAcceptsAConformingCommitInTheRange(): void
    {
        $this->commit('feat: add a collector');

        [$exitCode, $output] = $this->judge('HEAD~1..HEAD');

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * Every commit in the range, not just the one at an end of it. With a
     * single judged commit per case, `git rev-list | tail -1` passed the whole
     * suite — measured.
     */
    #[Test]
    public function itJudgesEveryCommitInTheRangeRatherThanAnEndOfIt(): void
    {
        $base = $this->revision('HEAD');
        $this->commit('Ugly subject in the middle');
        $this->commit('feat: a conforming one after it');

        [$exitCode, $output] = $this->judge($base . '..HEAD');

        self::assertNotSame(0, $exitCode, "the offender is not last in the range\n" . $output);
        self::assertStringContainsString(
            'Ugly subject in the middle',
            $output,
            'the refusal must name the commit it refused',
        );
    }

    #[Test]
    public function itReportsHowManySubjectsItJudged(): void
    {
        $base = $this->revision('HEAD');
        $this->commit('feat: one');
        $this->commit('fix: two');

        [$exitCode, $output] = $this->judge($base . '..HEAD');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString(
            '2 commit subject(s) conform',
            $output,
            'the count is the only thing separating "judged everything" from "judged one and stopped"',
        );
    }

    #[Test]
    public function itRefusesARangeWithAnEmptyEndpoint(): void
    {
        foreach (['..HEAD', 'HEAD..'] as $range) {
            [$exitCode, $output] = $this->judge($range);

            self::assertSame(
                2,
                $exitCode,
                "git would default the empty side to HEAD and judge nothing; '{$range}' must be refused\n" . $output,
            );
        }
    }

    /**
     * The merge subject GitHub writes is the one this repository's history is
     * full of. Judging it would redden every pull request that merges main in.
     */
    #[Test]
    public function itSkipsMergeCommitsByParentCountRatherThanBySubject(): void
    {
        $base = $this->revision('HEAD');
        self::assertSame(0, self::execute(['git', 'checkout', '-q', '-b', 'side'], $this->repository)[0]);
        $this->commit('feat: work on the side');
        self::assertSame(0, self::execute(['git', 'checkout', '-q', 'main'], $this->repository)[0]);
        self::assertSame(
            0,
            self::execute(
                ['git', 'merge', '--no-ff', '--no-verify', '-m', "Merge pull request #1 from qualimetrix/side", 'side'],
                $this->repository,
            )[0],
        );

        [$exitCode, $output] = $this->judge($base . '..HEAD');

        self::assertSame(
            0,
            $exitCode,
            "the merge commit must be excluded by parent count, and the branch commit still judged\n" . $output,
        );
    }

    private function commit(string $subject): void
    {
        self::assertSame(
            0,
            self::execute(['git', 'commit', '-q', '--allow-empty', '--no-verify', '-m', $subject], $this->repository)[0],
            'could not plant the commit',
        );
    }

    private function revision(string $ref): string
    {
        [$code, $output] = self::execute(['git', 'rev-parse', $ref], $this->repository);
        self::assertSame(0, $code);

        return trim($output);
    }

    /** @return array{int, string} */
    private function judge(string $range): array
    {
        return self::execute(
            ['bash', \dirname(__DIR__, 2) . '/scripts/check-commit-subject.sh', '--commits', $range],
            $this->repository,
        );
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string}
     */
    private static function execute(array $command, string $cwd): array
    {
        $result = ChildProcess::run($command, $cwd);

        return [$result['exitCode'], $result['stdout'] . $result['stderr']];
    }
}
