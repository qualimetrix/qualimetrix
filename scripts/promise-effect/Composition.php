<?php

declare(strict_types=1);

/**
 * How axis C turns one ledger row into three documents.
 *
 * The ledger says WHO writes and what was promised; it says nothing about the
 * spelling of a write, because a promise about layer order is not a promise
 * about YAML. This file is that missing half, and it is a declaration reader,
 * never a guess: the magnitudes come from `composition-magnitudes.tsv`, the
 * alias flag from the product's own registry, and the two other keys a triple
 * needs — the graduated partner of its slot and the mode key that evicts them
 * both — from `key-pairs.tsv`, which is stage 01's frozen enumeration.
 *
 * `key-pairs.tsv` rather than `RuleThresholdKeyGroupRegistry` on purpose. The
 * registry is product code this round's own cure is entitled to delete (ADR
 * 0055, question two); a stand that read it would stop measuring on the day
 * the cure lands, and would do so silently.
 */

namespace Qualimetrix\PromiseEffect;

/** One layer's write: a key of one rule, and the value one side gives it. */
final readonly class CompositionWrite
{
    public function __construct(
        public string $writer,
        public string $key,
        public string $yamlWrite,
        public string $cliWrite,
    ) {}
}

/**
 * The three documents of one probe, plus what the framework point is asked.
 *
 * `low` and `high` are the sides written ALONE; `both` is every layer at once.
 * A triple's `both` carries three writes and its two sides carry one each,
 * which is the same shape and the reason the probe has one contract.
 *
 * A triple has a THIRD side a pair does not: `middle`, the layer that writes
 * the mode key alone (L2). A pair's dispute is fully described by `low` and
 * `high` — there is nothing else written — so `middle` stays empty there. A
 * triple's is not: `promised_survival=survives` asks which of the layers that
 * touched a slot wrote it LAST, and a slot only `low` and `middle` dispute
 * (the middle layer's own expansion of a key the top layer never rewrites)
 * has no answer without observing `middle` on its own — `low` and `high`
 * alone make it indistinguishable from a slot `low` disputes with nobody.
 *
 * @phpstan-type WriteList list<CompositionWrite>
 */
final readonly class CompositionPlan
{
    /**
     * @param list<CompositionWrite> $low
     * @param list<CompositionWrite> $high
     * @param list<CompositionWrite> $both
     * @param list<string> $pathWitnesses
     * @param list<CompositionWrite> $middle empty for a pair (composition-path,
     *                                       composition-bucket): there is no third layer to probe alone
     */
    public function __construct(
        public string $rule,
        public array $low,
        public array $high,
        public array $both,
        public array $pathWitnesses,
        public string $note,
        public array $middle = [],
    ) {}

    /**
     * Whether every key the lowest layer wrote is written again by a layer
     * above it.
     *
     * This is what tells a lost slot from a won dispute, and it cannot be read
     * off the observations. When both sides write the SAME key, the higher
     * value replacing the lower one is the promise being kept — and its leaf
     * effect is indistinguishable from the lower side's slot vanishing, so a
     * stand that only looked at leaves would call every correct composition of
     * a list-shaped key a `LOST_SIBLING`. Measured on axis C's own framework
     * point, that mistake turned four kept promises into four defects.
     *
     * A triple is the other case: its L3 writes a STRICT SUBSET of the pair,
     * so the slot L1 alone wrote is disputed by nobody and its disappearance
     * is the thing the triples exist to detect.
     */
    public function highRewritesEveryLowKey(): bool
    {
        $rewritten = [];

        foreach ($this->both as $write) {
            if (!\in_array($write, $this->low, true)) {
                $rewritten[$write->key] = true;
            }
        }

        foreach ($this->low as $write) {
            if (!isset($rewritten[$write->key])) {
                return false;
            }
        }

        return true;
    }
}

/** A point the probe could not be taken at, and the reason in the row's own terms. */
final readonly class CompositionRefusal
{
    public function __construct(public string $reason) {}
}

final class CompositionPlanner
{
    /**
     * The point at which the options object answers, and the point at which
     * the three framework keys do. Named here because `expectedKeys()`, the
     * grid and the raw snapshot all have to agree on the spelling.
     */
    public const string POINT_OBJECT = 'optionsObject';

    public const string POINT_FRAMEWORK = 'frameworkKeys';

    /**
     * The framework key the second point is taken on.
     *
     * One of the three, not all of them: they are drained by the same lines of
     * `RuleOptionsFactory::create()` into the same registry, so a second key
     * would repeat the measurement rather than extend it. `suppress_paths` is
     * the one of the three that every door can carry — it accepts a lone
     * string, which is the only thing `--rule-opt` can write.
     */
    private const string FRAMEWORK_KEY = 'suppress_paths';

    /** @param array<string, array{rule: string, option: string}> $aliases flag => what it writes */
    public function __construct(
        private readonly Declarations $declarations,
        private readonly array $aliases,
        private readonly KeyPairGroups $groups,
    ) {}

    /**
     * The plan for one row at one point, or the reason there is none.
     *
     * A refusal here is never a verdict about the product: it says the stand
     * could not put the question, which is the NOT OBSERVABLE this axis owes.
     */
    public function plan(CompositionRow $row, string $rule, string $option, string $point): CompositionPlan|CompositionRefusal
    {
        if ($point === self::POINT_FRAMEWORK) {
            return $this->frameworkPlan($row, $rule);
        }

        return match ($row->kind) {
            'composition-path' => $this->pathPlan($row, $rule, $option),
            'composition-bucket' => $this->bucketPlan($row, $rule),
            'composition-triple' => $this->triplePlan($row, $rule),
            default => new CompositionRefusal('no plan is declared for the row kind "' . $row->kind . '"'),
        };
    }

    /** Two writers, one key, two magnitudes. */
    private function pathPlan(CompositionRow $row, string $rule, string $option): CompositionPlan|CompositionRefusal
    {
        $low = $this->write($row->low, $rule, $option, 'graduated-primary', 'low');
        $high = $this->write($row->high, $rule, $option, 'graduated-primary', 'high');

        if ($low instanceof CompositionRefusal) {
            return $low;
        }

        if ($high instanceof CompositionRefusal) {
            return $high;
        }

        return new CompositionPlan($rule, [$low], [$high], [$low, $high], [], '');
    }

    /**
     * Two writers, two DIFFERENT keys of one level — the only pair where both
     * keys survive into the same CLI bucket, which is why the denominator
     * singles it out.
     */
    private function bucketPlan(CompositionRow $row, string $rule): CompositionPlan|CompositionRefusal
    {
        $lowHalves = explode('@', $row->low, 2);
        $highHalves = explode('@', $row->high, 2);

        if (\count($lowHalves) !== 2 || \count($highHalves) !== 2) {
            return new CompositionRefusal('a composition-bucket row packs `key@writer` in columns 3 and 4, and this one does not');
        }

        $low = $this->write($lowHalves[1], $rule, $lowHalves[0], 'graduated-primary', 'low');
        $high = $this->write($highHalves[1], $rule, $highHalves[0], 'graduated-primary', 'high');

        if ($low instanceof CompositionRefusal) {
            return $low;
        }

        if ($high instanceof CompositionRefusal) {
            return $high;
        }

        return new CompositionPlan($rule, [$low], [$high], [$low, $high], [], '');
    }

    /**
     * Three layers, and the one thing at stake: the slot only the lowest layer
     * writes.
     *
     * L1 writes the graduated pair, L2 switches the mode and evicts it, L3
     * writes the graduated mode back with STRICTLY ONE slot of the pair. The
     * strictness is the experiment: a triple whose L3 writes both slots has
     * nothing to lose and is green by construction (TD3 of the denominator),
     * so it would be a row that cannot fail.
     */
    private function triplePlan(CompositionRow $row, string $rule): CompositionPlan|CompositionRefusal
    {
        $halves = explode('@', $row->low, 2);

        if (\count($halves) !== 2) {
            return new CompositionRefusal('a composition-triple row packs `path@slot` in column 3, and this one does not');
        }

        [$path, $slot] = $halves;
        $slotKey = $path === '' || $path === '(top)' ? $slot : $path . '.' . $slot;
        $partner = $this->groups->partnerOf($rule, $path, $slotKey);
        $mode = $this->groups->modeKeyOf($rule, $path, $slotKey);

        if ($partner === null || $mode === null) {
            return new CompositionRefusal(
                'key-pairs.tsv declares no graduated partner and mode key for ' . $rule . ' at "' . $path . '", so the triple has no slot to lose',
            );
        }

        if (\count($row->layers) !== 3) {
            return new CompositionRefusal('the row names ' . \count($row->layers) . ' layers');
        }

        [$l1, $l2, $l3] = $row->layers;
        $writes = [
            $this->write($l1, $rule, $slotKey, 'graduated-primary', 'low'),
            $this->write($l1, $rule, $partner, 'graduated-sibling', 'low'),
            $this->write($l2, $rule, $mode, 'graduated-primary', 'high'),
            $this->write($l3, $rule, $partner, 'graduated-sibling', 'high'),
        ];

        foreach ($writes as $write) {
            if ($write instanceof CompositionRefusal) {
                return $write;
            }
        }

        /** @var list<CompositionWrite> $writes */
        return new CompositionPlan(
            $rule,
            [$writes[0], $writes[1]],
            [$writes[3]],
            $writes,
            [],
            'L1 writes ' . $slotKey . ' and ' . $partner . ', L2 writes ' . $mode . ', L3 writes ' . $partner . ' alone',
            middle: [$writes[2]],
        );
    }

    /**
     * The same two writers, on a key that never reaches an options object.
     *
     * Only for a row whose promise reaches every path. An `80-alias-restricted`
     * row promises nothing here — the alias door reaches exactly the 80 paths
     * a `#[CliAlias]` names, and no framework key is one of them — so the
     * point is refused rather than measured and called a defect.
     */
    private function frameworkPlan(CompositionRow $row, string $rule): CompositionPlan|CompositionRefusal
    {
        if ($row->kind !== 'composition-path') {
            return new CompositionRefusal('only a composition-path row disputes one path, and only it can dispute a framework key');
        }

        if (!$row->reachesEveryPath()) {
            return new CompositionRefusal(
                'the row promises only over the 80 alias paths, and no framework key is one of them (scope ' . $row->scope . ')',
            );
        }

        $low = $this->write($row->low, $rule, self::FRAMEWORK_KEY, 'framework-pattern', 'low');
        $high = $this->write($row->high, $rule, self::FRAMEWORK_KEY, 'framework-pattern', 'high');

        if ($low instanceof CompositionRefusal) {
            return $low;
        }

        if ($high instanceof CompositionRefusal) {
            return $high;
        }

        return new CompositionPlan(
            $rule,
            [$low],
            [$high],
            [$low, $high],
            [
                $this->declarations->magnitude('framework-witness', 'low')->yamlWrite,
                $this->declarations->magnitude('framework-witness', 'high')->yamlWrite,
            ],
            'observed through the registry predicate, because the factory drains this key before fromArray()',
        );
    }

    private function write(string $writer, string $rule, string $key, string $slot, string $side): CompositionWrite|CompositionRefusal
    {
        if ($writer === 'cli-alias' && $this->aliasFor($rule, $key) === null) {
            return new CompositionRefusal('no `#[CliAlias]` flag writes ' . $rule . '.' . $key);
        }

        $magnitude = $this->declarations->magnitude($slot, $side);

        return new CompositionWrite($writer, $key, $magnitude->yamlWrite, $magnitude->cliWrite);
    }

    /** The flag that writes this option, out of the product's own alias registry. */
    public function aliasFor(string $rule, string $key): ?string
    {
        foreach ($this->aliases as $flag => $target) {
            if ($target['rule'] === $rule && $target['option'] === $key) {
                return $flag;
            }
        }

        return null;
    }
}

/**
 * The graduated pairs and mode keys of `key-pairs.tsv`, read by what the row
 * SAYS about the two keys rather than by their spelling.
 *
 * A spelling reader would have to know that `max_warning` and `warning` are
 * the same role, that `vo_error` is a third one, and it would be wrong the
 * first time a rule spelled a band differently. The table already carries the
 * answer in its `interaction` column, and stage 01 derived that column from
 * the call sites.
 */
final class KeyPairGroups
{
    public const string PATH = 'docs/internal/plans/promise-effect/measurement/key-pairs.tsv';

    private const string COMPOSES = 'compose within one group';

    private const string EXCLUSIVE = 'mutually exclusive within one source';

    /** @var list<array{string, string, string, string}> rule, path, key a, key b — composing pairs */
    private array $composing = [];

    /** @var list<array{string, string, string, string}> rule, path, key a, key b — mode-evicting pairs */
    private array $exclusive = [];

    public function __construct(string $root)
    {
        $lines = file($root . '/' . self::PATH, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . self::PATH);
        }

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
            $row = [$cells[0], $cells[2], $cells[3], $cells[4]];

            if (str_contains($cells[6], self::COMPOSES)) {
                $this->composing[] = $row;
            }

            if (str_contains($cells[6], self::EXCLUSIVE)) {
                $this->exclusive[] = $row;
            }
        }
    }

    /** The other band key of the group this slot belongs to. */
    public function partnerOf(string $rule, string $path, string $key): ?string
    {
        return self::otherSide($this->composing, $rule, $path, $key);
    }

    /**
     * The two band keys of the group at one path, in the table's own order.
     *
     * This is what a LEVEL BLOCK has to be written with to have any effect:
     * `class: {}` is an empty mapping and means nothing, so a neighbour
     * spelled `class:` is written as the pair its own level declares.
     *
     * @return array{string, string}|null
     */
    public function bandPairAt(string $rule, string $path): ?array
    {
        foreach ($this->composing as [$rowRule, $rowPath, $a, $b]) {
            if ($rowRule === $rule && $rowPath === $path) {
                return [$a, $b];
            }
        }

        return null;
    }

    /** The key whose presence evicts the whole band group — `threshold`, however spelled. */
    public function modeKeyOf(string $rule, string $path, string $key): ?string
    {
        return self::otherSide($this->exclusive, $rule, $path, $key);
    }

    /** @param list<array{string, string, string, string}> $rows */
    private static function otherSide(array $rows, string $rule, string $path, string $key): ?string
    {
        foreach ($rows as [$rowRule, $rowPath, $a, $b]) {
            if ($rowRule !== $rule || $rowPath !== $path) {
                continue;
            }

            if ($a === $key) {
                return $b;
            }

            if ($b === $key) {
                return $a;
            }
        }

        return null;
    }
}
