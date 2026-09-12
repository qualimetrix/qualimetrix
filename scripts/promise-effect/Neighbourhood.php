<?php

declare(strict_types=1);

/**
 * The coordinate no axis owns: the FORM of one key beside its NEIGHBOUR.
 *
 * Axes A and D ask what form a key may take, always alone. Axis B asks what
 * two keys do side by side, always at the canonical magnitude and never at
 * `~`. The cell "`~` next to a neighbour" belongs to neither, and two effects
 * measured by the previous round live in exactly that cell: a bare `warning: ~`
 * beside a `class:` block throws the block away without a word, and a
 * `threshold: ~` beside `warning:` refuses for a mixing that the `~` did not
 * commit.
 *
 * The population is a PRODUCT and not a list of interesting cases. One factor
 * is read off the product's own declarations — a key whose declared shape
 * accepts `null` is a key whose `~` the reader has to decide something about.
 * The other is `key-pairs.tsv`, stage 01's frozen enumeration of which keys of
 * a rule interact at all. Picking cases by hand is how six spellings of one
 * name surfaced one per review round in X12; the size is printed instead.
 */

namespace Qualimetrix\PromiseEffect;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;

/** One cell of the coordinate: this rule's key, written `~`, beside this neighbour. */
final readonly class NeighbourhoodRow
{
    public function __construct(
        public string $rule,
        public string $nullKey,
        public string $neighbour,
        public string $pairKind,
    ) {}

    public function key(): string
    {
        // The kind belongs in the key for the reason the pair axis already
        // learned: two keys of one rule can interact in more than one way, and
        // a key without the kind silently folds those contexts onto one cell.
        // There the collision hid because the colliding cells happened to
        // agree; here it cost 38 cells of population before review found it.
        return 'neighbourhood|' . $this->rule . '|' . $this->nullKey . '|' . $this->neighbour . '|' . $this->pairKind;
    }

    /** A neighbour spelled `class:` is a level BLOCK, not a leaf that carries a value. */
    public function neighbourIsBlock(): bool
    {
        return str_ends_with($this->neighbour, ':');
    }

    public function neighbourBlock(): string
    {
        return rtrim($this->neighbour, ':');
    }
}

final class Neighbourhood
{
    /** @var list<NeighbourhoodRow> */
    public readonly array $rows;

    /**
     * @param array<string, class-string<RuleOptionsInterface>> $optionsClasses producer name => options class
     */
    public function __construct(string $root, array $optionsClasses)
    {
        $lines = file($root . '/' . KeyPairGroups::PATH, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . KeyPairGroups::PATH);
        }

        $rows = [];
        $seen = [];
        $header = false;

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!$header) {
                $header = true;

                continue;
            }

            $cells = array_pad(explode("\t", $line), 8, '');
            $rule = $cells[0];

            // BOTH orientations of every pair. The two keys are not
            // interchangeable here: which of them carries the `~` is the
            // question, and a pair whose two keys both accept `~` is two
            // different cells, not one written twice.
            foreach ([[$cells[3], $cells[4]], [$cells[4], $cells[3]]] as [$nullKey, $neighbour]) {
                if (str_ends_with($nullKey, ':') || !self::acceptsNull($optionsClasses, $rule, $nullKey)) {
                    continue;
                }

                $row = new NeighbourhoodRow($rule, $nullKey, $neighbour, $cells[5]);

                if (isset($seen[$row->key()])) {
                    continue;
                }

                $seen[$row->key()] = true;
                $rows[] = $row;
            }
        }

        $this->rows = $rows;
    }

    /**
     * Whether the declared shape of this key accepts `null` — the `->orNull()`
     * half of "`~` changes the branch this key is read through".
     *
     * Asked of the shape itself rather than of a list of key names: the
     * question "does this key accept `~`" is one the product answers, and a
     * hand-written answer to it is the very claim under review.
     *
     * @param array<string, class-string<RuleOptionsInterface>> $optionsClasses
     */
    private static function acceptsNull(array $optionsClasses, string $rule, string $key): bool
    {
        $shape = self::shapeOf($optionsClasses, $rule, $key);

        return $shape !== null && $shape->matches(null);
    }

    /**
     * The declared shape of one key, through the same two-depth walk the pair
     * probe uses: the rule's own declaration, or — when the head segment names
     * a level slot — that slot's own.
     *
     * @param array<string, class-string<RuleOptionsInterface>> $optionsClasses
     */
    public static function shapeOf(array $optionsClasses, string $rule, string $key): ?RuleOptionShape
    {
        $class = $optionsClasses[$rule] ?? null;

        if ($class === null || !is_a($class, RuleOptionsInterface::class, true)) {
            return null;
        }

        $segments = explode('.', $key);
        $slots = is_a($class, HierarchicalRuleOptionsInterface::class, true) ? $class::levelOptionsClasses() : [];
        $head = ConfigKeySpelling::normalize($segments[0]);

        if (\count($segments) > 1 && isset($slots[$head])) {
            $set = $slots[$head]::acceptedOptionKeys();
            $normalized = ConfigKeySpelling::normalize(implode('.', \array_slice($segments, 1)));
        } else {
            $set = $class::acceptedOptionKeys();
            $normalized = ConfigKeySpelling::normalize($key);
        }

        return $set->knows($normalized) ? $set->shapeOf($normalized) : null;
    }
}
