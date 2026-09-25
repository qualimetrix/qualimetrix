<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\DeclaredDelta;
use QmxFindingGate\FailureClass;
use RuntimeException;

/**
 * The channel renames controls plant in the product, and the declarations each one drags along.
 *
 * Shared on purpose: two controls built on one rename must not drift apart over which declarations they
 * carry.
 */
final class ChannelRenamePlants
{
    /**
     * The map a control declares: every row the step tracks, plus the control's
     * own.
     *
     * A control that writes the map whole has to write the step's rows too, and
     * copying them into this file would be a second, silently ageing copy of a
     * tracked declaration. They are read from the tracked file instead, so the
     * only thing stated here is what this control adds.
     *
     * Whole-file, not an insertion: {@see Mutation} refuses an edit whose own
     * anchor survives it, and an appended row leaves whatever it anchored on in
     * place. The reason `channels.tsv`'s step rows must survive is measured
     * rather than tidy — they declare the split that explains the producer move,
     * and without them the health surfaces the step declares a delta for fail as
     * `delta-overreach`, which for the green control means no green at all.
     *
     * `$file` is one of `RenameMaps`' declared map filenames; the "cannot read"
     * guard below is a defensive check on the read, not an emptiness check on
     * the file's content — a header-only map (every map A1 tracks starts that
     * way) reads as non-empty and is exactly the state this is meant to append
     * to.
     *
     * @param list<string> $rows tab-separated old, new, reason
     */
    public static function trackedMapPlus(string $file, array $rows, string $description): Mutation
    {
        $path = 'finding-gate/maps/' . $file;
        $tracked = @file_get_contents(\dirname(__DIR__, 2) . '/' . $path);

        if ($tracked === false || trim($tracked) === '') {
            throw new RuntimeException(\sprintf(
                'Cannot read %s, so a control cannot state its declaration on top of the step\'s own rows.',
                $path,
            ));
        }

        return Mutation::replace(
            [$path => rtrim($tracked, "\n") . "\n" . implode("\n", $rows) . "\n"],
            $description,
        );
    }

    /**
     * {@see trackedMapPlus()}, fixed to `channels.tsv`.
     *
     * @param list<string> $rows tab-separated old, new, reason
     */
    public static function trackedChannelMapPlus(array $rows, string $description): Mutation
    {
        return self::trackedMapPlus('channels.tsv', $rows, $description);
    }

    /** The scope the `bin/qmx rules` listing is captured under. {@see \QmxFindingGate\TreeRun::rules()}. */
    public const PRODUCER_LISTING_SURFACE = 'tree|rules';

    /**
     * The `qmx rules` toleration a control whose mutation moves anything the
     * listing prints needs — a producer name, or a channel code, since
     * `RulesCommand` prints both: a producer's own name, and each channel it
     * judges (`<channel> judges <metric>`). Three controls use it: the two
     * built on {@see unusedPrivateChannelMutation()}, which renames a producer,
     * and {@see RenameControls::renameWithoutMap()}, built on {@see lcomChannelMutation()},
     * which renames a channel while leaving the producing rule's name alone.
     * {@see RenameControls::referenceInputUntranslated()} touches neither: measured on
     * `bin/qmx rules` captured before and after its one-literal edit,
     * byte-identical.
     *
     * {@see DeclaredDeltaControls::deltaOverreach()} shares {@see lcomChannelMutation()} but does NOT
     * use this helper, even though it qualifies by mutation: its own
     * {@see DeclaredDeltaControls::declare()} call replaces the whole declared-delta index in the
     * scratch tree, so that control's `tree|rules` toleration can never depend
     * on the repository's tracked declaration — see the docblock on
     * {@see DeclaredDeltaControls::deltaOverreach()} for why it hardcodes the expectation instead.
     *
     * Whether the reach is a `surface-mismatch` is not a property of the
     * mutation: it is a property of the step under test. A step that declares a
     * delta for the listing has that surface compared against its exact diff and
     * never for equality, so the run reports a delta class there and
     * {@see Outcome::isDeclarationNoise()} absorbs it — and a toleration would
     * then match nothing and fail the control as an unmeasured radius
     * ({@see Outcome::idleTolerations()}). A step that declares nothing gets the
     * plain surface diff, which without a toleration is an unexplained failure.
     * Both readings occur on valid inputs: a delta may be declared or absent,
     * and a toleration pinned to either answer is wrong for the other.
     *
     * So the answer is read from the step's own tracked declaration, the way
     * {@see trackedChannelMapPlus()} reads its rows — through the gate's own
     * loader, and from the repository rather than a scratch tree, exactly as
     * {@see Harness::declaredSurfaces()} does. Membership is exact because that
     * is the rule both the absorber and {@see Control::assertNotPinnedToDeclaredDelta()}
     * apply, and because this pin names one whole surface rather than a prefix
     * of several.
     *
     * One gap is named rather than closed: the answer comes from the
     * repository's tracked declaration, so it does not know about a control
     * whose OWN mutation writes to `declared-delta.tsv` — the index it reads
     * and the index the scratch tree ends up with would then be two different
     * files. Neither control built on {@see unusedPrivateChannelMutation()}
     * touches `declared-delta.tsv` at all, so both stay eligible. A control
     * that does touch it — {@see DeclaredDeltaControls::deltaOverreach()} is the one case today — must
     * not call this helper at all, for either surface it could name: if it
     * plants a declaration for `tree|rules` itself, the toleration would match
     * nothing and fail the control as idle ({@see Outcome::idleTolerations()});
     * if it plants one for a DIFFERENT surface, {@see DeclaredDeltaControls::declare()} still replaces
     * the whole index, so `tree|rules` is unconditionally undeclared in the
     * scratch tree regardless of what the repository tracks, and reading the
     * repository would silently drift from that truth the day the repository
     * starts tracking a `tree|rules` delta of its own. Such a control derives
     * its expectation from what it itself plants, not from what the repository
     * tracks — a hardcoded {@see Expectation}, not this helper.
     *
     * @return list<Expectation>
     */
    public static function producerListingToleration(): array
    {
        $root = \dirname(__DIR__, 2) . '/finding-gate';

        $declared = is_file($root . '/' . DeclaredDelta::INDEX)
            ? DeclaredDelta::load($root)->surfaces()
            : [];

        return \in_array(self::PRODUCER_LISTING_SURFACE, $declared, true)
            ? []
            : [new Expectation(FailureClass::SURFACE_MISMATCH, self::PRODUCER_LISTING_SURFACE)];
    }

    /**
     * What has to be re-declared when {@see unusedPrivateChannelMutation()}
     * renames the channel: the case's claim and the tracked declaration fixture.
     *
     * Neither is evidence about the channel — both are declarations of it — so a
     * control that left them stale would fail on the claim check and the witness
     * and say nothing about the mechanism it is for. Shared by every control
     * built on that rename so the three cannot drift apart over which
     * declaration they carry.
     *
     * The step's derived declarations are the third, and they were missing until
     * a step declared a delta for `tree|rules`: the measured diff names whatever
     * rules fell inside its hunks, so leaving it stale failed one control on the
     * declaration rather than on the mechanism — and left its twin green, decided
     * by nothing but which rule the hunks happened to cover.
     * {@see Mutation::renameInDerivedDeclarations()} carries it, and carries it
     * unconditionally: a rename that finds nothing there is correct, because what
     * a derived declaration contains is not a control's business.
     */
    public static function unusedPrivateRenameDeclarations(): Mutation
    {
        return Mutation::edit(
            'governance/Channel/Fixtures/declared.txt',
            ['code-smell.unused-private higher class' => 'code-smell.unused-privat2 higher class'],
            'the tracked declaration fixture names the new channel',
        )->and(Mutation::edit(
            'finding-gate/cases/smells/case.json',
            ['"code-smell.unused-private@class"' => '"code-smell.unused-privat2@class"'],
            'the case claims the new channel',
        ))->and(Mutation::renameInDerivedDeclarations(
            ['code-smell.unused-private' => 'code-smell.unused-privat2'],
            'any derived declaration that names the channel names the new one',
        ));
    }

    /**
     * The channel rename both fingerprint controls apply: one product edit, and
     * the channel code, its declaration key and the published `rule` field all
     * move with it.
     *
     * The rule's `NAME` is that one place — the declaration key, the emitted
     * `code` and the emitted `ruleName` all read it — so renaming it renames the
     * channel without letting the two published fields drift apart. A code-only
     * rename is not expressible: a whole-name row would go on to rewrite the
     * `rule` field the mutation had left alone. Measured on the green control:
     * that variant failed on the smells case's `html`, `json` and `text-verbose`
     * surfaces and on `tree|rules`.
     *
     * **The new name is the same length as the old one, and that is load-bearing
     * rather than tidy.** `qmx rules` and `--format=text-verbose` pad the channel
     * column to a fixed width, so a name one character longer shifts the text
     * beside it by one space — a shift no row can declare, because a row
     * translates a name and not the padding after it. Measured on `qmx rules`
     * alone: `code-smell.unused-private2` leaves exactly one line differing by
     * one space, and `code-smell.unused-privat2` leaves the whole output
     * identical under a single substitution. That is also why this is the shape
     * a real step's rename has to have, or declare a delta for.
     *
     * A private mutation rather than a second caller of {@see
     * lcomChannelMutation()}: the fingerprint pair needs a case that declares no
     * delta, and the two controls that share the lcom mutation both pin their
     * expectations to the whole `case:complexity`, where the declared sarif
     * surface is one format among twelve and cannot swallow the control.
     */
    public static function unusedPrivateChannelMutation(): Mutation
    {
        return Mutation::edit(
            'src/Analysis/Evidence/CodeSmell/UnusedPrivateRule.php',
            ["public const string NAME = 'code-smell.unused-private';" => "public const string NAME = 'code-smell.unused-privat2';"],
            'channel code-smell.unused-private -> code-smell.unused-privat2, its code and published rule field together',
        );
    }

    /**
     * The channel rename, shared by the map control and the overreach control.
     *
     * The declaration-side fragment was `ChannelDeclaration::magnitude(` when
     * this control was written; `LcomRule` later moved onto
     * `ChannelDeclaration::judging(` without changing the shape this control
     * relies on — the key is still `self::NAME` on its own line, immediately
     * before the factory call. `LcomRule.php` is the only file this mutation
     * touches; `LcomVisitor.php` declares
     * neither `self::NAME` nor `ChannelDeclaration`, so a future rename there
     * cannot collide with this fragment.
     *
     * The renamed channel also gets `describedAs()`, because the product refuses
     * the rename without it: a channel not named after its producer must carry
     * its own description (ADR 0081), and a container that does not compile
     * stops the gate at its channel probe, before any comparison. The text is
     * the producer's own `getDescription()`, so every published description
     * stays byte-identical and the channel name remains the only thing moved.
     */
    public static function lcomChannelMutation(): Mutation
    {
        return Mutation::edit(
            'src/Analysis/Evidence/Cohesion/LcomRule.php',
            [
                'self::NAME => ChannelDeclaration::judging(' => "'cohesion.lcom4' => ChannelDeclaration::judging(",
                "                SymbolLevel::Class_,\n            ),\n        ];"
                    => "                SymbolLevel::Class_,\n            )->describedAs("
                    . "'Checks Lack of Cohesion of Methods (high values indicate class should be split)'),\n        ];",
                'code: self::NAME,' => "code: 'cohesion.lcom4',",
            ],
            'channel cohesion.lcom -> cohesion.lcom4, described in its producer\'s own words, the producing rule name left alone',
        );
    }
}
