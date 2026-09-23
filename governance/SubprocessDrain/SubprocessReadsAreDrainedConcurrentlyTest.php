<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SubprocessDrain;

use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

require_once __DIR__ . '/NameOccurrence.php';
require_once __DIR__ . '/PhpFilePopulation.php';

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
 * The gate is a case-folded substring match, and the match itself belongs to
 * {@see NameOccurrence}, which both controls in this group read the tree
 * through: `Proc_Open(` and `PROC_OPEN(` are seen exactly as `proc_open(` is,
 * because PHP resolves function names without regard to case and a
 * case-sensitive gate would have been a spelling away from blind. Why the fold
 * has to preserve byte length, and why only a comment is excused, are that
 * class's subject rather than this one's.
 *
 * What stays here is what this control makes of an occurrence: the `file:line`
 * it declares by, the label it prints, and the case below, which measures that
 * the search behind this control still folds case and still reads the bytes
 * back out of the original. It measures behaviour and not delegation — a copy
 * of the scan pasted back in here would satisfy it, measured — so that the
 * search is *this group's one scan* is refused separately, by
 * {@see ScanIsNotReimplementedTest}.
 *
 * Folding widens the population of matches by exactly one occurrence today,
 * and that occurrence is not a call: a test method name whose camelCase seam
 * spells the single-stream spawner across two words. It is declared like any
 * other. That the fold is live is measured twice over, deliberately: directly,
 * on text this control writes itself, and incidentally, because removing the
 * fold refuses that entry as stale. The direct measurement is the load-bearing
 * one — the seam belongs to a test about something else and a rename there
 * would carry the incidental witness away with it.
 *
 * The seam also states the price of folding a substring match, which nothing
 * here reduces: the byte before a match is not read, so any identifier
 * spelling the same seam matches and needs an entry. Reading that byte would
 * narrow a fail-closed gate, and it is the direct measurement above, not this
 * paragraph, that would go red if someone did.
 *
 * Two measurements say why the match is not a token-type check:
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
 * ## The price of the line anchor, measured
 *
 * Inserting anything *above* an entered occurrence moves it, and the entry then
 * refuses as stale until someone re-anchors it — for a change that touched no
 * read discipline. That is not rare, and the size of it is a measurement rather
 * than an impression. Replaying every commit since 2026-06-01 that touched a file
 * holding an entry gives 40 commit-to-commit transitions, of which nine moved an
 * occurrence without changing the text of its line, and none changed that text.
 * So this anchor would have been re-taken nine times over that window, where one
 * keyed on the line's *content* would have been re-taken none.
 *
 * One of the nine was paid twice, though not by breaking twice: `10978986` put a
 * seven-line comment above the second call in `PseudoTerminalRun.php`, and two
 * concurrent sessions each re-took the same 78 → 85 within half an hour. What
 * doubled the bill there was the visibility of the red, not the anchor.
 *
 * The form is kept anyway, and not out of inertia. Four cheaper anchors were
 * weighed by the question this control is judged on — what does each stop
 * refusing — and every one of them is wider, because **an anchor's churn and its
 * specificity are the same property**: a form survives an unrelated edit exactly
 * when it names an occurrence by something a *different* occurrence can also
 * carry, which is also what lets one reviewed entry come to permit two. ADR 0070
 * lists the four and what each loses. Two of those losses are present in today's
 * tree rather than hypothetical: keyed on the enclosing symbol, eight of the
 * twenty-two occurrences outside the module sit four-to-a-function in two data
 * providers, so the six entries covering them would collapse into two; and two
 * more sit in a class constant array with no enclosing function to name at all.
 *
 * So the red is the mechanism working, not a defect in it: the same anchor is
 * what makes a *second* call added to an already-entered file refuse by default,
 * which a file-level entry could never do. Re-take the number from this
 * control's own refusal message, which reports where the occurrence sits now,
 * rather than counting lines by hand. Re-opening the question costs a fresh
 * measurement — the numbers above are the case for changing the form, and it has
 * already been made and lost.
 *
 * One gap is named rather than covered: a dynamically assembled name
 * (`$f = 'proc_' . 'open'; $f(…)`) is invisible to any textual gate, and the
 * enumeration swept for one and found none. A textual gate cannot close that
 * one in principle — the deciding text does not exist until run time.
 *
 * The differently-cased spelling that used to sit beside it is closed: the
 * match folds case, and a planted `Proc_Open(` is refused by name and line.
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
     * Nothing verifies the prose. The enclosing function is named for the
     * reader's sake, and matching it mechanically would be a second gate nobody
     * designed; the line anchor already keeps an entry attached to a real
     * occurrence. What that leaves open is an entry that anchors correctly and
     * still says something untrue — one did, excusing a site on the ground that
     * it could not reach the module, while its own directory reached it. A
     * reason is checked only by a reader holding it against the tree it
     * describes, so write reasons that can lose.
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

        'scripts/finding-gate/SelfTest.php:2919' => 'The interrupt self-test needs the child back *alive*, with its '
            . 'stdout handle, after reading two announcement lines while the child runs `sleep 30`; the module '
            . 'waits for exit. Stderr goes to a file, leaving stdout the only blocking stream, and the bespoke loop '
            . 'keeps its deadline and SIGKILL backstop.',

        'scripts/subprocess/tests/ChildProcessDrainTest.php:476' => 'The module is one file, so its test directory '
            . 'is not inside it. `runWithDeadline()` bounds the code under test by wall clock without ever reading '
            . 'a pipe from it: both of the child\'s streams go to files and only `proc_get_status()` is polled, '
            . 'because a supervisor that read a pipe here could deadlock the same way the code under test might.',

        'scripts/subprocess/tests/ChildProcessFailurePrefixTest.php:174' => 'Not a spawn, and the only occurrence '
            . 'here that prevents one: source text for a function of this name declared inside the module\'s own '
            . 'namespace, so that the module\'s unqualified call resolves to it and reports failure. That is how '
            . 'the prefix a caller is given for a failed spawn is measured at all — no portable condition makes the '
            . 'real function fail in the parent. The generated harness it goes into opens nothing of its own and '
            . 'reaches the module through its ordinary entry point, like any other caller.',

        'src/Infrastructure/Git/GitRepositoryLocator.php:94' => 'Production code, which may not import a '
            . 'development namespace, and the module lives outside `src/` deliberately. The deadlock is removed by '
            . 'construction instead: `git rev-parse` gets no stdin pipe and its stderr goes to a file, so stdout is '
            . 'the only blocking stream.',

        'tests/Infrastructure/Console/Functional/Command/HookInstallCommandTest.php:286' => 'Arranging a git '
            . 'repository for the case under test: both streams go to `/dev/null` and no pipe is opened, so only '
            . 'the exit status is read.',

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

        'tests/Analysis/Evidence/Duplication/Unit/DataDeclarationTaggerTest.php:363' => 'Not a spawn and not the '
            . 'name: a test method whose camelCase seam spells the single-stream spawner once case is folded — the '
            . '`p` ends one word and `Open` begins the next. It is the only occurrence in the tree that the '
            . 'case-fold adds, and it is therefore also this control\'s witness that the fold is live: fold the '
            . 'match back to case-sensitive and this entry refuses as stale.',

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

    /** @var list<array{path: string, line: int, name: string, spelled: string, kind: string}>|null */
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

    /**
     * The fold, measured on text this control writes rather than on a spelling
     * the tree happens to carry. Without this the only evidence that the match
     * folds case is one camelCase seam in an unrelated test, and a rename there
     * would take the evidence with it.
     *
     * The fourth case is the fold's cost and is pinned deliberately: a seam
     * inside a longer identifier is an occurrence, because nothing here reads
     * the byte before the match. That is why one `ENTRIES` row declares a
     * method name. Narrowing the match to identifier boundaries would be a
     * deliberate change to a fail-closed gate — it would turn this line red,
     * which is the point.
     *
     * The fifth field is pinned for a reason of its own: a seam's token is the
     * identifier it sits in, so the refusal labels it `a call` exactly as it
     * labels a real one. After the fold that label means "a name PHP would
     * resolve as a call", seam included, and pinning it here makes any change
     * to that reading a decision rather than a side effect.
     *
     * Every spelling here is assembled from halves: string literals in this
     * file are not excused, so writing one whole would make this control refuse
     * itself.
     */
    #[Test]
    public function itSeesANameWhateverCaseItIsWrittenIn(): void
    {
        $source = '<?php' . "\n"
            . "\$kelvin = '\u{212A}'; // three bytes only a multi-byte fold rewrites\n"
            . '$a = Proc_' . 'Open($command, $descriptors, $pipes);' . "\n"
            . '$b = \PROC_' . 'OPEN($command, $descriptors, $pipes);' . "\n"
            . '$c = P' . 'open($command, \'w\');' . "\n"
            . '$d = keep' . 'Open();' . "\n"
            . '// Proc_' . 'Open() named in a comment' . "\n";

        $found = array_map(
            static fn(array $occurrence): string => $occurrence['line'] . ' ' . $occurrence['spelled']
                . ' → ' . $occurrence['name'] . ' (' . $occurrence['kind'] . ')',
            self::occurrencesIn('fixture.php', $source),
        );

        self::assertSame(
            [
                '3 Proc_' . 'Open → ' . self::NEEDLES[0] . ' (a call)',
                '4 PROC_' . 'OPEN → ' . self::NEEDLES[0] . ' (a call)',
                '5 P' . 'open → ' . self::NEEDLES[1] . ' (a call)',
                '6 p' . 'Open → ' . self::NEEDLES[1] . ' (a call)',
            ],
            $found,
            'The match no longer folds case, or folds it differently. Lines 2-4 are working calls that PHP '
            . 'resolves exactly as the lower-case spelling, and a gate that misses them is a spelling away from '
            . 'blind. Line 6 is the cost of folding a substring match: a camelCase seam inside a longer '
            . 'identifier matches too, which is why one `ENTRIES` row declares a method name. Line 7 must not '
            . 'appear at all — it is inside a comment, the one kind of occurrence this control excuses. The '
            . 'Kelvin sign on line 2 is what fixes the fold to `strtolower`: a multi-byte fold rewrites it to '
            . 'one byte, and every spelling below is then read two bytes early.',
        );
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

            $undeclared[] = $anchor . ' — ' . self::spelling($occurrence) . ' (' . $occurrence['kind'] . ')';
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

    /**
     * The refusal names the bytes that are actually in the file, because the
     * match is case-folded and "the needle `popen`" would send a reader
     * looking for a spelling the line does not carry.
     *
     * @param array{path: string, line: int, name: string, spelled: string, kind: string} $occurrence
     */
    private static function spelling(array $occurrence): string
    {
        return $occurrence['spelled'] === $occurrence['name']
            ? $occurrence['name']
            : $occurrence['spelled'] . ', which folds to ' . $occurrence['name'];
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

    /** @return list<array{path: string, line: int, name: string, spelled: string, kind: string}> */
    private static function occurrences(): array
    {
        if (self::$occurrences !== null) {
            return self::$occurrences;
        }

        $found = [];

        foreach (PhpFilePopulation::paths() as $path) {
            $contents = (string) file_get_contents(PhpFilePopulation::root() . '/' . $path);

            foreach (self::occurrencesIn($path, $contents) as $occurrence) {
                $found[] = $occurrence;
            }
        }

        return self::$occurrences = $found;
    }

    /**
     * This control's own reading of {@see NameOccurrence}: the anchor it
     * declares by, and the label it puts in a refusal.
     *
     * @return list<array{path: string, line: int, name: string, spelled: string, kind: string}>
     */
    private static function occurrencesIn(string $path, string $contents): array
    {
        $found = [];

        foreach (NameOccurrence::findIn($contents, self::NEEDLES) as $occurrence) {
            $found[] = [
                'path' => $path,
                'line' => $occurrence->line,
                'name' => $occurrence->name,
                'spelled' => $occurrence->spelled,
                'kind' => self::kindOf($occurrence->token),
            ];
        }

        return $found;
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
