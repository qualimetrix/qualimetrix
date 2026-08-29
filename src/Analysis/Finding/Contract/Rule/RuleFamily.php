<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;

/**
 * The family a producer belongs to: the first dot-separated segment of its
 * name, and the label `qmx rules` prints its group heading from.
 *
 * **The only place a producer name is split into segments for this purpose.**
 * A family used to be a second vocabulary — an enum each rule declared beside
 * its name — and the two never disagreed on any registered producer, so the
 * declaration carried no fact the name did not already hold. Deriving it here,
 * once, is what keeps that agreement true by construction instead of by
 * convention; deriving it at each consumer would spread the same split across
 * the code the way a level suffix once was.
 *
 * A family is a **label**, not an address, in one exact sense: it decides
 * nothing about findings. No directive resolves against it, no rule selector
 * matches it, no channel exclusion consults it, and no finding is added or
 * removed by it — group addressing is written `complexity.*` and is parsed by
 * {@see NameSelector}, which reads the whole name and not a first segment.
 * That is the difference from the group matcher this project removed, whose
 * derived membership decided what a directive applied to.
 *
 * What does read it is `qmx rules`, and only for display: the `--group`
 * filter narrows the listing to one family, and it compares against the very
 * value the heading is printed from, so the two can never name different
 * sets. The comparison is exact and case-sensitive; a `--group` no producer
 * has lists nothing and exits 0.
 */
final class RuleFamily
{
    private const string SEPARATOR = '.';

    /**
     * A dotless name is its own family — the open computed-metric producer
     * (`computed`) is registered exactly that way, and printing it under an
     * empty heading would be a silent answer to a question the listing asks
     * of every producer.
     *
     * @throws LogicException when the name yields no family at all, i.e. it is
     *                        empty or starts with the separator. Raised where
     *                        the container is assembled, so such a producer
     *                        never reaches a listing
     */
    public static function of(string $producerRuleName): string
    {
        $separatorAt = strpos($producerRuleName, self::SEPARATOR);
        $family = $separatorAt === false
            ? $producerRuleName
            : substr($producerRuleName, 0, $separatorAt);

        if ($family === '') {
            throw new LogicException(\sprintf(
                'Producer "%s" has no family: its name must start with a non-empty dot-separated segment,'
                . ' which is what `qmx rules` groups it under.',
                $producerRuleName,
            ));
        }

        return $family;
    }
}
