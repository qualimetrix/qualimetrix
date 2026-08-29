<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * What the product's own source says a metric name can be: the closed list of
 * aggregation suffixes, and the base keys it declares.
 *
 * A metric is published bare AND once per aggregation strategy declared for it,
 * spelled `<key>.<strategy>` ({@see \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName::agg()}).
 * A `metric-keys.tsv` row therefore has to reach the suffixed spellings of the
 * key it names, and the suffix has to come from a closed list — an open one
 * would turn a declared rename into a substring rewrite over every longer name
 * that happens to start with the key, which is the one thing the map's
 * whole-name rule exists to refuse. Measured 2026-08-26 over the 14-case
 * corpus: 212 of 295 published spellings are `base.<strategy>`, against 83 base
 * keys, so declaring them one row each is not a list anybody can keep complete.
 *
 * The suffix list is read from BOTH trees and they have to agree, which is not
 * symmetry for its own sake: forward translation is applied to the REFERENCE's
 * artifacts, so a strategy the step removed would stop being expanded while the
 * reference is still publishing it — a rename leaking into an undeclared diff,
 * silently. The comparison lives in {@see ReferenceTree::create()} rather than
 * in the gate's own sequence, so that obtaining a reference tree at all is what
 * performs it: a check the caller has to remember is a check one refactoring
 * removes.
 *
 * The base keys are read for a second check, and what they are is a partial
 * universe rather than the whole one — said here because a check that overstates
 * its reach is worse than one that states it. `MetricName`'s constants are 71 of
 * the 82 published base keys; the other eleven are collector-owned literals
 * (`getterCount`, the three `methodCount*`, …) that no single file declares, and
 * the step that gives them constants is Ш5e3 itself. So the overlap check below
 * covers every key the product names in one place, and cannot see one it does
 * not.
 */
final class MetricVocabulary
{
    /**
     * Where the two vocabularies live, as paths rather than classes.
     *
     * The reference tree is a checkout of an older commit and is never
     * autoloaded into this process — two trees' classes of the same name cannot
     * both be loaded — so both are read as text from each tree's own file.
     */
    private const STRATEGY_PATH = 'src/Analysis/Evidence/Measurement/Contract/AggregationStrategy.php';

    private const METRIC_NAME_PATH = 'src/Analysis/Evidence/Measurement/Contract/MetricName.php';

    /**
     * @param list<string> $suffixes
     * @param list<string> $baseKeys
     */
    private function __construct(
        public readonly array $suffixes,
        public readonly array $baseKeys,
    ) {}

    public static function ofTree(string $treeRoot): self
    {
        return new self(
            self::literals($treeRoot, self::STRATEGY_PATH, '~^\s*case\s+\w+\s*=\s*\'([^\']+)\';~m', 'aggregation suffix'),
            self::literals($treeRoot, self::METRIC_NAME_PATH, '~public const string \w+ = \'([^\']+)\';~', 'metric key'),
        );
    }

    /**
     * A vocabulary stated outright, for the self-test.
     *
     * A shape proved on synthetic rows must not depend on what the product
     * happens to declare today, so the cases that are about the suffix expansion
     * carry their own list.
     *
     * @param list<string> $suffixes
     * @param list<string> $baseKeys
     */
    public static function of(array $suffixes, array $baseKeys = []): self
    {
        return new self($suffixes, $baseKeys);
    }

    /** No vocabulary at all: no suffix is expanded and no key is known. */
    public static function none(): self
    {
        return new self([], []);
    }

    public function assertSuffixesAgreeWith(self $other): void
    {
        if ($this->suffixes === $other->suffixes) {
            return;
        }

        throw new GateError(\sprintf(
            'The two trees do not agree on the aggregation suffixes a metric key may carry: [%s] against [%s].'
            . ' Forward translation runs over the reference\'s artifacts, so a suffix only it publishes would fall'
            . ' out of every metric-keys row silently. A step that means to change this vocabulary renames a'
            . ' published spelling on every aggregated metric at once, and the gate has no shape to declare that'
            . ' in — so it stops here rather than comparing a translation it cannot state.',
            implode(', ', $this->suffixes),
            implode(', ', $other->suffixes),
        ));
    }

    /**
     * @return list<string>
     */
    private static function literals(string $treeRoot, string $path, string $pattern, string $subject): array
    {
        $file = $treeRoot . '/' . $path;

        if (!is_file($file)) {
            throw new GateError(\sprintf(
                'No %s in %s, so the closed list of %s values cannot be read. A metric-keys row is checked and'
                . ' expanded against that list, and doing either against a list nothing produced is a guess.',
                $path,
                $treeRoot,
                $subject,
            ));
        }

        $found = preg_match_all($pattern, Fs::read($file), $matches);

        // `preg_match_all` answers "no match" with 0 and "the pattern did not
        // run" with false, and reading the second as the first is how a broken
        // read becomes an empty vocabulary that expands nothing and refuses
        // nothing.
        if ($found === false || $found === 0) {
            throw new GateError(\sprintf(
                '%s in %s yields no %s values. Either the declaration moved or the read failed; both leave the'
                . ' vocabulary unfounded, and an unfounded vocabulary silently stops checking.',
                $path,
                $treeRoot,
                $subject,
            ));
        }

        $values = array_values(array_unique($matches[1]));
        sort($values);

        return $values;
    }
}
