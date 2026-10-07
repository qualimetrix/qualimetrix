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

    public static function deriveRefusesBrokenRun(): Control
    {
        $captureFailure = TupleControls::publisherDrift();
        return Control::writing(
            'derive-refuses-broken-run',
            'a --derive-declarations run with an invalid captured publication must leave ordinary delta declarations unchanged',
            $captureFailure->mutation,
            '--derive-declarations',
            $captureFailure->required,
            ['finding-gate/' . DeclaredDelta::INDEX, 'finding-gate/' . DeclaredDelta::DIRECTORY],
            $captureFailure->tolerated,
        );
    }

    /**
     * A derivation whose comparison passed must put the declaration back.
     *
     * The mirror of {@see deriveRefusesBrokenRun()}, and the half nothing held.
     * That control proves an invalid capture leaves ordinary delta
     * declarations unchanged. A derivation emptied to `return []` after
     * comparison satisfies it: comparison still fails, the held declarations
     * remain untouched, and the self-test never enters the write path.
     * A check green before and after the change it exists to catch is not a check.
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
            'a --derive-declarations run whose comparison passed, which must write the declaration back',
            self::declaredDeltaIndexWrite(
                self::declaredDeltaIndexOrHeader() . "# planted: a line the loader skips and a derivation cannot reproduce\n",
                'a comment in the declaration index that only a real rewrite removes',
            ),
            '--derive-declarations',
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
            [new Expectation(FailureClass::DELTA_MISMATCH, 'case:smells|baseline-file'),
                new Expectation(FailureClass::RECORD_UNDECLARED, 'case:smells|format:json', exactScope: true)],
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells')],
        );
    }

    /**
     * An unrelated JSON envelope array exceeds the declaration's line limit
     * while physical findings and their complete ranking remain coherent.
     * The health case is declared; every other analysis publication still
     * differs and must be reported rather than swallowed by that declaration.
     */
    public static function deltaTooLarge(): Control
    {
        return Control::red(
            'delta-too-large',
            'a declared delta whose measured diff is past the limit a declaration may be',
            Mutation::edit(
                'src/Reporting/Formatter/Json/JsonFormatter.php',
                [
                    "'coverage' => \$report->coverage?->toArray(),"
                        => "'coverage' => (\$report->coverage?->toArray()), 'controlPadding' => array_fill(0, 250, 'envelope padding'),",
                ],
                'a report envelope array contributes 250 changed lines without modifying findings',
            )->and(self::declare(
                'case:health|format:json',
                'a delta declared for a surface whose measured diff is hundreds of lines',
            )),
            [new Expectation(FailureClass::DELTA_TOO_LARGE, 'case:health|format:json')],
            [
                new Expectation(FailureClass::DELTA_MISMATCH, 'case:health|format:json'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:health|check:output:file', exactScope: true),
                ...self::surfaceMismatchOnEveryCaseButHealth(),
            ],
        );
    }

    /** @return list<Expectation> */
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
            if ($entry === 'health' || !is_file($root . '/' . $entry . '/case.json')
                || \QmxFindingGate\CaseDefinition::load($root . '/' . $entry)->outcome === \QmxFindingGate\CaseOutcome::REFUSAL) {
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
