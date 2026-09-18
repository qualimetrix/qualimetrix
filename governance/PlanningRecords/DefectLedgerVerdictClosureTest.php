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
     * The shape of a hash is not the existence of one. A verdict carrying
     * `1c6ec21e` -- eight hex digits naming nothing in this repository -- passed
     * the check above, which is the defect this stage exists to repair: a guard
     * reading the spelling instead of the subject. It was found by a hash
     * somebody invented, not by anybody reading the guard.
     *
     * **Every** hash-shaped token is resolved, not the first: evidence like
     * "c49fc0b4 then 666d8679" makes two claims and a reader following the
     * second one deserves it to be true.
     *
     * The object has to be a commit. A token resolving to a tree or a blob is a
     * real object and still not a commit anyone can read a change out of, and
     * the two are indistinguishable by spelling. Where a cell genuinely needs a
     * hex token that is not a commit, reword it -- a verdict is read by people,
     * and eight hex digits in it mean a commit.
     *
     * One `git cat-file --batch-check` for the whole set, because the tokens
     * repeat across files and a process per token would put a hundred-odd of
     * them in every Governance run. Its output is positional: one line per input
     * line, `<oid> <type> <size>` for an object that exists and
     * `<input> missing` for one that does not, so the answers are zipped back
     * onto the inputs by index rather than matched by name -- the oid it prints
     * for a short hash is the full one, which is not the token that was asked
     * about.
     */
    #[Test]
    public function itNamesCommitsTheRepositoryCarries(): void
    {
        $claims = [];

        foreach (self::verdicts() as $verdict) {
            if (!\in_array($verdict['verdict'], ['fixed', 'already-fixed'], true)) {
                continue;
            }

            foreach (self::hashShapedTokens($verdict['evidence']) as $token) {
                $claims[$token][] = $verdict['source'] . ': ' . $verdict['row_id'];
            }
        }

        $tokens = array_keys($claims);
        self::assertNotSame([], $tokens, 'No verdict claims a commit; this control would pass on nothing.');

        $types = self::gitObjectTypes($tokens);
        $unresolved = [];

        foreach ($claims as $token => $carriers) {
            $type = $types[(string) $token];

            if ($type !== 'commit') {
                foreach ($carriers as $carrier) {
                    $unresolved[] = \sprintf('%s names %s, which is %s', $carrier, $token, $type);
                }
            }
        }

        self::assertSame([], $unresolved, \sprintf(
            "A verdict names a commit this repository does not carry:\n%s",
            implode("\n", $unresolved),
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
     * @param list<string> $tokens
     *
     * @return array<string, string> each token mapped to its object type, or to why it has none
     */
    private static function gitObjectTypes(array $tokens): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open(['git', 'cat-file', '--batch-check'], $descriptors, $pipes, self::$projectRoot);

        // Not being able to ask is not the same as an answer. A control that
        // passes when its oracle is unavailable is the shape this whole stage is
        // about, so an unusable git is a refusal.
        self::assertIsResource($process, 'git cat-file could not be started, so no verdict here was checked.');

        fwrite($pipes[0], implode("\n", $tokens) . "\n");
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertIsString($output, 'git cat-file produced no readable output.');

        $lines = explode("\n", rtrim($output, "\n"));

        self::assertCount(
            \count($tokens),
            $lines,
            \sprintf(
                "git cat-file answered %d of %d tokens, so the answers cannot be zipped onto them.\n%s",
                \count($lines),
                \count($tokens),
                \is_string($errors) ? $errors : '',
            ),
        );

        $types = [];

        foreach ($tokens as $index => $token) {
            $fields = explode(' ', $lines[$index]);
            $types[$token] = $fields[1] ?? 'unanswered';
        }

        return $types;
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
