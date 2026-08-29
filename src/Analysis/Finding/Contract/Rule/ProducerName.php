<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;

/**
 * The grammar every registered producer's name obeys.
 *
 * {@see RuleFamily} refuses a name with no family at all, and that was as far
 * as the check could go while `computed.branch_load` was a legal producer: a
 * strict pattern would have refused a name the corpus registers. Ш5e3 made
 * every producer name lower-case kebab, so the whole form can be held here
 * instead of its first segment.
 *
 * What the widening buys is measured rather than assumed. `Complexity.Foo` used
 * to register, print under a heading of its own, and then not be found by
 * `--group=complexity`, the filter being case-sensitive — a producer invisible
 * to the one option that addresses its family. A trailing separator, a doubled
 * separator and a segment containing a space did the same.
 */
final class ProducerName
{
    /**
     * One or more lower-case kebab segments separated by dots.
     *
     * Written once, as a constant, because the test that pins it reads this
     * value: a grammar restated in a test is a second grammar, and the two
     * would agree only until one of them moved.
     */
    public const string TEMPLATE = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$/';

    /**
     * @throws LogicException when the name does not obey the grammar. Raised
     *                        where the container is assembled, so a malformed
     *                        producer never reaches a listing, a heading or a
     *                        published finding
     */
    public static function assertWellFormed(string $producerRuleName): void
    {
        if (preg_match(self::TEMPLATE, $producerRuleName) === 1) {
            return;
        }

        throw new LogicException(\sprintf(
            'Producer "%s" is not a well-formed name. Every segment is lower-case kebab and segments are'
            . ' separated by single dots (%s).',
            $producerRuleName,
            self::TEMPLATE,
        ));
    }

    private function __construct() {}
}
