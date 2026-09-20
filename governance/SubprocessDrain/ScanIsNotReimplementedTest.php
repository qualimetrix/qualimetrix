<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SubprocessDrain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/NameOccurrence.php';
require_once __DIR__ . '/PhpFilePopulation.php';

/**
 * {@see NameOccurrence} is the only file in this group that searches PHP text.
 *
 * Every other control here reads the tree through it, and until this control
 * existed that was a habit rather than a property. Each control's own case
 * proves what its scan *does* — that case is folded, that the bytes come back
 * out of the original — which a re-implementation satisfies as easily as a
 * call does: measured, putting the old mechanism back inside one control
 * verbatim left all of this group's cases green. That is the shape where a
 * check coincides with the property it is supposed to guard and lets through
 * exactly the harm it was put there to stop.
 *
 * So the property is checked directly, by absence: outside the scan, no file in
 * this group folds a file's case or tokenizes PHP for itself. The two controls
 * went back to one mechanism because two copies drift; this is what keeps a
 * third from being written.
 *
 * One file is exempt by name rather than by pattern, because an exemption that
 * matched a shape would grow to fit whatever is written next.
 *
 * The needles name the two forms that were actually duplicated — folding a
 * file's contents, and finding the token a byte offset falls in — and not
 * tokenizing as such. Tokenizing is a legitimate thing to do here for another
 * subject: the caller scan reads `require_once` expressions token by token,
 * asking what an expression resolves to rather than where a name is written. A
 * needle naming the tokenizer refused that on its first run, which is how the
 * distinction got measured rather than assumed.
 *
 * So this is a fence around two known forms, not a proof that no third is
 * possible. A search written some other way passes, and the case below keeps
 * the needles honest only about matching the scan itself.
 */
final class ScanIsNotReimplementedTest extends TestCase
{
    /**
     * Spelled in halves: this file is in the population it scans, and a whole
     * spelling in a literal here would make this control its own violation.
     * Lower case, because the scan searches a folded copy and now refuses a
     * needle that is not folded — this constant was written `tokenAt(` first
     * and matched nothing, which is what that refusal is for.
     *
     * @var list<string>
     */
    private const SEARCH_MECHANISM = ['strtolower' . '($contents)', 'function ' . 'tokenat('];

    private const SCAN = 'NameOccurrence.php';

    #[Test]
    public function itFindsTheScanItselfSoTheNeedlesAreKnownToMatch(): void
    {
        $scan = (string) file_get_contents(__DIR__ . '/' . self::SCAN);

        foreach (self::SEARCH_MECHANISM as $needle) {
            self::assertNotSame(
                [],
                NameOccurrence::findIn($scan, [$needle]),
                'The needle "' . $needle . '" no longer matches the scan itself, so it would match a copy of the '
                . 'scan either. Either the scan was rewritten in some other form — in which case this control '
                . 'now guards nothing and the needle has to follow it — or the needle is misspelled.',
            );
        }
    }

    #[Test]
    public function itRefusesAFileInThisGroupThatSearchesPhpTextItself(): void
    {
        $group = 'governance/SubprocessDrain/';
        $reimplemented = [];

        foreach (PhpFilePopulation::paths() as $path) {
            if (!str_starts_with($path, $group) || $path === $group . self::SCAN) {
                continue;
            }

            $contents = (string) file_get_contents(PhpFilePopulation::root() . '/' . $path);

            foreach (NameOccurrence::findIn($contents, self::SEARCH_MECHANISM) as $occurrence) {
                $reimplemented[] = $path . ':' . $occurrence->line . ' — ' . $occurrence->spelled;
            }
        }

        self::assertSame(
            [],
            $reimplemented,
            'A file in this group searches PHP text on its own instead of through ' . self::SCAN . '. That is how '
            . 'the two copies this group used to carry came about: they agreed when they were written and had to '
            . 'be repaired separately afterwards, once each. A control\'s own case cannot refuse this — a copy '
            . 'that behaves the same passes it — which is why the refusal lives here.',
        );
    }
}
