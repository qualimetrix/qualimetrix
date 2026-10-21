<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\BudgetExceeded;
use QmxFindingGate\DeclaredDelta;
use QmxFindingGate\ExactDiff;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\Tsv;

/**
 * How a delta is measured, declared and written: an exact diff split into its
 * real hunks, and a writer that keeps the authored reasons and refuses an
 * unannounced surface before touching any file.
 */
final class DeclaredDeltaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    /**
     * The declared delta's own mechanics: an exact diff, never clipped, and an
     * empty declaration that claims nothing.
     */
    #[Test]
    public function itMeasuresTheWholeChangeAsAnExactUnclippedDiff(): void
    {
        $diff = ExactDiff::between("a\nb\nc\n", "a\nB\nc\n", 'candidate', 'reference (mapped)');
        self::assertSame(2, $diff->changedLineCount(), 'an exact diff counts both sides of the change');
        self::assertSame(
            "--- candidate\n+++ reference (mapped)\n@@ -2,1 +2,1 @@\n-b\n+B\n",
            $diff->render(),
            'the exact diff is the whole change, with no context and no clipping',
        );
        self::assertTrue(
            ExactDiff::between("a\n", "a\n", 'l', 'r')->isEmpty(),
            'two equal artifacts have no exact diff',
        );

        $long = str_repeat('x', 600);
        $detail = ExactDiff::between('{"a":"' . $long . '1"}', '{"a":"' . $long . '2"}', 'l', 'r')->tokenDetail();
        self::assertTrue($detail !== [], 'a line too long to read as a line also gets a token diff');

        $unclipped = ExactDiff::between($long . "1\n", $long . "2\n", 'l', 'r')->render();
        self::assertTrue(str_contains($unclipped, $long . '1'), 'a long line is declared whole, never clipped');

        $empty = Fs::temporaryDirectory('declared-delta-empty-');
        try {
            self::assertSame([], DeclaredDelta::load($empty)->surfaces(), 'an empty declared delta claims no surface');
            self::assertNull(DeclaredDelta::load($empty)->claim('case:undeclared|format:json'), 'a surface nothing declares claims nothing');
        } finally {
            Fs::removeRecursively($empty);
        }
    }

    /**
     * Two changes at opposite ends of an artifact used to be reported as one
     * hunk spanning everything between them, so `delta-too-large` counted
     * hundreds of identical lines as changed and refused a declaration that had
     * nothing left to declare. Every case here is written as the pair
     * "what the diff says" and "what it must not say".
     */
    #[Test]
    public function itSplitsTwoDistantChangesIntoTwoHunksAndCountsNoPaddingBetweenThem(): void
    {
        $left = "head\n" . implode("\n", array_map(static fn(int $i): string => 'same ' . $i, range(1, 40))) . "\ntail\n";
        $right = str_replace(["head\n", "\ntail\n"], ["HEAD\n", "\nTAIL\n"], $left);

        $diff = ExactDiff::between($left, $right, 'candidate', 'reference (mapped)');

        self::assertSame(2, substr_count($diff->render(), "\n@@ "), 'two changes far apart are two hunks, not one span');
        self::assertSame(4, $diff->changedLineCount(), 'the identical lines between two hunks are not counted as changed');
        self::assertTrue(
            !str_contains($diff->render(), 'same 20'),
            'the padding between two hunks is not emitted at all',
        );
        self::assertSame(
            [['head', 'HEAD'], ['tail', 'TAIL']],
            $diff->pairs(),
            'pairs() pairs inside each hunk, which is what delta-overreach reads',
        );
        self::assertTrue(
            str_contains($diff->render(), '@@ -1,1 +1,1 @@') && str_contains($diff->render(), '@@ -42,1 +42,1 @@'),
            'each hunk carries the line it starts at on both sides',
        );

        // The anchor floor, stated: a shared run shorter than it stays inside
        // its hunk and IS counted, which is why the class docblock no longer
        // claims that nothing identical is counted.
        $short = ExactDiff::between("a\nx\nb\nc\ny\nd\n", "A\nx\nb\nc\nY\nd\n", 'l', 'r');
        self::assertSame(1, substr_count($short->render(), "\n@@ "), 'a shared run below the anchor is not split on');
        self::assertSame(10, $short->changedLineCount(), 'and it is counted on both sides, padding included');

        // The budget is a refusal, not a silent downgrade to one hunk. Both
        // edges have to move: the span the search works on is what is left
        // after the shared head and tail are trimmed, so a change at one end
        // alone leaves nothing to spend a budget on.
        $wide = static fn(string $first, string $last): string => $first . "\n"
            . implode("\n", array_map(static fn(int $i): string => 'line ' . $i, range(1, 16100)))
            . "\n" . $last . "\n";
        $refused = false;

        try {
            ExactDiff::between($wide('first', 'last'), $wide('FIRST', 'LAST'), 'l', 'r');
        } catch (BudgetExceeded $error) {
            $refused = str_contains($error->getMessage(), 'refused rather than silently downgraded');
        }

        self::assertTrue($refused, 'a span past the search budget is refused, not emitted as one padded hunk');
    }

    /** The writer retains explained intentions and refuses an unannounced surface before touching any file. */
    #[Test]
    public function itWritesExactlyTheAnnouncedSurfacesAndKeepsTheirAuthoredReasons(): void
    {
        $root = Fs::temporaryDirectory('declared-delta-write-');
        try {
            $kept = "--- candidate\n+++ reference (mapped)\n@@ -1,1 +1,1 @@\n-a\n+A\n";
            $moved = "--- candidate\n+++ reference (mapped)\n@@ -2,1 +2,1 @@\n-b\n+B\n";

            Fs::write($root . '/' . DeclaredDelta::DIRECTORY . '/case-x-format-json.diff', $kept);
            Fs::write($root . '/' . DeclaredDelta::DIRECTORY . '/case-y-format-json.diff', $kept);
            Fs::write($root . '/' . DeclaredDelta::INDEX, Tsv::render(DeclaredDelta::COLUMNS, [
                ['case:x|format:json', DeclaredDelta::DIRECTORY . '/case-x-format-json.diff', 'the sentence written for x'],
                ['case:y|format:json', DeclaredDelta::DIRECTORY . '/case-y-format-json.diff', 'the sentence written for y'],
            ]));

            $model = DeclaredDelta::load($root);
            $indexBefore = Fs::read($root . '/' . DeclaredDelta::INDEX);
            self::assertTrue(self::throws(static fn(): array => $model->rewrite([
                'case:x|format:json' => $kept,
                'case:z|format:json' => $moved,
            ])), 'a derivation refuses an unannounced neighbouring surface before writing');
            self::assertSame($indexBefore, Fs::read($root . '/' . DeclaredDelta::INDEX), 'a refused write leaves the intention index unchanged');
            self::assertTrue(!is_file($root . '/' . DeclaredDelta::DIRECTORY . '/case-z-format-json.diff'), 'the refused neighbour leaves no derived file');
            $written = $model->rewrite(['case:y|format:json' => $moved, 'case:x|format:json' => $kept]);
            self::assertSame([
                DeclaredDelta::DIRECTORY . '/case-x-format-json.diff',
                DeclaredDelta::DIRECTORY . '/case-y-format-json.diff',
                DeclaredDelta::INDEX,
            ], $written, 'a derivation writes exactly the announced surfaces and its index');
            foreach ($written as $file) {
                self::assertTrue(is_file($root . '/' . $file), $file . ' is actually written');
            }
            self::assertSame($moved, Fs::read($root . '/' . DeclaredDelta::DIRECTORY . '/case-y-format-json.diff'), 'the changed announced surface contains the new measurement');
            $reasons = [];
            foreach (Tsv::rows($root . '/' . DeclaredDelta::INDEX, DeclaredDelta::COLUMNS) as $row) {
                $reasons[$row['surface']] = $row['reason'];
            }
            self::assertSame([
                'case:x|format:json' => 'the sentence written for x',
                'case:y|format:json' => 'the sentence written for y',
            ], $reasons, 'reasons belong to the intentions, including a changed measurement');
            self::assertSame(2, DeclaredDelta::load($root)->count(), 'a successful derivation remains loadable without placeholder reasons');
        } finally {
            Fs::removeRecursively($root);
        }
    }

    private static function throws(callable $callback): bool
    {
        try {
            $callback();

            return false;
        } catch (GateError) {
            return true;
        }
    }
}
