<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SubprocessDrain;

use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

/**
 * Every occurrence of a subprocess-spawning function's name in a PHP file this
 * repository ships or runs must be either inside
 * `scripts/subprocess/ChildProcess.php` — the one deadlock-free way to start a
 * child and capture what it writes — or carry an entry in `ENTRIES` naming its
 * line and giving its reason. Everything else is refused.
 *
 * The defect being made undeclarable is a hang, not a red: a parent that reads
 * stdout to EOF and stderr afterwards blocks forever once the child writes more
 * than the pipe buffer to the stream read second, because the child blocks
 * mid-write, never exits, and never closes the stream the parent is reading.
 * In CI that burns the job timeout and names no cause.
 *
 * ## Two names, and why not the other five
 *
 * `NEEDLES` holds `proc_open` and `popen`. The other channels — `exec`,
 * `shell_exec`, `system`, `passthru` and backticks — are deliberately absent,
 * and the line between them is the *direction* of the stream the caller is left
 * holding, not how many streams there are:
 *
 * - Those five hand the caller no live stream at all. PHP reads the child's
 *   output to EOF itself, or passes it straight through to the parent's own
 *   stdout. There is nothing left unserviced, so the shape cannot occur.
 * - `popen` hands over exactly one, and its mode decides the direction. Opened
 *   for reading, leaving it unread is survivable — measured on this platform,
 *   closing an unread read pipe gives the child `EPIPE` and it dies at once.
 *   Opened for **writing** it carries this defect in full: measured, a parent
 *   that wrote 1 MB to a child that never reads stdin sat blocked inside
 *   `fwrite()` for the child's entire 30 s life and was released only by the
 *   child's exit turning the write into `EPIPE`. A child that never exits
 *   blocks it forever.
 *
 * The gate does not read the mode argument, for the same reason it parses no
 * descriptor spec: the deciding text can be a variable, a constant or a
 * concatenation. Both names are refused outright, and a site that genuinely
 * needs one takes an entry.
 *
 * ## The gate counts nothing and parses no descriptor spec
 *
 * An earlier mechanism judged how many `['pipe'` entries a descriptor spec
 * declares. Three counterexamples killed it and none was repairable:
 * `array_fill_keys([1, 2], ['pipe', 'w'])` is one occurrence and two pipes;
 * `["pipe", "w"]` in double quotes matches nothing and no style rule forbids
 * it; and `[1 => ['pipe','w'], 2 => ['pty']]` is one `['pipe'` and two blocking
 * streams — measured on this platform, an unread pty blocked the child exactly
 * as a pipe would, leaving the parent stuck at 5 s. So the rule counts nothing:
 * it is fail-closed, and a shape nobody has thought of yet is refused by
 * default rather than permitted by an argument nobody has checked.
 *
 * ## The decision is textual; the tokenizer only finds comments
 *
 * The gate is a case-sensitive substring match. Two measurements say why it is
 * not a token-type check:
 *
 * - `\proc_open(` tokenizes as a single `T_NAME_FULLY_QUALIFIED`, not as
 *   `T_STRING` followed by `(`. A gate built on the latter would not see it,
 *   and the leading backslash is the house style for global functions here —
 *   `\sprintf(` occurs 1,716 times in tracked PHP. The qualified spelling is
 *   the likely one, not the exotic one.
 * - A token scan finds 29 calls where the hand enumeration found 30 sites. The
 *   missing one is the call inside `ProcessHandle`'s nowdoc, which is a string
 *   literal in that file and a real call in the `php -r` child it is handed to.
 *   A detector that exempted literals would have exempted precisely the live
 *   one.
 *
 * Literals therefore need entries — today the security rule's data and its
 * fixtures, a skip message naming the descriptor kind, and the finding-gate's
 * nowdoc. What that buys is the guarantee that embedded source cannot hide. The
 * tokenizer excuses exactly one kind of occurrence — one inside a comment token,
 * because a docblock cannot execute — and otherwise only labels the refusal.
 *
 * That exemption is what lets this docblock spell both names in full. The
 * constant below spells each in two halves instead, because a string literal is
 * not excused: this file is in the population and holds no entry of its own, so
 * a real call written here would be refused like any other.
 *
 * ## An entry permits a line, not a shape
 *
 * The descriptor spec and the read discipline on an entered line can change
 * without this control noticing: the occurrence is still there and the entry
 * still matches. A second occurrence added to the *same* line is invisible for
 * the same reason — including one that spells the *other* name, since an entry
 * is keyed by line and not by which needle matched. Verifying the shape means
 * parsing descriptor specs, which is the mechanism the counterexamples above
 * killed. What holds the line instead is that entries are few, anchored at
 * `file:line`, and reviewed — and that a new call on a new line in an
 * already-entered file is refused by default.
 *
 * The price of the line anchor is paid by edits that have nothing to do with
 * subprocesses. Inserting anything *above* an entered occurrence moves it, and
 * the entry then refuses as stale until someone re-anchors it — this has already
 * happened once, when two test cases were added above an entered call and the
 * whole group went red for a change that touched no read discipline. That red is
 * the mechanism working, not a defect in it: the same anchor is what makes a
 * *second* call added to an already-entered file refuse by default, which a
 * file-level entry could never do. Re-take the number from this control's own
 * refusal message, which reports where the occurrence sits now, rather than
 * counting lines by hand.
 *
 * Two gaps are named rather than covered. A dynamically assembled name
 * (`$f = 'proc_' . 'open'; $f(…)`) is invisible; the enumeration swept for one
 * and found none. So is a differently-cased spelling: PHP resolves function
 * names case-insensitively, the gate does not, and nothing in the tree spells
 * either name any other way today.
 *
 * The population is {@see PhpFilePopulation}: every PHP file the repository
 * ships or runs, untracked-but-not-ignored files included.
 */
final class SubprocessReadsAreDrainedConcurrentlyTest extends TestCase
{
    /**
     * Spelled in halves because this file is in the population and holds no
     * entry of its own.
     *
     * @var list<string>
     */
    private const NEEDLES = ['proc_' . 'open', 'p' . 'open'];

    private const MODULE_PATH = 'scripts/subprocess/ChildProcess.php';

    /**
     * Occurrences permitted by name, each anchored at the line it sits on and
     * each giving the reason that line keeps its own call. Removing one refuses
     * its occurrence; leaving one behind after its line stops matching refuses
     * as stale, so the list cannot decay into permission for whatever moves
     * into that path later.
     *
     * The enclosing function is named in the reason for the reader's sake and
     * is deliberately not verified: matching it mechanically would be a second
     * gate nobody designed, and the line anchor already does the work.
     *
     * @var array<string, string>
     */
    private const ENTRIES = [
        'scripts/finding-gate-controls/Shell.php:89' => 'The finding-gate controls supervisor: a global '
            . '`stream_select` across every live child, plus process-group isolation, descendant termination and a '
            . 'bounded parallel scheduler. That is supervision layered on the read discipline, a different subject '
            . 'from flat capture, and its behaviour is what `composer gate:controls` measures.',

        'scripts/finding-gate/ProcessHandle.php:61' => 'The finding-gate worker handle: non-blocking reads under a '
            . 'bounded scheduler, with process-group isolation and launcher-disappearance detection. Same '
            . 'supervision subject as the line above.',

        'scripts/finding-gate/ProcessHandle.php:202' => '`groupedCommand()`: source text inside a nowdoc handed to '
            . '`php -r`, so this is a literal here and a real call in the child. The child opens no pipe at all — '
            . 'its descriptors are the STDIN/STDOUT/STDERR constants — so it carries none of this hazard.',

        'scripts/finding-gate/SelfTest.php:2916' => 'The interrupt self-test needs the child back *alive*, with its '
            . 'stdout handle, after reading two announcement lines while the child runs `sleep 30`; the module '
            . 'waits for exit. Stderr goes to a file, leaving stdout the only blocking stream, and the bespoke loop '
            . 'keeps its deadline and SIGKILL backstop.',

        'scripts/subprocess/tests/ChildProcessDrainTest.php:476' => 'The module is one file, so its test directory '
            . 'is not inside it. `runWithDeadline()` bounds the code under test by wall clock without ever reading '
            . 'a pipe from it: both of the child\'s streams go to files and only `proc_get_status()` is polled, '
            . 'because a supervisor that read a pipe here could deadlock the same way the code under test might.',

        'src/Infrastructure/Git/GitRepositoryLocator.php:59' => 'Production code, which may not import a '
            . 'development namespace, and the module lives outside `src/` deliberately. The deadlock is removed by '
            . 'construction instead: `git rev-parse` gets no stdin pipe and its stderr goes to a file, so stdout is '
            . 'the only blocking stream.',

        'src/Analysis/Evidence/Security/CommandInjectionDetector.php:27' => 'Rule data: the name of one of the '
            . 'functions the command-injection rule detects.',

        'src/Analysis/Evidence/Security/CommandInjectionDetector.php:28' => 'Rule data, the single-stream spawner: '
            . 'the next name in the same list.',

        'tests/Analysis/Evidence/Security/Unit/CommandInjectionDetectorTest.php:59' => 'The data-provider case for '
            . 'that rule, naming the detected function twice on one line — as the case label and as the case value.',

        'tests/Analysis/Evidence/Security/Unit/CommandInjectionDetectorTest.php:60' => 'The same shape for the '
            . 'single-stream spawner: the case label and the case value on one line.',

        'tests/Analysis/Evidence/Security/Unit/SecurityPatternVisitorTest.php:351' => 'The data-provider case label '
            . 'for the command-injection visitor.',

        'tests/Analysis/Evidence/Security/Unit/SecurityPatternVisitorTest.php:352' => 'PHP source inside the '
            . 'fixture string that case hands to the parser — embedded source, never executed by this process.',

        'tests/Analysis/Evidence/Security/Unit/SecurityPatternVisitorTest.php:356' => 'The case label for the same '
            . 'visitor, naming the single-stream spawner.',

        'tests/Analysis/Evidence/Security/Unit/SecurityPatternVisitorTest.php:357' => 'PHP source inside that '
            . 'case\'s fixture string — embedded source, never executed by this process.',

        'tests/Analysis/Policy/Baseline/Integration/BaselineChannelRenamerTest.php:636' => 'The parent holds the '
            . 'lock the child blocks on, so the window opens before the parent is free to read anything and no read '
            . 'discipline closes it. Stderr goes to a file the failure message reads back, leaving stdout the only '
            . 'blocking stream.',

        'tests/Infrastructure/Console/Functional/ErrorStreamPseudoTerminalTest.php:31' => 'A skip message naming '
            . 'the descriptor kind this PHP build could not allocate.',

        'tests/Infrastructure/Console/Support/PseudoTerminalRun.php:40' => '`isSupported()` asks whether this build '
            . 'can allocate a pseudo-terminal at all, so it must open one. Stdin and stdout go to `/dev/null` and '
            . 'the single pty is read to EOF rather than closed unread.',

        'tests/Infrastructure/Console/Support/PseudoTerminalRun.php:85' => 'A pty master reports EIO where a pipe '
            . 'reports EOF, which is a different read discipline rather than a caller of this one. Both streams are '
            . 'drained from one `stream_select` loop.',
    ];

    /** @var list<array{path: string, line: int, name: string, kind: string}>|null */
    private static ?array $occurrences = null;

    public static function tearDownAfterClass(): void
    {
        self::$occurrences = null;
        PhpFilePopulation::forget();
    }

    /**
     * An allowlist control over a population that came back empty, or over one
     * a needle no longer reaches, is green for the wrong reason and says so to
     * nobody. These are the cheapest evidence that the scan is looking where it
     * claims: the module's own call is seen, this control's own file is in the
     * population, the extension-less binary is too, and *each* needle finds
     * something.
     */
    #[Test]
    public function itScansAPopulationThatReachesEveryKindOfPhpFile(): void
    {
        $population = PhpFilePopulation::paths();

        self::assertContains(
            self::MODULE_PATH,
            $population,
            'The module itself is missing from the scanned population.',
        );
        self::assertContains(
            'governance/SubprocessDrain/SubprocessReadsAreDrainedConcurrentlyTest.php',
            $population,
            'This control does not scan its own group, so a call planted here would pass.',
        );
        self::assertContains(
            'bin/qmx',
            $population,
            'A PHP file without a .php extension is outside the population, which is what a shebang lookup exists '
            . 'to prevent.',
        );

        $inModule = array_values(array_filter(
            self::occurrences(),
            static fn(array $occurrence): bool => $occurrence['path'] === self::MODULE_PATH,
        ));

        self::assertNotSame(
            [],
            $inModule,
            'The scan no longer finds the module\'s own call. Either the match or the population is broken, and '
            . 'every assertion below would pass over nothing.',
        );

        foreach (self::NEEDLES as $needle) {
            $found = array_values(array_filter(
                self::occurrences(),
                static fn(array $occurrence): bool => $occurrence['name'] === $needle,
            ));

            self::assertNotSame(
                [],
                $found,
                'The needle "' . $needle . '" matches nothing in the whole tree. Either it is misspelled — in which '
                . 'case every call it should refuse passes in silence — or the last occurrence of that name has '
                . 'left the tree. The single-stream spawner is anchored only by the command-injection rule\'s data '
                . 'and its fixtures; if those are gone, re-anchor this witness deliberately rather than deleting '
                . 'the needle.',
            );
        }
    }

    #[Test]
    public function itRefusesEveryUndeclaredOccurrence(): void
    {
        $undeclared = [];

        foreach (self::occurrences() as $occurrence) {
            if ($occurrence['path'] === self::MODULE_PATH) {
                continue;
            }

            $anchor = $occurrence['path'] . ':' . $occurrence['line'];

            if (\array_key_exists($anchor, self::ENTRIES)) {
                continue;
            }

            $undeclared[] = $anchor . ' — ' . $occurrence['name'] . ' (' . $occurrence['kind'] . ')';
        }

        self::assertSame(
            [],
            $undeclared,
            'Undeclared subprocess spawn. Every occurrence of either name outside ' . self::MODULE_PATH
            . ' needs an entry in ' . self::class . '::ENTRIES anchored at its line and giving its reason. '
            . 'Use ' . ChildProcess::class . '::run() unless the site genuinely cannot, and say why in the entry.',
        );
    }

    /**
     * An entry that no longer anchors anything is the inert-suppression shape:
     * it outlives its subject and turns into permission for whatever moves into
     * that path later.
     */
    #[Test]
    public function itRefusesAnEntryThatAnchorsNoOccurrence(): void
    {
        $anchors = [];

        foreach (self::occurrences() as $occurrence) {
            $anchors[$occurrence['path'] . ':' . $occurrence['line']] = true;
        }

        $stale = [];

        foreach (array_keys(self::ENTRIES) as $anchor) {
            if (isset($anchors[$anchor])) {
                continue;
            }

            $stale[] = $anchor . ' — ' . self::whereItIsNow($anchor);
        }

        self::assertSame(
            [],
            $stale,
            'Stale entry: the declared line carries no occurrence any more. Re-anchor it on the line that does, or '
            . 'delete it if the call is gone.',
        );
    }

    #[Test]
    public function itRefusesAnEntryThatIsRedundantOrUnexplained(): void
    {
        $refused = [];

        foreach (self::ENTRIES as $anchor => $reason) {
            if (str_starts_with($anchor, self::MODULE_PATH . ':')) {
                $refused[] = $anchor . ' — the module is exempt as a whole, so this entry does no work.';
            }

            if (trim($reason) === '') {
                $refused[] = $anchor . ' — an entry without a reason is permission nobody granted.';
            }
        }

        self::assertSame([], $refused, 'An entry that cannot do the work an entry exists for.');
    }

    private static function whereItIsNow(string $anchor): string
    {
        $path = substr($anchor, 0, (int) strrpos($anchor, ':'));
        $lines = [];

        foreach (self::occurrences() as $occurrence) {
            if ($occurrence['path'] === $path) {
                $lines[] = (string) $occurrence['line'];
            }
        }

        return $lines === []
            ? 'that file carries no occurrence at all now'
            : 'the file now carries one at line ' . implode(', ', $lines);
    }

    /** @return list<array{path: string, line: int, name: string, kind: string}> */
    private static function occurrences(): array
    {
        if (self::$occurrences !== null) {
            return self::$occurrences;
        }

        $found = [];

        foreach (PhpFilePopulation::paths() as $path) {
            $contents = (string) file_get_contents(PhpFilePopulation::root() . '/' . $path);
            $tokens = null;

            foreach (self::NEEDLES as $needle) {
                if (!str_contains($contents, $needle)) {
                    continue;
                }

                $tokens ??= PhpToken::tokenize($contents);
                $offset = 0;

                while (($at = strpos($contents, $needle, $offset)) !== false) {
                    $offset = $at + \strlen($needle);
                    $token = self::tokenAt($tokens, $at);

                    if ($token !== null && $token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                        continue;
                    }

                    $found[] = [
                        'path' => $path,
                        'line' => substr_count($contents, "\n", 0, $at) + 1,
                        'name' => $needle,
                        'kind' => self::kindOf($token),
                    ];
                }
            }
        }

        return self::$occurrences = $found;
    }

    /**
     * The token's own `line` is the line the token *starts* on, which for a
     * nowdoc is several lines above the occurrence inside it. The line is
     * therefore counted from the byte offset, and the token is consulted only
     * for what kind of thing the occurrence sits in.
     *
     * @param array<PhpToken> $tokens
     */
    private static function tokenAt(array $tokens, int $offset): ?PhpToken
    {
        foreach ($tokens as $token) {
            if ($token->pos > $offset) {
                return null;
            }

            if ($offset < $token->pos + \strlen($token->text)) {
                return $token;
            }
        }

        return null;
    }

    private static function kindOf(?PhpToken $token): string
    {
        if ($token === null) {
            return 'unclassified';
        }

        if ($token->is([\T_STRING, \T_NAME_FULLY_QUALIFIED, \T_NAME_QUALIFIED])) {
            return 'a call';
        }

        if ($token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE, \T_INLINE_HTML])) {
            return 'embedded source or string data';
        }

        return $token->getTokenName() ?? 'unclassified';
    }
}
