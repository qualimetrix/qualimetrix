<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A test file's path says which subject owns it.
 *
 * The sibling sentence to {@see TestNamespacesFollowTheirPathTest}, which says a
 * test file's namespace says where the file is. The two break on the same event,
 * a file at the wrong address, and read the same corpus through {@see TestTree}.
 *
 * The rule is the three parts {@see TestSubjectPaths} states — owner, level, and
 * the remainder being a *prefix* of the covered class's namespace remainder. The
 * prefix form is what measurement forced: filing a test flat under
 * `{owner}/{level}/` while its subject sits in a sub-namespace is this tree's
 * convention, and 132 correct files are filed that way. It still refuses what an
 * equality form was introduced for, a remainder that invents or skips a segment,
 * which is the part a two-part owner-and-level control cannot see at all.
 *
 * **This validates paths against owners, never owners against paths.**
 * `Core.Profiler` is a manifest owner with no `tests/Core/Profiler` directory and
 * no test class anywhere beneath it. That is not a failure here — it is a
 * question this control does not ask, and answering it would mean deciding that
 * every owner owes the tree a test, which is a different claim needing a
 * different measurement.
 *
 * **Three exception lists, each capped**, ship as {@see SubjectPathExceptions}.
 * Two refusals per list, and the second matters as much as the first: an
 * exception the list does not carry, and a row that no longer describes one. A
 * stale row is the same defect as a missed one — a statement about the tree that
 * stopped being true while still being believed.
 *
 * Emptying the lists is deliberately not this guard's job: it is a refiling or a
 * coverage declaration per file, and the check has to exist before that work can
 * be proved to have finished.
 */
final class TestPathsNameTheirSubjectTest extends TestCase
{
    /**
     * The whole population judged once: nine cases asking the same scan for
     * different halves of it would parse the tree nine times.
     *
     * @var array<string, array{verdict: string, detail: string}>|null
     */
    private static ?array $judged = null;

    /**
     * The owner table {@see itRefusesEachWayOnThePathsItIsGiven} hands the rule,
     * so a probe never reaches the manifest the real scan is judged against.
     *
     * @var array<string, string>
     */
    private const array PROBE_OWNERS = ['Probe' => 'Probe', 'Probe/Deep' => 'Probe.Deep', 'Other' => 'Other'];

    /** @var array<string, string> */
    private const array PROBE_DECLARATIONS = [
        'Qualimetrix\Probe\Widget\Html\Renderer' => 'Probe',
        'Qualimetrix\Probe\Flat' => 'Probe',
        'Qualimetrix\Probe\Deep\Thing' => 'Probe/Deep',
        'Qualimetrix\Other\Adapter\Command' => 'Other',
    ];

    /** @var list<string> */
    private const array RENDERER = ['Qualimetrix\Probe\Widget\Html\Renderer'];

    #[Test]
    public function itFindsNoTestFileWhosePathNamesNoManifestOwner(): void
    {
        $unknown = SubjectPathExceptions::describe(
            TestSubjectPaths::carrying(self::judged(), TestSubjectPaths::UNKNOWN_OWNER),
        );

        self::assertSame('', $unknown, \sprintf(
            "A test file's path names the subject that owns it, and these name no owner the manifest has.\n"
            . "There is no exception list for this: move the file under its owner, or the segments before its\n"
            . "level are a subject nobody declared:\n%s",
            $unknown,
        ));
    }

    #[Test]
    public function itFindsNoTestFileThatNamesNoSingleAnalysisLevel(): void
    {
        $unlevelled = SubjectPathExceptions::describe(
            TestSubjectPaths::carrying(self::judged(), TestSubjectPaths::LEVEL),
        );

        self::assertSame('', $unlevelled, \sprintf(
            "Every test file carries exactly one Unit, Integration or Functional segment, directly below its\n"
            . "owner. A file with none is in no suite the owner declares; a file with two is in whichever the\n"
            . "reader guesses:\n%s",
            $unlevelled,
        ));
    }

    #[Test]
    public function itFindsNoUncoveredTestFileTheListDoesNotCarry(): void
    {
        self::assertMissingRows('declares_no_coverage', \sprintf(
            "A file that declares no #[CoversClass] states no subject, so its path cannot be checked against\n"
            . "one. Declare what it covers — or #[CoversNothing] when it genuinely covers nothing nameable.\n"
            . "Do not add a row to %s by hand:",
            SubjectPathExceptions::PATH,
        ));
    }

    #[Test]
    public function itFindsNoTestFileCoveringAnotherOwnerThatTheListDoesNotCarry(): void
    {
        self::assertMissingRows('covers_another_owner', \sprintf(
            "A test filed under one owner whose every coverage claim names another is filed under a subject it\n"
            . "does not test. Move it to the owner it covers, or cover the owner it is filed under.\n"
            . "Do not add a row to %s by hand:",
            SubjectPathExceptions::PATH,
        ));
    }

    #[Test]
    public function itFindsNoPathDisagreeingWithItsSubjectThatTheListDoesNotCarry(): void
    {
        self::assertMissingRows('remainder_is_not_a_prefix', \sprintf(
            "The path below the level must be a prefix of where the covered class actually sits. These invent a\n"
            . "segment their subject does not have, or skip one in the middle — which a reader scanning the tree\n"
            . "does not notice. Rename the directory to the subject's own, or file the test flat.\n"
            . "Do not add a row to %s by hand:",
            SubjectPathExceptions::PATH,
        ));
    }

    #[Test]
    public function itCarriesNoStaleExceptionRow(): void
    {
        $measured = SubjectPathExceptions::split(self::judged());

        $stale = [];
        foreach (SubjectPathExceptions::load() as $name => $list) {
            foreach (array_diff_assoc($list['rows'], $measured[$name]) as $path => $detail) {
                $stale[] = \sprintf('%s is allowed to be on %s as "%s", and no longer is', $path, $name, $detail);
            }
        }

        self::assertSame([], $stale, \sprintf(
            "%d row(s) in %s describe an exception that is not there any more.\n"
            . "Re-derive: a row that describes nothing hides the next one that would.\n%s",
            \count($stale),
            SubjectPathExceptions::PATH,
            implode("\n", $stale),
        ));
    }

    #[Test]
    public function itKeepsEveryExceptionListUnderItsCeiling(): void
    {
        $measured = SubjectPathExceptions::split(self::judged());

        $over = [];
        foreach (SubjectPathExceptions::load() as $name => $list) {
            if (\count($measured[$name]) > $list['ceiling']) {
                $over[] = \sprintf(
                    '%s measures %d file(s) and admits %d',
                    $name,
                    \count($measured[$name]),
                    $list['ceiling'],
                );
            }

            if (\count($list['rows']) > $list['ceiling']) {
                $over[] = \sprintf(
                    '%s carries %d row(s) and admits %d',
                    $name,
                    \count($list['rows']),
                    $list['ceiling'],
                );
            }
        }

        self::assertSame([], $over, \sprintf(
            "%d exception list(s) grew past the ceiling they carry.\n"
            . "Deriving may only ever lower a ceiling, so this cannot be fixed by re-running the derive command:\n"
            . "fix the file, or raise that number by hand in %s and say in the commit why the tree is allowed to\n"
            . "get worse:\n%s",
            \count($over),
            SubjectPathExceptions::PATH,
            implode("\n", $over),
        ));
    }

    /**
     * Proves the rule refuses at all, on paths and coverage claims it is handed:
     * a scan that read no file would find no violation either, and stay green
     * for the wrong reason. Nothing here touches the corpus the real scan reads.
     */
    #[Test]
    public function itRefusesEachWayOnThePathsItIsGiven(): void
    {
        // Part 3, the three answers a prefix test gives.
        self::assertSame(
            TestSubjectPaths::EXACT,
            self::probe('tests/Probe/Unit/Widget/Html/RendererTest.php', self::RENDERER)['verdict'],
        );
        self::assertSame(
            TestSubjectPaths::PREFIX,
            self::probe('tests/Probe/Unit/Widget/RendererTest.php', self::RENDERER)['verdict'],
        );
        self::assertSame(
            TestSubjectPaths::PREFIX,
            self::probe('tests/Probe/Unit/RendererTest.php', self::RENDERER)['verdict'],
        );
        // A segment the subject does not have, at the end …
        self::assertSame(
            ['verdict' => TestSubjectPaths::NOT_A_PREFIX, 'detail' => 'Widget/Whatever against Widget/Html'],
            self::probe('tests/Probe/Unit/Widget/Whatever/RendererTest.php', self::RENDERER),
        );
        // … and in the middle, which is the shape a reader scanning the tree misses.
        self::assertSame(
            ['verdict' => TestSubjectPaths::NOT_A_PREFIX, 'detail' => 'Html against Widget/Html'],
            self::probe('tests/Probe/Unit/Html/RendererTest.php', self::RENDERER),
        );
        // A subject at the owner's own root is a remainder of nothing, not a wildcard.
        self::assertSame(
            TestSubjectPaths::EXACT,
            self::probe('tests/Probe/Unit/FlatTest.php', ['Qualimetrix\Probe\Flat'])['verdict'],
        );
        self::assertSame(
            TestSubjectPaths::NOT_A_PREFIX,
            self::probe('tests/Probe/Unit/Widget/FlatTest.php', ['Qualimetrix\Probe\Flat'])['verdict'],
        );

        // A nested owner is its own owner, not a sub-namespace of the shorter one.
        self::assertSame(
            ['verdict' => TestSubjectPaths::ANOTHER_OWNER, 'detail' => 'is filed under Probe and covers only Probe.Deep'],
            self::probe('tests/Probe/Unit/Deep/ThingTest.php', ['Qualimetrix\Probe\Deep\Thing']),
        );
        self::assertSame(
            ['verdict' => TestSubjectPaths::ANOTHER_OWNER, 'detail' => 'is filed under Probe and covers only Other'],
            self::probe('tests/Probe/Functional/CommandTest.php', ['Qualimetrix\Other\Adapter\Command']),
        );
        // One claim naming the path's own owner is enough; the rest do not move the verdict.
        self::assertSame(
            TestSubjectPaths::EXACT,
            self::probe('tests/Probe/Unit/FlatTest.php', ['Qualimetrix\Other\Adapter\Command', 'Qualimetrix\Probe\Flat'])['verdict'],
        );
        // A file whose every claim the manifest cannot resolve is not a verdict
        // at all: it used to fall through to not-a-prefix and take a slot on
        // that list, under a refusal telling the reader to rename a directory.
        try {
            self::probe('tests/Probe/Unit/StrangerTest.php', ['Qualimetrix\Nobody\Stranger']);
            self::fail('A file whose every claim lies outside the manifest was judged rather than refused.');
        } catch (LogicException $refusal) {
            self::assertStringContainsString('Qualimetrix\Nobody\Stranger', $refusal->getMessage());
            self::assertStringContainsString('the manifest declares none of them', $refusal->getMessage());
        }
        // One claim that does resolve answers part 3 by itself, so the file is
        // judged on that claim rather than aborting the scan over the other name.
        self::assertSame(
            TestSubjectPaths::EXACT,
            self::probe('tests/Probe/Unit/FlatTest.php', ['Qualimetrix\Probe\Flat', 'Qualimetrix\Nobody\Stranger'])['verdict'],
        );

        // The two ways a file states no subject, which are not the same statement.
        self::assertSame(
            ['verdict' => TestSubjectPaths::NO_COVERAGE, 'detail' => 'declares no coverage attribute'],
            self::probe('tests/Probe/Unit/SilentTest.php', []),
        );
        self::assertSame(
            ['verdict' => TestSubjectPaths::NO_COVERAGE, 'detail' => 'declares #[CoversNothing]'],
            self::probe('tests/Probe/Unit/SilentTest.php', [], true),
        );

        // Part 2.
        self::assertSame(TestSubjectPaths::LEVEL, self::probe('tests/Probe/RendererTest.php', self::RENDERER)['verdict']);
        self::assertSame(
            TestSubjectPaths::LEVEL,
            self::probe('tests/Probe/Unit/Integration/RendererTest.php', self::RENDERER)['verdict'],
        );

        // Part 1, and the level that is not immediately below its owner, which
        // needs no check of its own: it makes the owner a path nobody declares.
        self::assertSame(
            TestSubjectPaths::UNKNOWN_OWNER,
            self::probe('tests/Nobody/Unit/RendererTest.php', self::RENDERER)['verdict'],
        );
        self::assertSame(
            TestSubjectPaths::UNKNOWN_OWNER,
            self::probe('tests/Probe/Widget/Unit/RendererTest.php', self::RENDERER)['verdict'],
        );
    }

    /**
     * Names what was judged and what was excused, in the two directions they
     * move in. The judged corpus is a floor: a file may not leave the scan
     * unnoticed, and a scan that read nothing would satisfy every refusal above.
     * Every excused path must be one the scan actually reached, or a row is
     * excusing a file that is not there.
     */
    #[Test]
    public function itJudgesEveryTestFileUnderTheTestsRoot(): void
    {
        $judged = self::judged();
        $population = TestSubjectPaths::population();

        // 616 today. A floor of 500 left room for a sixth of the tree to stop
        // being scanned without a word; this one leaves sixteen files, so a
        // discovery that quietly stopped walking is the failure it was meant to
        // be. Lowering it is a hand edit, which is the admission — and stage 05
        // will owe one if adjudicating list A retires more than sixteen files.
        self::assertGreaterThan(600, \count($population));
        self::assertSame($population, array_keys($judged));

        $unreached = [];
        foreach (SubjectPathExceptions::load() as $name => $list) {
            foreach (array_keys($list['rows']) as $path) {
                if (!isset($judged[$path])) {
                    $unreached[] = $path . ' is excused on ' . $name;
                }
            }
        }

        self::assertSame([], $unreached, \sprintf(
            "%d row(s) in %s name a file the scan never reached:\n%s",
            \count($unreached),
            SubjectPathExceptions::PATH,
            implode("\n", $unreached),
        ));
    }

    /**
     * @param list<string> $covered
     *
     * @return array{verdict: string, detail: string}
     */
    private static function probe(string $path, array $covered, bool $nothing = false): array
    {
        return TestSubjectPaths::judge($path, $covered, $nothing, self::PROBE_OWNERS, self::PROBE_DECLARATIONS);
    }

    private static function assertMissingRows(string $name, string $explanation): void
    {
        $tracked = SubjectPathExceptions::load()[$name]['rows'];
        $measured = SubjectPathExceptions::split(self::judged())[$name];

        $missing = [];
        foreach (array_diff_assoc($measured, $tracked) as $path => $detail) {
            $missing[] = $path . ' ' . $detail;
        }

        self::assertSame([], $missing, \sprintf(
            "%d test file(s) belong on %s and are not on it.\n%s\n%s",
            \count($missing),
            $name,
            $explanation,
            implode("\n", $missing),
        ));
    }

    /** @return array<string, array{verdict: string, detail: string}> */
    private static function judged(): array
    {
        return self::$judged ??= TestSubjectPaths::measure();
    }
}
