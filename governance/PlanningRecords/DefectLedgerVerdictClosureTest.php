<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\PlanningRecords;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Every row of the test-content defect ledger is answered exactly once, by a
 * verdict the vocabulary allows, carrying evidence.
 *
 * Until this control existed nothing in the repository read the ledger, so no
 * command could tell a resolved row from an untouched one and the only
 * statement about progress was prose in a report. The refusal therefore prints
 * the count of unanswered rows and names them: a package proves its work by
 * that number falling by exactly its own rows, which a pass/fail bit cannot
 * show.
 *
 * Five ways to close a row on nothing, each its own refusal: no verdict, a
 * verdict for a row the ledger does not carry, two verdicts for one row, a word
 * outside the vocabulary, and evidence left blank. The last two matter because
 * "the vocabulary is exactly three values" is a claim about what a reader may
 * write, and nothing but this enforces it.
 *
 * Verdicts are read as the union of every file in the verdict directory. One
 * file per package, because several packages work one tree at once and an
 * editing tool rewrites a file whole.
 *
 * What this control is warranted to guard, and for how long. The ledger is
 * frozen and finite: 276 rows that will never become 277. So on the day the last
 * row is answered this control passes, and passes for ever after, whatever the
 * repository does next -- it can no longer fail, which in a stage whose subject
 * is tautologies is worth saying out loud. It guards the closing of stage 05 and
 * nothing else. When the campaign's plan directory is retired, `defect-ledger/`
 * and this file are candidates for retirement with it. That is a decision for
 * whoever sweeps the campaign; what it must not become is the default, which is
 * what happens to a green test nobody remembers the warrant for.
 *
 * It sits with the planning records because the ledger began as one and the
 * campaign is what it answers to, not because it reads a plan path -- it reads
 * `defect-ledger/` at the repository root, which is where a verification asset
 * a control depends on has to live if the plan is ever to be swept.
 *
 * **One thing this control checked is now a frozen measurement rather than a
 * check, and two digests are what make that legitimate.** It required every hash
 * a verdict names to resolve to an object of type `commit`. It cannot run where
 * this repository is published: the `check` job checks out at the default depth
 * of one (`.github/workflows/qmx.yml`, where only `commit-messages` asks for
 * `fetch-depth: 0`), and `main` is a chain of squash-merges, one commit per pull
 * request. Measured on a `--depth 1` clone of this branch: all 51 tokens read
 * `missing` and the method printed 428 refusal lines while the other methods
 * passed. Measured against `main`: 45 of the 51 are branch-local and stop
 * existing the moment the branch is squashed. A control that is red on `main`
 * from the day of the merge is worse than a vacuous green -- it teaches a reader
 * to stop looking at red.
 *
 * **Recording a measurement instead of re-deriving it is only honest while
 * nothing measured can change, and a bogus hash has exactly two doors.** One is
 * a row nobody has answered yet; the other is a row already answered, edited
 * afterwards. Both were open, and both were measured open rather than argued
 * about: a row appended to the ledger and answered `fixed` with the invented
 * hash `1c6ec21e` passed, and `c49fc0b4` rewritten to `deadbee1` inside a
 * verdict already written passed too. The second is not the lesser door -- it is
 * the one the defect came through the first time, a hash that named nothing
 * written into a verdict row, which is why the commit-existence check was asked
 * for at all.
 *
 * `itFindsTheLedgerExactlyAsItWasMeasured` closes the first and
 * `itFindsTheVerdictsExactlyAsTheyWereMeasured` closes the second. **Neither is
 * a check on what these files say, and neither stands alone**: together they are
 * the argument that the removed check has nothing left to guard. One without the
 * other leaves that argument half made and the original defect reproducible.
 *
 * **Neither constant has a legitimate reason to be refreshed.** The ledger is a
 * measurement taken at `585b7c72`; the one change it was ever allowed, minting
 * `row_id`, happened before the freeze. The verdicts are complete at 276 of 276.
 * A digest refreshed to make a red test green asserts nothing, and here it would
 * silently re-open a door the removed check used to cover. If the campaign ever
 * genuinely reopens either file, what is owed is not a new hash: it is the
 * commit-existence check back, or its argument made again against whatever the
 * files have become.
 *
 * The measurement itself, taken once on `c40b1981` against the full history:
 *
 *     cut -f3 defect-ledger/verdicts/*.tsv | grep -oE '\b[0-9a-f]{7,40}\b' | sort -u \
 *       | xargs -n1 git cat-file -t | sort | uniq -c
 *
 * gave **51 distinct tokens, every one of them `commit`**. Separately, each
 * row's commit was checked to touch the row's file, its counterpart or its heir:
 * **197 of 201**, the four misses being renames, where git reports only the new
 * path so the name the ledger recorded never appears. That half is re-derivable
 * and its command is tracked, at `defect-ledger/reproduce-commit-reach.py`.
 *
 * What survives as a running check is the half that needs no git and no history:
 * `itNamesACommitOnEveryVerdictThatClaimsOne`, which refuses a `fixed` or
 * `already-fixed` verdict carrying no hash-shaped token at all. That one holds
 * in a shallow clone and after a squash.
 */
final class DefectLedgerVerdictClosureTest extends TestCase
{
    private const string LEDGER = 'defect-ledger/defect-ledger.tsv';

    private const string VERDICT_DIRECTORY = 'defect-ledger/verdicts';

    private const array VOCABULARY = ['fixed', 'already-fixed', 'wont-fix'];

    private const int LEDGER_ROWS = 276;

    private const string LEDGER_SHA256 = '934b71a0075dfaeeab4dbb1f51517d2d037b69231488493af267a7bfadddb434';

    private const int VERDICT_FILES = 7;

    private const string VERDICTS_SHA256 = 'c73e34c1e618d7120694f16e4301507558646e83158679b7c4e813e18ac1b2eb';

    private const string HASH_SHAPED = '~(?<![0-9a-z])(?=[0-9a-f]{7,40}(?![0-9a-z]))[a-f]*[0-9][0-9a-f]*(?![0-9a-z])~';

    private static string $projectRoot;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = \dirname(__DIR__, 2);
    }

    /**
     * The first door: the ledger is exactly the file that was measured, byte for
     * byte, so no row can appear that needs a verdict nobody checked. Why a
     * digest rather than a check, and why this one is half an argument, is in
     * the class docblock.
     */
    #[Test]
    public function itFindsTheLedgerExactlyAsItWasMeasured(): void
    {
        $path = self::$projectRoot . '/' . self::LEDGER;

        self::assertFileExists($path, 'The frozen ledger is missing: ' . self::LEDGER);

        $contents = file_get_contents($path);
        \assert($contents !== false);

        $rows = substr_count(rtrim($contents, "\n"), "\n");

        self::assertSame(self::LEDGER_SHA256, hash('sha256', $contents), \sprintf(
            "%s is not the file that was measured: it carries %d rows against the %d it was frozen with,"
            . " and its digest does not match.\n"
            . "Nothing legitimately changes this file. A row added here needs a verdict nothing checked the"
            . " commit of, which is exactly what removing itNamesCommitsTheRepositoryCarries relied on being"
            . " impossible -- see this method's docblock before touching the constant.",
            self::LEDGER,
            $rows,
            self::LEDGER_ROWS,
        ));
    }

    /**
     * The second door: the verdict files are exactly the files that were
     * measured, so a hash written into a row already answered cannot be edited
     * afterwards -- which is how the defect arrived the first time. Why a digest
     * rather than a check, and why this one is half an argument, is in the class
     * docblock.
     *
     * The count is fixed as well as the content, and the name goes into the
     * digest beside its bytes: deleting a whole file and rewriting the rest, or
     * renaming one, are their own shapes, and a digest over concatenated content
     * alone would report them as an unhelpful "does not match".
     */
    #[Test]
    public function itFindsTheVerdictsExactlyAsTheyWereMeasured(): void
    {
        $files = self::verdictFiles();
        $parts = [];

        foreach ($files as $file) {
            $contents = file_get_contents(self::$projectRoot . '/' . self::VERDICT_DIRECTORY . '/' . $file);
            \assert($contents !== false);
            $parts[] = $file . "\0" . $contents;
        }

        self::assertSame(self::VERDICTS_SHA256, hash('sha256', implode("\0\0", $parts)), \sprintf(
            "%s is not what was measured: it holds %d files against the %d measured (%s),"
            . " and their digest does not match.\n"
            . "A verdict already written cannot legitimately change. Editing the commit a row names is"
            . " exactly the defect that asked for itNamesCommitsTheRepositoryCarries, and that check was"
            . " removed on the argument that this cannot happen -- see the class docblock before touching"
            . " the constant.",
            self::VERDICT_DIRECTORY,
            \count($files),
            self::VERDICT_FILES,
            implode(', ', $files),
        ));
    }

    #[Test]
    public function itCarriesAVerdictForEveryLedgerRow(): void
    {
        $answered = [];

        foreach (self::verdicts() as $verdict) {
            $answered[$verdict['row_id']] = true;
        }

        $ledgerRows = self::ledgerRowIds();
        $unanswered = array_values(array_filter(
            $ledgerRows,
            static fn(string $rowId): bool => !isset($answered[$rowId]),
        ));

        self::assertSame([], $unanswered, \sprintf(
            "%d of %d ledger rows carry no verdict (%d answered). Each needs one line in %s/<package>.tsv:\n%s",
            \count($unanswered),
            \count($ledgerRows),
            \count($ledgerRows) - \count($unanswered),
            self::VERDICT_DIRECTORY,
            implode(', ', $unanswered),
        ));
    }

    #[Test]
    public function itAnswersOnlyRowsTheLedgerCarries(): void
    {
        $ledgerRows = array_flip(self::ledgerRowIds());
        $unknown = [];

        foreach (self::verdicts() as $verdict) {
            if (!isset($ledgerRows[$verdict['row_id']])) {
                $unknown[] = $verdict['source'] . ': ' . $verdict['row_id'];
            }
        }

        self::assertSame([], $unknown, "A verdict names a row_id the ledger does not carry:\n" . implode("\n", $unknown));
    }

    #[Test]
    public function itAnswersEveryRowAtMostOnce(): void
    {
        $sources = [];

        foreach (self::verdicts() as $verdict) {
            $sources[$verdict['row_id']][] = $verdict['source'];
        }

        $duplicated = [];

        foreach ($sources as $rowId => $carriers) {
            if (\count($carriers) > 1) {
                $duplicated[] = $rowId . ' is answered in ' . implode(', ', $carriers);
            }
        }

        self::assertSame([], $duplicated, "A ledger row carries more than one verdict:\n" . implode("\n", $duplicated));
    }

    #[Test]
    public function itUsesOnlyTheThreeWordsThatAreVerdicts(): void
    {
        $foreign = [];

        foreach (self::verdicts() as $verdict) {
            if (!\in_array($verdict['verdict'], self::VOCABULARY, true)) {
                $foreign[] = $verdict['source'] . ': ' . $verdict['row_id'] . ' says "' . $verdict['verdict'] . '"';
            }
        }

        self::assertSame([], $foreign, \sprintf(
            "A verdict uses a word that is not one of %s:\n%s",
            implode(', ', self::VOCABULARY),
            implode("\n", $foreign),
        ));
    }

    #[Test]
    public function itCarriesEvidenceOnEveryVerdict(): void
    {
        $bare = [];

        foreach (self::verdicts() as $verdict) {
            if (trim($verdict['evidence']) === '') {
                $bare[] = $verdict['source'] . ': ' . $verdict['row_id'];
            }
        }

        self::assertSame([], $bare, \sprintf(
            "A verdict carries no evidence; fixed and already-fixed name a commit, wont-fix a reason:\n%s",
            implode("\n", $bare),
        ));
    }

    /**
     * A non-empty cell is not the rule the vocabulary states: `fixed` and
     * `already-fixed` name the commit, and "fixed, see the PR" closes a row on
     * something nothing here can read.
     *
     * A hash is recognised as seven to forty lowercase hex digits carrying at
     * least one digit -- the digit is what keeps an English word spelled from
     * a-f out. A hash that happens to be all letters is rejected; write it
     * longer.
     */
    #[Test]
    public function itNamesACommitOnEveryVerdictThatClaimsOne(): void
    {
        $unsourced = [];

        foreach (self::verdicts() as $verdict) {
            if (!\in_array($verdict['verdict'], ['fixed', 'already-fixed'], true)) {
                continue;
            }

            if (self::hashShapedTokens($verdict['evidence']) === []) {
                $unsourced[] = $verdict['source'] . ': ' . $verdict['row_id'] . ' says "' . $verdict['evidence'] . '"';
            }
        }

        self::assertSame([], $unsourced, \sprintf(
            "A fixed or already-fixed verdict names no commit:\n%s",
            implode("\n", $unsourced),
        ));
    }

    /**
     * @return list<string>
     */
    private static function hashShapedTokens(string $evidence): array
    {
        preg_match_all(self::HASH_SHAPED, $evidence, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @return list<string>
     */
    private static function ledgerRowIds(): array
    {
        $rows = self::readTsv(self::LEDGER, ['row_id', 'file', 'line', 'class', 'counterpart', 'severity', 'source_report', 'note']);

        self::assertNotSame([], $rows, 'The ledger carries no rows; every refusal here would be vacuous.');

        return array_map(static fn(array $row): string => $row['row_id'], $rows);
    }

    /**
     * The verdict files, by name, in an order this control fixes rather than
     * inherits. `FilesystemIterator` hands them back in whatever order the
     * filesystem holds them, so a digest over the unsorted sequence would be a
     * different digest on a different machine -- green here and red in CI, for a
     * tree nobody changed.
     *
     * @return list<string>
     */
    private static function verdictFiles(): array
    {
        $directory = self::$projectRoot . '/' . self::VERDICT_DIRECTORY;

        self::assertDirectoryExists($directory, 'The verdict directory is where every package writes; without it nothing here can refuse.');

        $files = [];

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'tsv') {
                $files[] = $entry->getBasename();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<array{row_id: string, verdict: string, evidence: string, source: string}>
     */
    private static function verdicts(): array
    {
        $verdicts = [];

        foreach (self::verdictFiles() as $file) {
            foreach (self::readTsv(self::VERDICT_DIRECTORY . '/' . $file, ['row_id', 'verdict', 'evidence']) as $row) {
                $verdicts[] = [
                    'row_id' => $row['row_id'],
                    'verdict' => $row['verdict'],
                    'evidence' => $row['evidence'],
                    'source' => $file,
                ];
            }
        }

        return $verdicts;
    }

    /**
     * @param list<string> $columns
     *
     * @return list<array<string, string>>
     */
    private static function readTsv(string $relativePath, array $columns): array
    {
        $path = self::$projectRoot . '/' . $relativePath;

        self::assertFileExists($path, "A table this control reads is missing: {$relativePath}");

        $contents = file_get_contents($path);
        \assert($contents !== false);

        $lines = explode("\n", rtrim($contents, "\n"));
        $header = array_shift($lines);

        self::assertSame(
            implode("\t", $columns),
            $header,
            "{$relativePath} does not start with the header this control reads; a column added or renamed silently shifts every cell.",
        );

        $rows = [];

        foreach ($lines as $number => $line) {
            if ($line === '') {
                continue;
            }

            $cells = explode("\t", $line);

            self::assertCount(
                \count($columns),
                $cells,
                \sprintf('%s line %d has %d cells, not %d.', $relativePath, $number + 2, \count($cells), \count($columns)),
            );

            $rows[] = array_combine($columns, $cells);
        }

        return $rows;
    }
}
