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
 * check, and the difference is not that the check was weakened.** It required
 * every hash a verdict names to resolve to an object of type `commit`. It
 * cannot run where this repository is published: the `check` job checks out at
 * the default depth of one (`.github/workflows/qmx.yml`, where only
 * `commit-messages` asks for `fetch-depth: 0`), and `main` is a chain of
 * squash-merges, one commit per pull request. Measured on a `--depth 1` clone of
 * this branch: all 51 tokens read `missing` and the method printed 428 refusal
 * lines while the other six methods passed. Measured against `main`: 45 of the
 * 51 are branch-local and stop existing the moment the branch is squashed. A
 * control that is red on `main` from the day of the merge is worse than a
 * vacuous green -- it teaches a reader to stop looking at red.
 *
 * So the fact is recorded instead of re-derived, which is honest because the
 * ledger is frozen: 276 rows, all answered, and no verdict row will ever be
 * added. Measured once, on `c40b1981`, against the full history:
 *
 *     cut -f3 defect-ledger/verdicts/*.tsv | grep -oE '\b[0-9a-f]{7,40}\b' | sort -u \
 *       | xargs -n1 git cat-file -t | sort | uniq -c
 *
 * gave **51 distinct tokens, every one of them `commit`**. Separately, each
 * row's commit was checked to touch that row's file or its counterpart: 159 of
 * 163, and the four misses were renames, where the commit changed the file's
 * name itself.
 *
 * What survives here is the half that needs no git and no history:
 * `itNamesACommitOnEveryVerdictThatClaimsOne`, which refuses a `fixed` or
 * `already-fixed` verdict carrying no hash-shaped token at all. That one holds
 * in a shallow clone and after a squash.
 */
final class DefectLedgerVerdictClosureTest extends TestCase
{
    private const string LEDGER = 'defect-ledger/defect-ledger.tsv';

    private const string VERDICT_DIRECTORY = 'defect-ledger/verdicts';

    private const array VOCABULARY = ['fixed', 'already-fixed', 'wont-fix'];

    private const string HASH_SHAPED = '~(?<![0-9a-z])(?=[0-9a-f]{7,40}(?![0-9a-z]))[a-f]*[0-9][0-9a-f]*(?![0-9a-z])~';

    private static string $projectRoot;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = \dirname(__DIR__, 2);
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
     * @return list<array{row_id: string, verdict: string, evidence: string, source: string}>
     */
    private static function verdicts(): array
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

        $verdicts = [];

        foreach ($files as $file) {
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
