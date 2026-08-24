<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The declared channel-and-level pairs, and the second witness to their static half.
 *
 * Static declarations come from the candidate container; the open
 * `computed.*` / `health.*` family is resolvable only once a case's
 * configuration has resolved, so it is asked per case. The tracked fixture
 * `tests/Analysis/Finding/Fixtures/Channels/declared.txt` is asked the same
 * question independently: two artifacts disagreeing is the cheapest detector we
 * have, so their disagreement is its own failure rather than a tie broken
 * silently.
 *
 * Pairs, not names, and that is what makes the declared side of coverage an
 * oracle rather than a restatement of the claim. A claim is hand-written on
 * purpose — it is a third, independent voice — but a hand-written declaration
 * would leave a pair the product can produce, which fires in no case and is
 * claimed in no case, invisible from both sides at once. So the declared side is
 * *derived*, from two witnesses that agree or fail, and the claim stays
 * hand-written beside them.
 *
 * The runtime half has one witness, exactly as it has for names: the fixture is
 * scoped to the static set by construction, because the `computed.*` vocabulary
 * is open-ended and no fixture line could enumerate it. Its levels come from the
 * case's own resolved configuration, so a computed level and the corpus that
 * fires it move together and pair coverage cannot see one leave — which is why
 * the `lost-level-fixture` control asserts a claim mismatch there and not a
 * coverage shortfall. For a static channel the levels come from product code and
 * the fixtures from the corpus, so the two can part company and the shortfall is
 * the one place that shows.
 */
final class ChannelWitness
{
    private const FIXTURE = 'tests/Analysis/Finding/Fixtures/Channels/declared.txt';

    /** @var array{static: array<string, list<string>>, computed: array<string, list<string>>, levels: list<string>}|null */
    private ?array $tree = null;

    /** @var array<string, list<string>> */
    private array $computed = [];

    public function __construct(private readonly string $treeRoot) {}

    /** @return list<string> */
    public function staticPairs(): array
    {
        return self::pairs(($this->tree ??= $this->probe(null))['static']);
    }

    /** @return list<string> */
    public function computedPairs(CaseDefinition $case): array
    {
        return $this->computed[$case->id] ??= self::pairs($this->probe($case)['computed']);
    }

    /** @return list<string> */
    public function fixturePairs(): array
    {
        $declarations = [];

        foreach (explode("\n", Fs::read($this->treeRoot . '/' . self::FIXTURE)) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $fields = preg_split('/\s+/', $line);

            // A line the fixture's own format forbids: it is read here as a
            // channel with no levels, so the disagreement below names it rather
            // than this parser inventing a level for it.
            if ($fields === false || \count($fields) < 3) {
                $declarations[$fields === false ? $line : $fields[0]] = [];

                continue;
            }

            $declarations[$fields[0]] = array_values(array_filter(
                explode(',', $fields[2]),
                static fn(string $level): bool => $level !== '',
            ));
        }

        return self::pairs($declarations);
    }

    /**
     * The product's own level vocabulary, as the candidate tree spells it.
     *
     * @return list<string>
     */
    public function productLevels(): array
    {
        return ($this->tree ??= $this->probe(null))['levels'];
    }

    /**
     * The two witnesses to the static half, compared.
     *
     * Static because that is the half the fixture covers; a run-time channel has
     * one witness and says so. Static, and pairs: a fixture line whose levels
     * column drifts from the declaration in code is the same defect as a line
     * naming a channel that no longer exists, and until now the gate read that
     * column and threw it away.
     *
     * @param list<string> $fixturePairs
     * @param list<string> $containerPairs
     */
    public static function checkAgreement(GateReport $report, array $fixturePairs, array $containerPairs): void
    {
        sort($fixturePairs);
        sort($containerPairs);

        if ($fixturePairs === $containerPairs) {
            return;
        }

        $report->fail(
            FailureClass::WITNESS_DISAGREEMENT,
            self::FIXTURE,
            'The container and the tracked fixture disagree about the static channel-and-level declarations. Two'
            . ' artifacts disagreeing is the cheapest detector we have, so neither is trusted over the other here.',
            Diff::betweenSets($fixturePairs, $containerPairs, 'fixture', 'container'),
        );
    }

    /**
     * The gate's level vocabulary against the product's own.
     *
     * `SubjectLevel` maps a subject tag onto a level, and the level it produces
     * never reaches a compared artifact: it is compared against a claim written
     * in the same gate-internal spelling, so a `SymbolLevel` case value changing
     * under it would leave every claim matching and the run green. The docblock
     * used to assert the two agreed; this measures it, in the one campaign whose
     * subject is the level vocabulary.
     *
     * @param list<string> $productLevels
     */
    public static function checkLevelVocabulary(GateReport $report, array $productLevels): void
    {
        $gate = SubjectLevel::levels();
        sort($gate);
        sort($productLevels);

        if ($gate === $productLevels) {
            return;
        }

        $report->fail(
            FailureClass::LEVEL_VOCABULARY_DRIFT,
            'scripts/finding-gate/SubjectLevel.php',
            'The levels the gate maps subject tags onto are no longer the product\'s own SymbolLevel values. The'
            . ' gate\'s level never reaches a compared artifact — it is checked against a claim in the same'
            . ' spelling — so a renamed level case would keep every claim matching and every run green.',
            Diff::betweenSets($gate, $productLevels, 'gate', 'product'),
        );
    }

    /**
     * @param array<string, list<string>> $declarations
     *
     * @return list<string>
     */
    private static function pairs(array $declarations): array
    {
        $pairs = [];

        foreach ($declarations as $channel => $levels) {
            foreach ($levels as $level) {
                $pairs[SubjectLevel::claim($channel, $level)] = true;
            }
        }

        $pairs = array_keys($pairs);
        sort($pairs);

        return $pairs;
    }

    /** @return array{static: array<string, list<string>>, computed: array<string, list<string>>, levels: list<string>} */
    private function probe(?CaseDefinition $case): array
    {
        $directory = $case === null ? $this->treeRoot : $case->directory;
        $configuration = $case === null ? 'qmx.yaml' : $case->config;
        $result = Process::run(
            [\PHP_BINARY, __DIR__ . '/probe-channels.php', $this->treeRoot, $directory, $configuration],
            $directory,
        );

        if ($result['exit'] !== 0) {
            throw new GateError(\sprintf("Channel probe failed (exit %d):\n%s", $result['exit'], $result['stderr']));
        }

        $decoded = json_decode($result['stdout'], true);

        if (
            !\is_array($decoded)
            || !\is_array($decoded['static'] ?? null)
            || !\is_array($decoded['computed'] ?? null)
            || !\is_array($decoded['levels'] ?? null)
        ) {
            throw new GateError('Channel probe produced no usable answer: ' . $result['stdout']);
        }

        /** @var array{static: array<string, list<string>>, computed: array<string, list<string>>, levels: list<string>} */
        return [
            'static' => self::declarations($decoded['static'], 'static'),
            'computed' => self::declarations($decoded['computed'], 'computed'),
            'levels' => array_values(array_map(strval(...), $decoded['levels'])),
        ];
    }

    /**
     * @param array<array-key, mixed> $reported
     *
     * @return array<string, list<string>>
     */
    private static function declarations(array $reported, string $half): array
    {
        $declarations = [];

        foreach ($reported as $channel => $levels) {
            // A declaration with no level is refused rather than read as "any
            // level": the product's own type forbids it, so the shape arriving
            // here means the probe answered a different question than the one
            // pair coverage is about to count on.
            if (!\is_array($levels) || $levels === []) {
                throw new GateError(\sprintf(
                    'The channel probe reported the %s channel "%s" with no level. Every declaration names the levels'
                    . ' it reports at, and coverage is counted per channel%slevel pair.',
                    $half,
                    (string) $channel,
                    SubjectLevel::SEPARATOR,
                ));
            }

            $declarations[(string) $channel] = array_values(array_map(strval(...), $levels));
        }

        return $declarations;
    }
}
