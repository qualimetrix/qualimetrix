<?php

declare(strict_types=1);

namespace Qualimetrix\PromiseEffect\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\PromiseEffect\Declarations;
use Qualimetrix\PromiseEffect\Ledger;
use Qualimetrix\PromiseEffect\LedgerError;

/**
 * The `side_b` selector of `effect-magnitudes.tsv`, and the duplicate document
 * it exists beside.
 *
 * Two keys of one pair that reach the same pointer and are written the same
 * value make `both` equal to whichever side one looks at, and
 * `Classifier::pair()` then reads every side as having survived. The verdict
 * that comes out is COEXISTENCE_OK — green, not blind — so nothing in the grid
 * says the question was unanswerable. Every case here guards a property that
 * would silently restore that state.
 */
final class PerSideMagnitudeTest extends TestCase
{
    /** @var list<string> */
    private array $scratchRoots = [];

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 3) . '/scripts/promise-effect/Ledger.php';
        require_once \dirname(__DIR__, 3) . '/scripts/promise-effect/Declarations.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->scratchRoots as $root) {
            self::removeTree($root);
        }

        $this->scratchRoots = [];
    }

    #[Test]
    public function itRefusesAPerSideLiteralEqualToItsFormsCanonicalWrite(): void
    {
        $this->expectException(LedgerError::class);
        $this->expectExceptionMessageMatches('/the side_b literal of the form "int" is its canonical write/');

        Declarations::load($this->rootWithMagnitudes($this->rewriteSideB('int', '7331')));
    }

    #[Test]
    public function itRefusesAPerSideLiteralEqualToItsFormsAlternate(): void
    {
        $this->expectException(LedgerError::class);
        $this->expectExceptionMessageMatches('/the side_b literal of the form "int" is its alternate/');

        Declarations::load($this->rootWithMagnitudes($this->rewriteSideB('int', '9137')));
    }

    #[Test]
    public function itRefusesAPerSideRowNamingAFormThatDoesNotExist(): void
    {
        $this->expectException(LedgerError::class);
        $this->expectExceptionMessageMatches('/names the form "integer", which forms\.tsv does not/');

        Declarations::load($this->rootWithMagnitudes($this->renameSideBSelector('int', 'integer')));
    }

    /**
     * One magnitude, many spellings — the rule `forms.tsv` follows for 7331 and
     * the alternate table follows for 9137.
     *
     * `writeForShape()` walks the forms in file order and returns the first one
     * the shape accepts, so which spelling a key is written in is a decision
     * nobody reads. Two different magnitudes across spellings would therefore
     * put a different value on one side depending on that decision, and the
     * per-form guards above cannot see it: each row would still differ from its
     * own form's canonical write.
     */
    #[Test]
    public function itWritesOneMagnitudeInEveryNumericSpelling(): void
    {
        $declarations = Declarations::load(\dirname(__DIR__, 3));

        foreach ($declarations->sideBLiterals as $form => $literal) {
            if ($form === self::WORD_FORM) {
                continue;
            }

            self::assertSame(
                str_replace(self::CANONICAL, self::SIDE_B, $declarations->forms[$form]->yamlWrite),
                $literal,
                'the side_b literal of the form "' . $form . '" is not its canonical spelling carrying ' . self::SIDE_B,
            );
        }
    }

    /**
     * The two forms that admit no third value, named rather than counted.
     *
     * `bool` has two values and one of them is the standing value of most
     * `enabled` keys; the `scope` enum has two and `all` is the default. A
     * `side_b` row for either would be a value chosen to win rather than a
     * third magnitude, and the six cells that stay unobservable after this
     * package are exactly the cells whose sides are written with them.
     */
    #[Test]
    public function itDeclaresNoPerSideValueForTheFormsThatHaveNoThird(): void
    {
        $declarations = Declarations::load(\dirname(__DIR__, 3));

        self::assertArrayNotHasKey('bool', $declarations->sideBLiterals);
        self::assertArrayNotHasKey('null', $declarations->sideBLiterals);
        self::assertSame(['scope', 'unused-directive-severity', 'mode', 'severity'], array_keys($declarations->leafAlternates));
    }

    /**
     * Nineteen `same-source` triples are judged under two kinds at once, so the
     * same document is probed twice and both cells count. They are deliberately
     * not deduplicated — `PairRow::key()` carries the kind because a row's
     * promise belongs to its kind — so what is guarded here is that the set
     * does not move in silence. The list is written out rather than derived
     * from the ledger: a test that computed its own expectation would agree
     * with any ledger at all.
     */
    #[Test]
    public function itNamesEveryTripleJudgedUnderMoreThanOneKind(): void
    {
        $ledger = Ledger::load(\dirname(__DIR__, 3));
        $kinds = [];

        foreach ($ledger->pairs as $row) {
            if ($row->sourceScope !== 'same-source') {
                continue;
            }

            $kinds[$row->rule . '|' . $row->keyA . '|' . $row->keyB][] = $row->kind;
        }

        $shared = [];

        foreach ($kinds as $triple => $seen) {
            if (\count($seen) > 1) {
                sort($seen);
                $shared[$triple] = implode(',', $seen);
            }
        }

        ksort($shared);

        self::assertSame(self::SHARED_DOCUMENTS, $shared);
    }

    private function rewriteSideB(string $form, string $literal): string
    {
        // `${1}` and not `$1`: a numeric literal directly after the reference
        // is read as a two-digit group number, and the plant then writes a
        // truncated magnitude into the selector column instead of the value.
        return $this->replaceFirst('/^(side_b\t' . preg_quote($form, '/') . '\t)[^\t]*/m', '${1}' . $literal);
    }

    private function renameSideBSelector(string $form, string $renamed): string
    {
        return $this->replaceFirst('/^side_b\t' . preg_quote($form, '/') . '\t/m', 'side_b' . "\t" . $renamed . "\t");
    }

    private function replaceFirst(string $pattern, string $replacement): string
    {
        $source = file_get_contents(\dirname(__DIR__, 3) . '/' . self::MAGNITUDES);

        self::assertIsString($source);

        $patched = preg_replace($pattern, $replacement, $source, 1);

        self::assertIsString($patched);
        self::assertNotSame($source, $patched, 'the plant matched nothing, so the case proves nothing');

        return $patched;
    }

    /**
     * `Declarations::load()` reads seven tables, so a scratch root carries all
     * of them and only the one under test is rewritten.
     */
    private function rootWithMagnitudes(string $magnitudes): string
    {
        $root = sys_get_temp_dir() . '/promise-effect-per-side-' . bin2hex(random_bytes(6));
        $this->scratchRoots[] = $root;

        mkdir($root . '/promise-effect', 0o775, true);

        foreach (self::TABLES as $table) {
            $source = file_get_contents(\dirname(__DIR__, 3) . '/promise-effect/' . $table);

            self::assertIsString($source, $table . ' is not readable, so the case proves nothing');

            file_put_contents($root . '/promise-effect/' . $table, $source);
        }

        file_put_contents($root . '/' . self::MAGNITUDES, $magnitudes);

        return $root;
    }

    private static function removeTree(string $path): void
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
            is_dir($child) ? self::removeTree($child) : unlink($child);
        }

        rmdir($path);
    }

    private const string MAGNITUDES = 'promise-effect/effect-magnitudes.tsv';
    private const string CANONICAL = '7331';
    private const string SIDE_B = '5519';
    private const string WORD_FORM = 'string-nonnumber';

    /** @var list<string> */
    private const array TABLES = [
        'forms.tsv',
        'axis-d-envelopes.tsv',
        'witness-envelopes.tsv',
        'axis-a-hits.tsv',
        'pair-kind-scope.tsv',
        'composition-magnitudes.tsv',
        'effect-magnitudes.tsv',
    ];

    /** @var array<string, string> */
    private const array SHARED_DOCUMENTS = [
        'complexity.ccn|callable.threshold|threshold' => '2-same-name-top-vs-level,4-cross-level',
        'complexity.ccn|class.threshold|threshold' => '2-same-name-top-vs-level,4-cross-level',
        'complexity.cognitive|callable.threshold|threshold' => '2-same-name-top-vs-level,4-cross-level',
        'complexity.cognitive|class.threshold|threshold' => '2-same-name-top-vs-level,4-cross-level',
        'complexity.npath|callable.threshold|threshold' => '2-same-name-top-vs-level,4-cross-level',
        'complexity.npath|class.threshold|threshold' => '2-same-name-top-vs-level,4-cross-level',
        'coupling.cbo|class.error|error' => '2-same-name-top-vs-level,4-cross-level',
        'coupling.cbo|class.scope|scope' => '2-same-name-top-vs-level,6-precedence-fill-in',
        'coupling.cbo|class.threshold|threshold' => '2-same-name-top-vs-level,4-cross-level',
        'coupling.cbo|class.warning|warning' => '2-same-name-top-vs-level,4-cross-level',
        'coupling.cbo|error|namespace.error' => '2-same-name-top-vs-level,4-cross-level',
        'coupling.cbo|namespace.threshold|threshold' => '2-same-name-top-vs-level,4-cross-level',
        'coupling.cbo|namespace.warning|warning' => '2-same-name-top-vs-level,4-cross-level',
        'coupling.instability|class.max-error|max-error' => '2-same-name-top-vs-level,4-cross-level',
        'coupling.instability|class.max-warning|max-warning' => '2-same-name-top-vs-level,4-cross-level',
        'coupling.instability|class.threshold|threshold' => '2-same-name-top-vs-level,4-cross-level',
        'coupling.instability|max-error|namespace.max-error' => '2-same-name-top-vs-level,4-cross-level',
        'coupling.instability|max-warning|namespace.max-warning' => '2-same-name-top-vs-level,4-cross-level',
        'coupling.instability|namespace.threshold|threshold' => '2-same-name-top-vs-level,4-cross-level',
    ];
}
