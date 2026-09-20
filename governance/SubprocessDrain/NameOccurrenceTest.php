<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SubprocessDrain;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/NameOccurrence.php';

/**
 * The scan both controls in this group read the tree through.
 *
 * Each control keeps its own case over its own needles, and this is not a third
 * copy of those. The difference is what the evidence rests on. A control
 * observes the scan through whatever the tree happens to spell today: change
 * how the line is counted and the drain control does go red, but only because
 * one entry is anchored at a nowdoc in a file that exists right now, and that
 * evidence leaves with the file. The cases here put the same properties in
 * text this class chooses, so they survive the tree changing — a name inside a
 * nowdoc, a name inside a string literal, the line reported when the token
 * began several lines above, and the two refusals.
 *
 * The names used here are deliberately not the ones the controls search for.
 * This file is in the scanned population like any other, and a control's needle
 * written whole in a literal here would make this file an occurrence of it.
 */
final class NameOccurrenceTest extends TestCase
{
    private const NAME = 'needle_name';

    #[Test]
    public function itFindsANameWhateverCaseItIsWrittenIn(): void
    {
        $source = '<?php' . "\n"
            . '$a = Needle_Name();' . "\n"
            . '$b = NEEDLE_NAME();' . "\n"
            . '$c = needle_name();' . "\n";

        self::assertSame(
            ['Needle_Name', 'NEEDLE_NAME', 'needle_name'],
            array_map(
                static fn(NameOccurrence $occurrence): string => $occurrence->spelled,
                NameOccurrence::findIn($source, [self::NAME]),
            ),
            'PHP resolves these three as one name. A scan that folds no case sees one of them, and the controls '
            . 'reading this scan are then a spelling away from blind.',
        );
    }

    /**
     * The Kelvin sign is three bytes that `mb_strtolower` rewrites to one. It
     * is here because the spelling is read out of the original at the offset
     * found in the folded copy: swap the fold and every spelling after this
     * line comes back shifted, whatever the surrounding text. Asserting an
     * answer rather than the bytes would not show that — a shifted offset still
     * lands on some token.
     */
    #[Test]
    public function itReadsTheSpellingOutOfTheOriginalBytes(): void
    {
        $source = "<?php\n\$sign = '\u{212A}';\n" . '$a = Needle_Name();' . "\n";

        self::assertSame(
            ['Needle_Name'],
            array_map(
                static fn(NameOccurrence $occurrence): string => $occurrence->spelled,
                NameOccurrence::findIn($source, [self::NAME]),
            ),
            'The fold no longer preserves byte length, so offsets found in the folded copy read the original '
            . 'early. Every line number, token and spelling this scan reports is wrong by the bytes the fold '
            . 'lost, and the controls reading it would declare and refuse the wrong places.',
        );
    }

    /**
     * A token's own line is where the token starts, and a nowdoc token starts
     * several lines above the text inside it. The line therefore has to be
     * counted from the byte offset. Neither control can show this through its
     * own needles: the tree's one occurrence of this shape sits in a file whose
     * entry would keep matching either way.
     */
    #[Test]
    public function itCountsTheLineFromTheOffsetAndNotFromTheTokenItSitsIn(): void
    {
        $source = '<?php' . "\n"
            . '$code = <<<\'PHP\'' . "\n"
            . 'a line' . "\n"
            . 'another line' . "\n"
            . 'needle_name();' . "\n"
            . 'PHP;' . "\n";

        $found = NameOccurrence::findIn($source, [self::NAME]);

        self::assertCount(1, $found, 'A name inside a nowdoc is source the child will run, so it is an occurrence.');
        self::assertSame(
            5,
            $found[0]->line,
            'The line was taken from the token, which begins where the nowdoc opens. A refusal naming that line '
            . 'sends a reader several lines above the text it is about, and an entry anchored there declares a '
            . 'line that carries nothing.',
        );
    }

    #[Test]
    public function itExcusesACommentAndNothingElse(): void
    {
        $source = '<?php' . "\n"
            . '// needle_name() named in a line comment' . "\n"
            . '/** {@see needle_name()} in a docblock */' . "\n"
            . '$literal = \'needle_name()\';' . "\n"
            . '$a = needle_name();' . "\n";

        $found = NameOccurrence::findIn($source, [self::NAME]);

        self::assertSame(
            [4, 5],
            array_map(static fn(NameOccurrence $occurrence): int => $occurrence->line, $found),
            'Only a comment is excused, because a comment cannot execute. A string literal is not: the tree '
            . 'holds source inside a nowdoc that a spawned `php -r` runs, so excusing literals would excuse '
            . 'precisely the live one. Lines 2 and 3 must be absent and lines 4 and 5 present.',
        );
    }

    #[Test]
    public function itReportsTheTokenWithoutInterpretingIt(): void
    {
        $source = '<?php' . "\n" . '$a = needle_name();' . "\n" . '$b = \'needle_name\';' . "\n";

        $found = NameOccurrence::findIn($source, [self::NAME]);

        self::assertSame(
            [\T_STRING, \T_CONSTANT_ENCAPSED_STRING],
            array_map(static fn(NameOccurrence $occurrence): ?int => $occurrence->token?->id, $found),
            'The token is what lets a caller label an occurrence in its own terms. This scan does not label it, '
            . 'because what a name in a string literal means is the calling control\'s subject, not this one\'s.',
        );
    }

    /**
     * Both refusals are about the same thing: a caller asking a question this
     * scan cannot answer should hear so. Silence is the expensive answer,
     * because "nowhere" is true of a name that was never searched for, and a
     * control resting on it goes quietly green. This is not hypothetical — a
     * needle in this group was spelled `tokenAt(` and matched nothing, and only
     * a positive case failing revealed it.
     */
    #[Test]
    public function itRefusesANameThatIsNotAlreadyFolded(): void
    {
        $source = '<?php' . "\n" . '$a = Needle_Name();' . "\n";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Needle_Name');

        NameOccurrence::findIn($source, ['Needle_Name']);
    }

    /**
     * An empty name matches at every offset and advances the search by nothing,
     * so the loop never ends. A control that hangs instead of failing is the
     * exact defect this group exists to refuse, and it does not get to arrive
     * through the scan the group reads the tree with.
     */
    #[Test]
    public function itRefusesAnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NameOccurrence::findIn('<?php' . "\n", ['']);
    }
}
