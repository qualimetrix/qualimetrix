<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\CommitSubjectPolicy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

/**
 * Runs the real `.githooks/commit-msg` and reads its exit code.
 *
 * Its sibling {@see CommitSubjectAuthorityTest} asserts the hook's shape, and a
 * shape control cannot see the one mutation that matters most: a hook that
 * calls the script and then discards its verdict. That mutation leaves every
 * structural assertion green while nothing is refused at all. Measured — it
 * survived nine other planted breakages.
 *
 * The verdict is taken in a throwaway repository, because the marker files that
 * decide the exemption are per-repository state and planting them in this one
 * would derail whatever the developer is in the middle of.
 *
 * @see docs/adr/0072-commit-subject-authority.md
 */
final class CommitSubjectVerdictTest extends TestCase
{
    private string $scratch = '';

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

        // Clock-derived identifiers collide between processes; see
        // ScratchPathsCarryRealEntropyTest.
        $this->scratch = sys_get_temp_dir() . '/qmx-commit-subject-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0o777, true), 'could not create the scratch repository');
        self::assertSame(0, self::execute(['git', 'init', '-q'], $this->scratch)[0]);
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== '' && is_dir($this->scratch)) {
            self::execute(['rm', '-rf', $this->scratch], sys_get_temp_dir());
        }
    }

    /**
     * The first three rows are the point of the whole control: the same subject,
     * the one git writes for a merge, is refused when the author typed it and
     * accepted when git is mid-merge. Nothing here matches the word "Merge".
     *
     * @return iterable<string, array{string, string|null, bool}>
     */
    public static function provideSubjects(): iterable
    {
        yield 'merge subject typed by hand is refused' => ["Merge the two config readers", null, false];
        yield 'merge subject while git is merging is exempt' => ["Merge branch 'feat'", 'MERGE_HEAD', true];
        yield 'revert subject while git is reverting is exempt' => ['Revert "feat: a thing"', 'REVERT_HEAD', true];
        yield 'cherry-pick in progress is exempt' => ['Anything at all', 'CHERRY_PICK_HEAD', true];
        yield 'non-conventional subject is refused' => ['Ugly subject', null, false];
        yield 'conventional subject is accepted' => ['feat: add a collector', null, true];
        yield 'conventional subject with a scope is accepted' => ['fix(parser): handle it', null, true];
        yield 'a type that is not in the list is refused' => ['wip: halfway there', null, false];
        yield 'a type without the colon is refused' => ['feat add a collector', null, false];

        // git composes these and never opens an editor on the clean path, so
        // the wrapped subject is the only thing there is to judge.
        yield 'a revert of a conforming commit is accepted' => ['Revert "feat: add a collector"', null, true];
        yield 'a revert of a non-conforming commit is refused' => ['Revert "Ugly original"', null, false];
        yield 'revert-shaped prose is refused' => ['Revert something entirely', null, false];
        // What git writes when it reverts a revert, since 2.36.
        yield 'a reapply of a conforming commit is accepted' => ['Reapply "feat: add a collector"', null, true];
        yield 'a reapply of a non-conforming commit is refused' => ['Reapply "Ugly original"', null, false];

        // Nesting a regex cannot express: the prefixes are stripped to a fixed
        // point, so depth is not a cliff the rule falls off.
        yield 'a revert of a reapply is accepted' => ['Revert "Reapply "feat: add a collector""', null, true];
        yield 'a revert of a reapply of a bad subject is refused' => ['Revert "Reapply "Ugly""', null, false];

        // git composes these too, and `rebase --autosquash` dissolves them.
        yield 'a fixup is accepted when its target conforms' => ['fixup! feat: add a collector', null, true];
        yield 'a squash is accepted when its target conforms' => ['squash! fix(parser): handle it', null, true];
        yield 'a fixup of a non-conforming subject is refused' => ['fixup! Ugly original', null, false];

        // The Conventional Commits breaking marker; `Breaking` is a live
        // CHANGELOG category in this repository.
        yield 'a breaking-change marker is accepted' => ['feat!: drop the old contract', null, true];
        yield 'a breaking-change marker with a scope is accepted' => ['feat(rules)!: drop it', null, true];
    }

    /**
     * Every type the script offers must be a type it accepts. The two lists
     * were separate strings once, and dropping `perf` from the pattern while
     * the help text still advertised it went unnoticed.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideDeclaredTypes(): iterable
    {
        foreach (['feat', 'fix', 'refactor', 'test', 'docs', 'chore', 'style', 'perf', 'ci', 'build'] as $type) {
            yield $type => [$type];
        }
    }

    #[Test]
    #[DataProvider('provideDeclaredTypes')]
    public function itAcceptsEveryTypeItAdvertises(string $type): void
    {
        $messageFile = $this->scratch . '/message.txt';
        self::assertNotFalse(file_put_contents($messageFile, $type . ': a subject' . "\n"));

        [$exitCode, $output] = self::execute(
            ['bash', \dirname(__DIR__, 2) . '/.githooks/commit-msg', $messageFile],
            $this->scratch,
        );

        self::assertSame(0, $exitCode, 'the rule offers "' . $type . '" but refuses it: ' . $output);
    }

    /**
     * The markers live in the per-worktree git directory, which is not `.git`
     * in a linked worktree — and this repository is developed in linked
     * worktrees. A resolution that assumed `.git` would exempt nothing there,
     * putting the conflicted path back to refusing what the clean path lands.
     */
    #[Test]
    public function itFindsTheMarkerFromInsideALinkedWorktree(): void
    {
        self::assertSame(0, self::execute(['git', 'config', 'user.email', 'test@example.com'], $this->scratch)[0]);
        self::assertSame(0, self::execute(['git', 'config', 'user.name', 'Test'], $this->scratch)[0]);
        self::assertNotFalse(file_put_contents($this->scratch . '/f.txt', "a\n"));
        self::assertSame(0, self::execute(['git', 'add', '.'], $this->scratch)[0]);
        self::assertSame(0, self::execute(['git', 'commit', '-q', '--no-verify', '-m', 'chore: base'], $this->scratch)[0]);

        $linked = $this->scratch . '-linked';
        self::assertSame(
            0,
            self::execute(['git', 'worktree', 'add', '-q', '-b', 'side', $linked], $this->scratch)[0],
            'could not create the linked worktree',
        );

        // Planted where this worktree's own git directory is, which is where
        // git itself would write it mid-merge.
        [$code, $gitDir] = self::execute(['git', 'rev-parse', '--absolute-git-dir'], $linked);
        self::assertSame(0, $code);
        $gitDir = trim($gitDir);
        self::assertStringNotContainsString($linked . '/.git/MERGE_HEAD', $gitDir . '/MERGE_HEAD');
        self::assertNotFalse(file_put_contents($gitDir . '/MERGE_HEAD', "\n"));

        $messageFile = $linked . '/message.txt';
        self::assertNotFalse(file_put_contents($messageFile, "Merge branch 'side'\n"));

        [$exitCode, $output] = self::execute(
            ['bash', \dirname(__DIR__, 2) . '/.githooks/commit-msg', $messageFile],
            $linked,
        );

        self::execute(['rm', '-rf', $linked], sys_get_temp_dir());

        self::assertSame(0, $exitCode, 'the exemption must find the marker in a linked worktree: ' . $output);
    }

    #[Test]
    #[DataProvider('provideSubjects')]
    public function itDecidesBySequencerMarkerRatherThanBySubjectWording(
        string $subject,
        ?string $marker,
        bool $expectedToPass,
    ): void {
        $messageFile = $this->scratch . '/message.txt';
        self::assertNotFalse(file_put_contents($messageFile, $subject . "\n"));

        if ($marker !== null) {
            self::assertNotFalse(file_put_contents($this->scratch . '/.git/' . $marker, "\n"));
        }

        // An absolute path, because the hook's private-term guard resolves the
        // repository root and changes directory before reading this file.
        [$exitCode, $output] = self::execute(
            ['bash', \dirname(__DIR__, 2) . '/.githooks/commit-msg', $messageFile],
            $this->scratch,
        );

        self::assertSame(
            $expectedToPass,
            $exitCode === 0,
            \sprintf(
                'subject %s with %s: expected the hook to %s, exit code was %d%s',
                var_export($subject, true),
                $marker ?? 'no sequencer marker',
                $expectedToPass ? 'accept' : 'refuse',
                $exitCode,
                "\n" . $output,
            ),
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
