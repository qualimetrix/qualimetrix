<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\DeclaredDelta;
use QmxFindingGate\FailureClass;
use RuntimeException;

/**
 * The controls on the declared delta: a declaration that does not match, is stale, reaches a compared
 * field or is too large, a licensed field move nothing performs, and what a derivation writes.
 */
final class DeclaredDeltaControls
{
    /**
     * A licensed move of a compared field that no diff line performs.
     *
     * `declared-field-moves.tsv` is the second source of permission
     * `delta-overreach` consults, and a row in it is the only thing that can
     * let a compared field differ inside a declared diff. A row describing a
     * move that did not happen is the same lie as a stale map row, and until
     * something has been seen refusing one, "the licence is narrow" is a claim
     * about code nobody has watched fire.
     *
     * The index is REPLACED rather than added to, exactly as {@see declare()}
     * replaces the delta index: the planted row has to be the only one whether
     * or not the step under test declares moves of its own. Replacing it also
     * removes the step's own licence, so the move that *does* happen goes back
     * to being `delta-overreach` on a surface the step declares — absorbed as
     * declaration noise, and not this control's subject.
     *
     * No product code is perturbed: the licence is the whole breakage.
     */
    public static function fieldMoveStale(): Control
    {
        $surface = 'case:smells|format:json';

        return Control::red(
            'field-move-stale',
            'a move of a compared field licensed where no diff line performs it',
            Mutation::replace(
                [
                    'finding-gate/declared-field-moves.tsv' => "surface\tfield\tfrom\tto\treason\n"
                        . $surface . "\tmessage\ta value nothing published\tnor did anything publish this one"
                        . "\ta move licensed where nothing moved\n",
                ],
                'a field move licensed on ' . $surface . ' that no run performs',
            ),
            [new Expectation(FailureClass::FIELD_MOVE_STALE, $surface)],
        );
    }

    /**
     * A derivation whose own comparison failed must leave the tracked
     * declaration exactly as it found it.
     *
     * The gate has always *said* so — "the run this declaration would be derived
     * from failed, so nothing was written" — and until this control it said so
     * after having rewritten the index and every diff file. Measured on
     * 2026-09-04: a full derive over a tree with one finding dropped exited 5,
     * printed that sentence, and replaced a planted declaration with thirteen
     * derived rows whose reasons were `?`.
     *
     * So the assertion cannot live in the report: the report is what lied. It is
     * the two paths the write would touch, digested before the run and after it.
     * The failure class is required as well, because a derivation that failed
     * for some *other* reason would leave the tree alone for a reason this
     * control is not about.
     */
    public static function deriveRefusesBrokenRun(): Control
    {
        return Control::writing(
            'derive-refuses-broken-run',
            'a --derive-declared-delta run whose comparison failed, which must write nothing',
            FindingControls::droppedFindingMutation(),
            '--derive-declared-delta',
            [new Expectation(FailureClass::FINDING_COUNT_MISMATCH, 'case:design')],
            ['finding-gate/' . DeclaredDelta::INDEX, 'finding-gate/' . DeclaredDelta::DIRECTORY],
        );
    }

    /**
     * A derivation whose comparison passed must put the declaration back.
     *
     * The mirror of {@see deriveRefusesBrokenRun()}, and the half nothing held.
     * That control proves a failed derivation writes nothing; a derivation
     * emptied to `return []` after the comparison satisfies it exactly — the
     * comparison still fails, the tree is still untouched — and satisfies the
     * self-test too, which never enters the write path. A check green before and
     * after the change it exists to catch is not a check.
     *
     * The perturbation is a comment line in the index, and it is the only shape
     * that works. A correct derivation over an unmutated tree reproduces the
     * tracked declaration byte for byte, so "the file changed" sees nothing;
     * every perturbation the loader *reads* turns the comparison red and the
     * derivation refuses. A comment is skipped by {@see \QmxFindingGate\Tsv} and
     * cannot survive a rewrite, so the run's own output is the difference
     * between the mutated file and the repository's.
     *
     * It is appended rather than typed over the rows, so the control does not
     * have to be re-typed each time the declaration changes. When a round
     * empties the declaration the assertion still holds — a derivation with
     * nothing to declare writes the header alone, and the comment is gone from
     * that too. When the declaration is retired entirely,
     * {@see declaredDeltaIndexOrHeader()} reads the same header the
     * loader would: `Mutation::append()` requires an existing target, so the
     * comment is appended to that header via
     * {@see declaredDeltaIndexWrite()} rather than to a file this repository
     * does not currently track.
     */
    public static function deriveWritesOnAGreenRun(): Control
    {
        return Control::rewriting(
            'derive-writes-green-run',
            'a --derive-declared-delta run whose comparison passed, which must write the declaration back',
            self::declaredDeltaIndexWrite(
                self::declaredDeltaIndexOrHeader() . "# planted: a line the loader skips and a derivation cannot reproduce\n",
                'a comment in the declaration index that only a real rewrite removes',
            ),
            '--derive-declared-delta',
            ['finding-gate/' . DeclaredDelta::INDEX, 'finding-gate/' . DeclaredDelta::DIRECTORY],
            // This repository's declared-delta.tsv holds no declared rows, so
            // its own file cannot state "a correct run restores the header
            // alone" — see Control::rewriting()'s $restoredContent.
            ['finding-gate/' . DeclaredDelta::INDEX => self::declaredDeltaIndexOrHeader()],
        );
    }

    /**
     * A declared delta that does not state the difference it covers.
     *
     * The product perturbation is the ceiling control's, because it is the one
     * whose blast radius is already measured. The declaration planted next to it
     * covers the baseline file — where the perturbation lands — with a diff no
     * measurement produced. A delta the gate does not recompute would make every
     * later step's delta a rubber stamp, so the mismatch has to be a failure of
     * its own rather than an absent surface diff.
     */
    public static function deltaMismatch(): Control
    {
        return Control::red(
            'delta-mismatch',
            'a declared delta whose diff is not the one the run measures',
            FindingControls::ceilingMutation()->and(self::declare(
                'case:smells|baseline-file',
                'the ceiling control\'s perturbation, declared with a diff nothing measured',
            )),
            [new Expectation(FailureClass::DELTA_MISMATCH, 'case:smells|baseline-file')],
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells')],
        );
    }

    /**
     * A declared delta on a surface the two trees agree on.
     *
     * No product code is perturbed, so this is the positive control with the
     * declaration *replaced*: one row added on a surface that does not differ,
     * and the step's own rows removed with it. Both halves show up in the run —
     * the added row as `delta-stale`, the removed ones as the declared surfaces
     * being compared for equality again — which is why this control tolerates
     * them there (see Outcome::isDeclarationNoise()). The lie under test is the
     * added row: the same lie as a map row that translated nothing, and it has
     * to fail the same way or a delta could outlive the change it described.
     */
    public static function deltaStale(): Control
    {
        return Control::red(
            'delta-stale',
            'a delta declared for a surface that did not change',
            self::declare('case:smells|baseline-file', 'a delta declared where nothing differs'),
            [new Expectation(FailureClass::DELTA_STALE, 'case:smells|baseline-file')],
        );
    }

    /**
     * A declared delta reaching a field the equivalence tuple compares.
     *
     * The channel rename is the mutation, because the half it moves *is* the
     * `code` field of every finding it produces — and with no split declared,
     * nothing explains that record. Without this seam the first user of a
     * declared delta would have had to breach it: the plan counts nine bare
     * occurrences of one renamed half across `json` and `html`, all of them the
     * `rule` field. The delta is also not the measured one, which is tolerated on
     * that same surface: reach is judged on what the run measures, so a
     * declaration that overreaches must fail for overreaching rather than be
     * excused by also failing to match.
     *
     * It shares the rename control's mutation, so it shares that control's two
     * tolerations that never fired and the one that does — see
     * {@see RenameControls::renameWithoutMap()} — for the same measured reasons, stated there.
     *
     * `tree|rules` is tolerated unconditionally here rather than through
     * {@see ChannelRenamePlants::producerListingToleration()}, and that is not a stylistic choice:
     * this control's own {@see declare()} call REPLACES the whole declared-delta
     * index with its one `case:complexity|format:json` row (see the docblock
     * above {@see declare()}), so the scratch tree this control measures can
     * never carry a repository-tracked `tree|rules` declaration, no matter what
     * the repository holds. {@see ChannelRenamePlants::producerListingToleration()} reads the
     * repository's tracked index, which is a different source from the one this
     * control's own mutation leaves on disk — using it here would make the
     * expectation agree with the scratch tree only by the repository's
     * coincidence of currently declaring no delta at all, and diverge silently
     * the day a real step commits one for `tree|rules`.
     */
    public static function deltaOverreach(): Control
    {
        return Control::red(
            'delta-overreach',
            'a declared delta covering a compared field with no split to explain it',
            ChannelRenamePlants::lcomChannelMutation()->and(self::declare(
                'case:complexity|format:json',
                'a renamed code half declared as a delta instead of a map row',
            )),
            [new Expectation(FailureClass::DELTA_OVERREACH, 'case:complexity|format:json')],
            [
                new Expectation(FailureClass::DELTA_MISMATCH, 'case:complexity|format:json'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:complexity'),
                new Expectation(FailureClass::CASE_CLAIM_MISMATCH, 'case:complexity'),
                new Expectation(
                    FailureClass::WITNESS_DISAGREEMENT,
                    'governance/Channel/Fixtures/declared.txt',
                ),
                new Expectation(FailureClass::SURFACE_MISMATCH, ChannelRenamePlants::PRODUCER_LISTING_SURFACE),
            ],
        );
    }

    /**
     * A declared delta bigger than a declaration may be.
     *
     * The perturbation is a formatter, not a rule: `JsonFindingSection` gains
     * a field on every finding, so every line of the `json` surface of a case
     * moves and the measured diff runs to hundreds of changed lines. The
     * declaration planted beside it names that surface, so the run reaches the
     * size check rather than stopping at "undeclared surface" — and the size
     * check is the whole point, because it is the class this harness had never
     * seen red.
     *
     * The `health` case is the target because it is the largest: two moved
     * lines per finding over its 69 message-bearing findings measure 256 changed
     * lines against a limit of 200, so the control is not sitting on the edge of
     * the threshold it is testing. It is also the one case NOT tolerated for a
     * surface diff: its `json` surface is the declared one, so the failures
     * there are delta classes, and the twelve tolerations name the twelve other
     * cases whose `json` surface moves with the formatter. That toleration was
     * declared and never fired; measured on a full PASS run, 2026-08-24.
     *
     * `delta-mismatch` and `delta-overreach` are tolerated on the same surface
     * for the reason the overreach control gives in reverse: reach and size are
     * judged on the diff the run measures, so a declaration that is too large
     * must fail for being too large and not be excused by also failing to match
     * — and a diff this wide inevitably pairs a moved field against a line that
     * does not carry it, which is overreach by the record-level rule.
     */
    public static function deltaTooLarge(): Control
    {
        return Control::red(
            'delta-too-large',
            'a declared delta whose measured diff is past the limit a declaration may be',
            Mutation::edit(
                'src/Reporting/Formatter/Json/JsonFindingSection.php',
                [
                    "'message' => \$finding->message," => "'message' => '(padded) ' . \$finding->message,",
                    "'recommendation' => \$finding->recommendation," => "'recommendation' => '(padded) ' . \$finding->recommendation,",
                ],
                'two lines of every JSON finding move, which on the largest case is past the declaration limit',
            )->and(self::declare(
                'case:health|format:json',
                'a delta declared for a surface whose measured diff is hundreds of lines',
            )),
            [new Expectation(FailureClass::DELTA_TOO_LARGE, 'case:health|format:json')],
            [
                new Expectation(FailureClass::DELTA_MISMATCH, 'case:health|format:json'),
                new Expectation(FailureClass::DELTA_OVERREACH, 'case:health|format:json'),
                ...self::surfaceMismatchOnEveryCaseButHealth(),
            ],
        );
    }

    /**
     * The mutation moves two lines of every JSON finding, so every case's JSON
     * surface differs; only `health` is big enough to pass the declaration
     * limit, and only it is required. The rest are tolerated.
     *
     * Derived from the corpus rather than listed, and that is the whole point:
     * a hand-written list omitted `rule-exclusion-ledger`, causing the control
     * to fail on a surface the mutation explains perfectly well —
     * "failure(s) the mutation does not explain" pointing at a case the
     * declaration had simply never heard of. A case is a directory holding a
     * `case.json`, the same definition {@see \QmxFindingGate\Corpus::load()}
     * uses, so a corpus that grows again does not invalidate this control.
     *
     * @return list<Expectation>
     */
    private static function surfaceMismatchOnEveryCaseButHealth(): array
    {
        $root = \dirname(__DIR__, 2) . '/finding-gate/cases';
        $entries = scandir($root);

        if ($entries === false) {
            throw new RuntimeException(\sprintf('No corpus at %s, so this control cannot state its blast radius.', $root));
        }

        // Every case here keeps its toleration even where the step under test
        // declares a delta for that case's JSON surface: this control replaces
        // the declaration index, so those surfaces are compared for equality
        // again and the mutation moves them like any other.
        $tolerated = [];

        foreach ($entries as $entry) {
            if ($entry === 'health' || !is_file($root . '/' . $entry . '/case.json')) {
                continue;
            }

            $tolerated[] = new Expectation(FailureClass::SURFACE_MISMATCH, 'case:' . $entry);
        }

        return $tolerated;
    }

    /**
     * The declared-delta index's current bytes, or just its header if the
     * tracked file is missing.
     *
     * README states the invariant: the index is tracked like
     * `declared-field-moves.tsv` and may hold only its header row, while the
     * `declared-delta/` directory appears only while the index holds at least
     * one declared row. A control cannot assume the index carries any rows —
     * this tree's own is header-only — so the fallback covers the
     * hypothetical case where the tracked file is absent entirely.
     */
    private static function declaredDeltaIndexOrHeader(): string
    {
        $path = \dirname(__DIR__, 2) . '/finding-gate/' . DeclaredDelta::INDEX;
        $tracked = is_file($path) ? file_get_contents($path) : false;

        return $tracked !== false ? $tracked : implode("\t", DeclaredDelta::COLUMNS) . "\n";
    }

    /**
     * Writes `finding-gate/declared-delta.tsv`'s whole content, whether or not
     * the repository holds one today.
     *
     * `Mutation::replace()` requires the target to already exist and
     * `Mutation::create()` refuses one that does — opposite preconditions for
     * opposite states of the same fact, so which of the two applies is read
     * here once rather than assumed by each caller. Every control that plants a
     * declaration over this file goes through this one method.
     */
    private static function declaredDeltaIndexWrite(string $content, string $description): Mutation
    {
        $path = 'finding-gate/' . DeclaredDelta::INDEX;

        return is_file(\dirname(__DIR__, 2) . '/' . $path)
            ? Mutation::replace([$path => $content], $description)
            : Mutation::create([$path => $content], $description);
    }

    /**
     * Plants a declared delta for one surface: the index row plus a diff file no
     * measurement produced.
     */
    /**
     * One declaration, and only it: the index is replaced, so the step's own
     * declared surfaces are removed by the same call. That is deliberate — a
     * control on the declaration mechanism must not also be judged against the
     * step's declaration — and it is why the delta controls' reports carry
     * tolerated failures on those surfaces.
     */
    private static function declare(string $surface, string $reason): Mutation
    {
        // `control-` prefixed on purpose: a step may track a diff for the very
        // surface a control plants one for, and Mutation refuses to CREATE a
        // file the repository already has. A tracked
        // `case:health|format:json` diff can collide with delta-too-large's slug
        // and would have crashed that control at mutation time.
        $slug = trim((string) preg_replace('~[^A-Za-z0-9]+~', '-', $surface), '-');
        $file = 'declared-delta/control-' . $slug . '.diff';

        // The index holds this one row and nothing else: a step that declares a
        // delta of its own already committed one, and a control's declaration
        // has to be the only row in it whether or not that is so.
        return self::declaredDeltaIndexWrite(
            "surface\tfile\treason\n" . $surface . "\t" . $file . "\t" . $reason . "\n",
            'a delta declared for ' . $surface,
        )->and(Mutation::create(
            [
                'finding-gate/' . $file => "--- candidate\n+++ reference (mapped)\n@@ -1,1 +1,1 @@\n"
                    . "-a line no measurement produced\n+nor did it produce this one\n",
            ],
            'with a diff no measurement produced',
        ));
    }
}
