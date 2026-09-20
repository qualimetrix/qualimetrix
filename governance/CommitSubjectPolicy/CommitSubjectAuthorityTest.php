<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\CommitSubjectPolicy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The commit-subject rule is applied at two layers that cannot see the same
 * commits: the commit-msg hook, which judges a message before it becomes a
 * commit, and the pull-request CI job, which judges commits the hook never got
 * to see. This control keeps the two from drifting into separate rules, and
 * keeps the gating step gating.
 *
 * Deliberately structural, and deliberately not alone: what a shape cannot see
 * — a layer that runs the rule and discards its answer — is held by
 * {@see CommitSubjectVerdictTest} and {@see CommitRangeVerdictTest}, which
 * execute it. A control that walked history instead would be green for the
 * wrong reason: the PHP matrix checks out at depth 1, so the walk would judge
 * one commit, and an assertion over an empty set passes.
 *
 * @see docs/adr/0072-commit-subject-authority.md
 */
final class CommitSubjectAuthorityTest extends TestCase
{
    private const SCRIPT = 'scripts/check-commit-subject.sh';

    private const WORKFLOW = '.github/workflows/qmx.yml';

    /**
     * Pinned by branch protection, which requires this exact check context.
     * Renaming the job retires the context, and open pull requests then wait
     * forever for a check that no longer reports.
     */
    private const PROTECTED_JOB_NAME = 'Commit messages (private terms)';

    /** The states git marks while it, rather than the author, writes a subject. */
    private const SEQUENCER_MARKERS = ['MERGE_HEAD', 'REVERT_HEAD', 'CHERRY_PICK_HEAD'];

    /** Rebase is marked by a directory that lives only for the operation. */
    private const SEQUENCER_DIRECTORIES = ['rebase-merge', 'rebase-apply'];

    #[Test]
    public function itKeepsTheHookDelegatingRatherThanCarryingItsOwnRule(): void
    {
        $hook = $this->read('.githooks/commit-msg');

        // Matched as an invocation. A bare mention of the path is also satisfied
        // by the comment above the call, which would keep this green while the
        // hook judged nothing.
        self::assertMatchesRegularExpression(
            '~check-commit-subject\.sh"?\s+--message~',
            $hook,
            'the commit-msg hook must invoke the shared script, or the hook and CI become two rules',
        );
        self::assertDoesNotMatchRegularExpression(
            '/feat\|fix\|refactor/',
            $hook,
            'the hook has regrown its own copy of the pattern; one file owns it, and two copies drift apart silently',
        );
    }

    #[Test]
    public function itKeepsCiApplyingTheSameRule(): void
    {
        $workflow = $this->read(self::WORKFLOW);

        // The whole command, matched exactly. A regex over fragments stayed
        // green while `|| true` was appended, while the range was replaced by
        // `HEAD..HEAD`, and while the mode was changed — all three measured.
        // The range now belongs to the script, so those became mutations of
        // code the behavioural controls execute.
        // Whole line, not containment: `... --pull-request || true` contains the
        // expected text and turns the gate off. Measured — it passed a
        // containment assertion.
        self::assertContains(
            'run: bash ' . self::SCRIPT . ' --pull-request',
            $this->trimmedLines($workflow),
            'the gating step must be exactly this invocation; anything appended to it, or a range'
            . ' spelled in the workflow instead of the script, sits where no control can reach it',
        );
        self::assertStringContainsString(
            'name: ' . self::PROTECTED_JOB_NAME,
            $workflow,
            'the gating job was renamed; branch protection still requires "' . self::PROTECTED_JOB_NAME
            . '", so the rename silently stops the rule from gating anything',
        );
    }

    /**
     * The step's guard, its severity and the workflow's own trigger all turn
     * the rule off without touching a line that names it.
     */
    #[Test]
    public function itKeepsTheCiStepGatingRatherThanMerelyRunning(): void
    {
        $workflow = $this->read(self::WORKFLOW);
        $job = $this->gatingJob($workflow);

        // Whole line again: `== 'pull_request' && false` contains it and never
        // runs. Measured.
        self::assertContains(
            "if: github.event_name == 'pull_request'",
            $this->trimmedLines($job),
            'the subject check runs only on pull requests; a push range on main opens with the commit'
            . ' the merge button just wrote, and judging it would redden main after every merge',
        );

        // Read over the whole job, not from the step name onwards: the switch
        // that stops a job failing can be set on the job itself, above every
        // step, where a window opening at the step cannot see it.
        self::assertStringNotContainsString(
            'continue-on-error',
            $job,
            'a job or step that cannot fail is not a control; it would report the refusal and let the pull request merge',
        );

        // The rule reaches nothing if the workflow never runs on the event the
        // gating step waits for.
        self::assertStringContainsString(
            'pull_request:',
            $this->triggerSection($workflow),
            'the workflow must still trigger on pull_request, or the only layer covering the'
            . " sequencer's clean path never executes",
        );
    }

    #[Test]
    public function itKeepsEverySequencerStateNamedInTheExemption(): void
    {
        $script = $this->read(self::SCRIPT);

        foreach (self::SEQUENCER_MARKERS as $marker) {
            self::assertStringContainsString(
                $marker,
                $script,
                $marker . ' is no longer exempt; the subject git writes for that operation would then be judged'
                . ' on the conflicted path and unseen on the clean one — the same content, two verdicts',
            );
        }

        foreach (self::SEQUENCER_DIRECTORIES as $directory) {
            self::assertStringContainsString(
                $directory,
                $script,
                $directory . ' is no longer exempt; a conflicted rebase finished with `git commit` would be'
                . ' refused while `git rebase --continue` lands the same subject unseen',
            );
        }

        // Measured: REBASE_HEAD survives the rebase that wrote it, so keying on
        // it silences the hook for every commit afterwards. Read from the
        // declarations alone — the header explains the trap by name, and a
        // whole-file search would be answered by that explanation.
        self::assertSame(
            [],
            preg_grep('~^SEQUENCER_(MARKERS|DIRECTORIES)=.*REBASE_HEAD~m', explode("\n", $script)),
            'REBASE_HEAD outlives its operation; exempting on it disables the hook permanently after one rebase',
        );
    }

    #[Test]
    public function itKeepsBothLayersModesDeclared(): void
    {
        $script = $this->read(self::SCRIPT);

        self::assertStringContainsString('--message', $script, 'the hook calls this mode');
        self::assertStringContainsString('--pull-request', $script, 'CI calls this mode');
        self::assertFileIsReadable(
            \dirname(__DIR__, 2) . '/' . self::SCRIPT,
            'the script both layers invoke must exist and be readable',
        );
    }

    /**
     * @return list<string>
     */
    private function trimmedLines(string $text): array
    {
        return array_map('trim', explode("\n", $text));
    }

    /** The gating job in full, from its key to the next top-level job key. */
    private function gatingJob(string $workflow): string
    {
        $start = strpos($workflow, "\n  commit-messages:\n");
        self::assertNotFalse($start, 'the gating job is addressed by key; it has been renamed or removed');

        $found = preg_match('~\n  [a-z][a-z0-9-]*:\n~', $workflow, $matches, \PREG_OFFSET_CAPTURE, $start + 1);
        $next = $found === 1 ? $matches[0][1] : \strlen($workflow);

        return substr($workflow, $start, $next - $start);
    }

    /** Everything between the `on:` key and the first job. */
    private function triggerSection(string $workflow): string
    {
        $start = strpos($workflow, "\non:\n");
        self::assertNotFalse($start, 'the workflow declares no triggers at all');

        $end = strpos($workflow, "\njobs:\n", $start);
        self::assertNotFalse($end, 'the workflow declares no jobs at all');

        return substr($workflow, $start, $end - $start);
    }

    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path, $relativePath . ' is an address this control reads; a missing file must fail loudly, not vacuously');

        return (string) file_get_contents($path);
    }
}
