<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Source-declared aggregation suffixes and independent metric base keys.
 *
 * Both trees are read as text; the reference's product classes are never loaded
 * into the tool process. RenameMaps binds the vocabularies before comparison,
 * requiring complete suffix equality after exactly the declared strategy rows.
 * Independent base keys prevent an expanded spelling from consuming a distinct
 * metric that one tree declares. Collector-owned literals absent from MetricName
 * remain outside this finite source vocabulary and receive no inferred strategy
 * translation.
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
            . ' out of every metric-keys row silently. Identity comparison requires the same suffix vocabulary;'
            . ' declared strategy correspondence must be checked through RenameMaps instead.',
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
